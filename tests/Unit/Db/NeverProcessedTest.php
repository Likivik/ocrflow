<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use Psr\Log\LoggerInterface;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The panel's numbers must add up to the tree.
 *
 * "Not processed yet 10" was wrong while 30 files had never been touched: a
 * file that was queued AND had a ledger row was subtracted twice — once by
 * leaving it out of the settled count, once by counting every live row.
 * Nothing caught it, because the arithmetic lived behind three queries.
 */
class NeverProcessedTest extends TestCase
{
	private function queue(array $disk, array $ledger, array $live): Queue
	{
		$db = $this->createMock(IDBConnection::class);
		$stage = new StageEvents(new RetryPolicy(), $this->createMock(LoggerInterface::class));

		return new class($db, $stage, $disk, $ledger, $live) extends Queue {
			public function __construct(
				IDBConnection $db,
				StageEvents $stage,
				private array $disk,
				private array $ledger,
				private array $live,
			) {
				parent::__construct($db, $stage);
			}

			protected function diskFileIds(string $uid): array
			{
				return $this->disk;
			}

			protected function ledgerRowsFor(array $fileIds): array
			{
				return $this->ledger;
			}

			protected function liveQueueFor(array $fileIds): array
			{
				return $this->live;
			}

			protected function emptyCount(string $uid): int
			{
				return 0;
			}

			protected function unreadableFormatCount(string $uid): int
			{
				return 0;
			}
		};
	}

	public function testAQueuedFileWithALedgerRowIsNotSubtractedTwice(): void
	{
		// 10 files on disk; 3 settled, 3 queued (all of which already have a
		// ledger row), 4 never touched. The answer must be 4.
		$disk = range(1, 10);
		$ledger = [];
		foreach ([1, 2, 3, 4, 5, 6] as $id) {
			$ledger[$id] = ['status' => 'skipped', 'reason' => 'skip_text_native', 'engine' => null, 'engine_version' => null];
		}
		$live = [4 => 'queued', 5 => 'claimed', 6 => 'queued'];

		$report = $this->queue(array_fill_keys($disk, 1), $ledger, $live)->fileReport('alice');

		$this->assertSame(4, $report['never_processed'], 'files 7-10 have neither a ledger row nor a live row');
		$this->assertSame(['queued' => 2, 'claimed' => 1], $report['queue']);
	}

	public function testAQueuedFileWithNoLedgerRowIsInTheQueueNotNeverProcessed(): void
	{
		$disk = [1, 2, 3];
		$live = [3 => 'queued'];

		$report = $this->queue([1 => 1, 2 => 1, 3 => 1], [], $live)->fileReport('alice');

		$this->assertSame(2, $report['never_processed']);
		$this->assertSame(['queued' => 1, 'claimed' => 0], $report['queue']);
	}

	public function testTheBlocksAddUpToTheTree(): void
	{
		// The whole point of the report: settled + queue + never = total.
		$disk = range(1, 12);
		$ledger = [];
		foreach (range(1, 8) as $id) {
			$ledger[$id] = ['status' => 'skipped', 'reason' => 'skip_ocred_by_ocrflow', 'engine' => 'google', 'engine_version' => '3.16.0'];
		}
		$live = [5 => 'queued', 9 => 'queued'];

		$report = $this->queue(array_fill_keys($disk, 1), $ledger, $live)->fileReport('alice');

		$settled = array_sum($report['by_code']);
		$queued = array_sum($report['queue']);
		$this->assertSame(12, $settled + $queued + $report['never_processed']);
		$this->assertSame(7, $settled, '8 ledger rows minus the one that is queued now');
		$this->assertSame(2, $queued, 'one queued with a ledger row, one without');
		$this->assertSame(3, $report['never_processed'], 'files 10-12');
	}

	public function testAGoneFileIsShownNowhereAndDoesNotBecomeNeverProcessed(): void
	{
		// `error_worker_file_gone` means the subject stopped existing before we
		// could read it. The owner cannot act on it, so it is not an outcome —
		// but it must not fall through into "not processed yet" either, which
		// would ask them to process a file that is not there.
		$disk = [1, 2, 3];
		$ledger = [
			1 => ['status' => 'skipped', 'reason' => 'skip_text_native', 'engine' => null, 'engine_version' => null],
			2 => ['status' => 'error', 'reason' => 'error_worker_file_gone', 'engine' => null, 'engine_version' => null],
		];

		$report = $this->queue([1 => 1, 2 => 1, 3 => 1], $ledger, [])->fileReport('alice');

		$this->assertArrayNotHasKey('error_worker_file_gone', $report['by_code'], 'a gone file has no row');
		$this->assertSame(1, $report['never_processed'], 'file 3 is the only untouched file');
	}

	public function testAStaleJobReferenceStaysVisible(): void
	{
		// Only a vanished file is silent. A result arriving for a job that no
		// longer exists says something about a file that is still there, and
		// hiding it would be a different promise than the one made to the owner.
		$ledger = [
			1 => ['status' => 'error', 'reason' => 'error_worker_job_reference_stale', 'engine' => null, 'engine_version' => null],
		];

		$report = $this->queue([1 => 1], $ledger, [])->fileReport('alice');

		$this->assertSame(1, $report['by_code']['error_worker_job_reference_stale'] ?? 0, 'the row is still reported');
		$this->assertSame(0, $report['gone'], 'and it is not counted as a vanished file');
	}

	public function testNoTextRowsNameTheReaderNotTheVersion(): void
	{
		// The row reads "by google, no text to transcribe": two versions of one
		// engine must not become two rows carrying the same label.
		$ledger = [
			1 => ['status' => 'skipped', 'reason' => 'skip_ocred_by_ocrflow_found_nothing', 'engine' => 'google', 'engine_version' => '3.16.0'],
			2 => ['status' => 'skipped', 'reason' => 'skip_ocred_by_ocrflow_found_nothing', 'engine' => 'google', 'engine_version' => null],
		];

		$report = $this->queue([1 => 1, 2 => 1], $ledger, [])->fileReport('alice');

		$this->assertCount(1, $report['no_text_by_engine'], 'one engine, one row');
		$this->assertSame('google', $report['no_text_by_engine'][0]['engine']);
		$this->assertSame(2, $report['no_text_by_engine'][0]['count']);
	}

	public function testTheArithmeticCloses(): void
	{
		// settled + queue + never + gone must equal the files on disk, or one of
		// the blocks is lying about a file.
		$disk = [1, 2, 3, 4];
		$ledger = [
			1 => ['status' => 'skipped', 'reason' => 'skip_text_native', 'engine' => null, 'engine_version' => null],
			2 => ['status' => 'error', 'reason' => 'error_worker_file_gone', 'engine' => null, 'engine_version' => null],
		];

		$report = $this->queue([1 => 1, 2 => 1, 3 => 1, 4 => 1], $ledger, [4 => 'queued'])->fileReport('alice');

		$this->assertSame(
			$report['total'],
			array_sum($report['by_code']) + $report['queue']['queued'] + $report['queue']['claimed'] + $report['never_processed'] + $report['gone'],
		);
	}
}
