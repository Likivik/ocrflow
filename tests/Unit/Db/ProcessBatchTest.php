<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCA\OcrFlow\Storage\Batch;
use OCA\OcrFlow\Storage\DiskFiles;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\Ledger;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] Who the "process them" button may touch, and who it may not.
 *
 * The candidates are the panel's own definition of unprocessed, so this test
 * pins the three things that definition must keep apart: a file nobody has
 * touched (queue it), a file that vanished mid-run and came back (queue it, and
 * force is the only door), and a file whose result merely expired (leave it —
 * that is what Retry is for).
 *
 * The selector is now its own class, so the test mocks its three readers
 * instead of subclassing the class under test.
 */
class ProcessBatchTest extends TestCase
{
	public array $disk = [];
	public array $ledger = [];
	public array $live = [];
	public array $rows = [];
	public array $jobIds = [];
	public array $enqueueAccepts = [];
	public array $forceResult = ['requeued' => [], 'refused' => []];
	public int $minAge = 120;

	private function batch(): Batch
	{
		$disk = $this->createMock(DiskFiles::class);
		$disk->method('ids')->willReturn($this->disk);
		$disk->method('rows')->willReturnCallback(
			fn (array $fileIds): array => array_values(array_filter(
				$this->rows,
				static fn (array $r): bool => in_array($r['fileId'], $fileIds, true),
			)),
		);

		$ledger = $this->createMock(Ledger::class);
		$ledger->method('ledgerRowsFor')->willReturn($this->ledger);

		$jobs = $this->createMock(JobStore::class);
		$jobs->method('liveQueueFor')->willReturn($this->live);
		$jobs->method('terminalJobIdsFor')->willReturnCallback(
			fn (array $fileIds): array => array_intersect_key($this->jobIds, array_flip($fileIds)),
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn((string) $this->minAge);

		return new Batch($disk, $ledger, $jobs, $config);
	}

	private function queue(): Queue
	{
		$db = $this->createMock(IDBConnection::class);
		$stage = new StageEvents(new RetryPolicy(), $this->createMock(LoggerInterface::class));

		return new class($db, $stage, $this) extends Queue {
			public function __construct(IDBConnection $db, StageEvents $stage, private ProcessBatchTest $t)
			{
				parent::__construct($db, $stage);
			}

			protected function batch(): Batch
			{
				return $this->t->batchForSubclass();
			}

			public function enqueue(string $path, ?int $fileId, ?int $size, ?string $etag, ?string $engine = null, bool $fromScan = false): ?int
			{
				return in_array($fileId, $this->t->enqueueAccepts, true) ? 999 : null;
			}

			public function forceRequeue(array $ids): array
			{
				return $this->t->forceResult;
			}
		};
	}

	public function batchForSubclass(): Batch
	{
		return $this->batch();
	}

	private function row(int $fileId, string $path, int $mtime = 0): array
	{
		return ['fileId' => $fileId, 'path' => $path, 'size' => 1000, 'etag' => 'e' . $fileId, 'mtime' => $mtime === 0 ? time() - 3600 : $mtime];
	}

	public function testFilesNobodyTouchedAreCandidates(): void
	{
		$this->disk = [1 => 1, 2 => 1];
		$this->rows = [$this->row(1, 'files/b.pdf'), $this->row(2, 'files/a.pdf')];

		$result = $this->batch()->neverProcessedFor('alice', 10);

		$this->assertSame(2, $result['readyTotal']);
		$this->assertSame(['files/a.pdf', 'files/b.pdf'], array_column($result['ready'], 'path'), 'ordered by path, so repeat clicks walk folders predictably');
		$this->assertNull($result['ready'][0]['jobId'], 'a file with no job row has no job id to force');
	}

	public function testAGhostIsACandidateAndCarriesTheJobRowToForce(): void
	{
		$this->disk = [1 => 1];
		$this->ledger = [1 => ['status' => 'error', 'reason' => 'error_worker_file_gone', 'engine' => null, 'engine_version' => null]];
		$this->rows = [$this->row(1, 'files/a.pdf')];
		$this->jobIds = [1 => 91];

		$result = $this->batch()->neverProcessedFor('alice', 10);

		$this->assertSame(1, $result['readyTotal']);
		$this->assertSame(91, $result['ready'][0]['jobId']);
	}

	public function testASettledFileIsNotACandidateEvenWhenItsResultIsGone(): void
	{
		$this->disk = [1 => 1, 2 => 1];
		$this->ledger = [
			1 => ['status' => 'skipped', 'reason' => 'skip_ocred_by_ocrflow', 'engine' => 'google', 'engine_version' => '3.16.0'],
			2 => ['status' => 'error', 'reason' => 'error_worker_job_reference_stale', 'engine' => null, 'engine_version' => null],
		];
		$this->rows = [$this->row(1, 'files/a.pdf'), $this->row(2, 'files/b.pdf')];

		$result = $this->batch()->neverProcessedFor('alice', 10);

		$this->assertSame(0, $result['readyTotal'], 'a settled file is Retry material, not a batch candidate');
	}

	public function testALiveRowIsNeverTouched(): void
	{
		$this->disk = [1 => 1, 2 => 1];
		$this->live = [2 => 'queued'];
		$this->rows = [$this->row(1, 'files/a.pdf'), $this->row(2, 'files/b.pdf')];

		$result = $this->batch()->neverProcessedFor('alice', 10);

		$this->assertSame(1, $result['readyTotal']);
		$this->assertSame(1, $result['ready'][0]['fileId']);
	}

	public function testAFileStillBeingWrittenIsCountedNotQueued(): void
	{
		$this->disk = [1 => 1, 2 => 1];
		$this->rows = [$this->row(1, 'files/a.pdf'), $this->row(2, 'files/b.pdf', time() - 5)];

		$result = $this->batch()->neverProcessedFor('alice', 10);

		$this->assertSame(1, $result['readyTotal']);
		$this->assertSame(1, $result['young'], 'the young file is reported, not silently dropped');
	}

	public function testTheLimitIsHonouredButTheTotalIsNot(): void
	{
		$this->disk = array_fill_keys([1, 2, 3, 4, 5], 1);
		$this->rows = array_map(fn (int $i): array => $this->row($i, sprintf('files/%d.pdf', $i)), [1, 2, 3, 4, 5]);

		$result = $this->batch()->neverProcessedFor('alice', 2);

		$this->assertCount(2, $result['ready'], 'two are queued');
		$this->assertSame(5, $result['readyTotal'], 'five were available, so three are left over');
	}

	public function testTheBatchTriesTheOrdinaryDoorFirstAndForcesOnlyTheGhost(): void
	{
		$this->disk = [1 => 1, 2 => 1, 3 => 1];
		$this->ledger = [3 => ['status' => 'error', 'reason' => 'error_worker_file_gone', 'engine' => null, 'engine_version' => null]];
		$this->rows = [$this->row(1, 'files/a.pdf'), $this->row(2, 'files/b.pdf'), $this->row(3, 'files/c.pdf')];
		$this->jobIds = [3 => 77];
		// File 2 is refused by enqueue and has no job row: genuinely refused.
		$this->enqueueAccepts = [1, 3];
		$this->forceResult = ['requeued' => [77], 'refused' => []];

		$result = $this->queue()->enqueueNeverProcessed('alice', 10, 'google');

		$this->assertSame(2, $result['queued'], 'file 1 queued, file 3 forced');
		$this->assertSame(1, $result['refused'], 'file 2 was refused by both doors');
		$this->assertSame(1, $result['remaining'], 'the file both doors refused is still unprocessed');
	}
}
