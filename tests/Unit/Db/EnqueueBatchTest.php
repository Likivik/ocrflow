<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Storage\Batch;
use OCA\OcrFlow\Storage\EnqueueService;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\Ledger;
use OCA\OcrFlow\Storage\SettlementService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The batch takes the ordinary door first and forces only the ghost.
 *
 * A file that vanished mid-run and came back still has its terminal job row, so
 * `enqueue()` refuses it for ever. The batch must then force exactly that row —
 * and must not force anything the ordinary door merely rejected for a reason of
 * its own.
 */
class EnqueueBatchTest extends TestCase
{
	public array $enqueueAccepts = [];
	public array $forceResult = ['requeued' => [], 'refused' => []];

	private function service(array $ready, int $readyTotal): EnqueueService
	{
		$batch = $this->createMock(Batch::class);
		$batch->method('neverProcessedFor')->willReturn([
			'ready' => $ready,
			'readyTotal' => $readyTotal,
			'young' => 0,
		]);

		$jobs = $this->createMock(JobStore::class);
		$jobs->method('forceRequeue')->willReturn($this->forceResult);

		return new class(
			$this->createMock(IDBConnection::class),
			$jobs,
			$this->createMock(Ledger::class),
			$this->createMock(SettlementService::class),
			null,
			$batch,
			null,
			$this,
		) extends EnqueueService {
			public function __construct(
				IDBConnection $db,
				JobStore $jobs,
				Ledger $ledger,
				SettlementService $settlement,
				?\OCA\OcrFlow\Service\EnginePolicy $enginePolicy,
				Batch $batch,
				?\Psr\Log\LoggerInterface $logger,
				private EnqueueBatchTest $t,
			) {
				parent::__construct($db, $jobs, $ledger, $settlement, $enginePolicy, $batch, $logger);
			}

			public function enqueue(string $path, ?int $fileId, ?int $size, ?string $etag, ?string $engine = null, bool $fromScan = false): ?int
			{
				return in_array($fileId, $this->t->enqueueAccepts, true) ? 999 : null;
			}
		};
	}

	public function testTheBatchTriesTheOrdinaryDoorFirstAndForcesOnlyTheGhost(): void
	{
		$this->enqueueAccepts = [1, 3];
		$this->forceResult = ['requeued' => [77], 'refused' => []];

		$result = $this->service([
			['fileId' => 1, 'path' => 'files/a.pdf', 'size' => 1000, 'etag' => 'e1', 'jobId' => null],
			['fileId' => 2, 'path' => 'files/b.pdf', 'size' => 1000, 'etag' => 'e2', 'jobId' => null],
			['fileId' => 3, 'path' => 'files/c.pdf', 'size' => 1000, 'etag' => 'e3', 'jobId' => 77],
		], 3)->enqueueNeverProcessed('alice', 10, 'google');

		$this->assertSame(2, $result['queued'], 'file 1 queued, file 3 forced');
		$this->assertSame(1, $result['refused'], 'file 2 was refused by both doors');
		$this->assertSame(1, $result['remaining'], 'the file both doors refused is still unprocessed');
	}
}
