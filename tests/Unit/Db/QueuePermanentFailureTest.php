<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A permanent failure must stay failed — across the retention purge.
 *
 * Retention (#90) deletes terminal `ocrflow_jobs` rows after
 * `jobs_retention_days`. Once the `error` row is gone, the old dedupe guards
 * (`jobForVersion()` finds nothing, `isDoneForVersion()` only knows `done`)
 * would let a file whose bytes can never succeed be re-queued, paid for and
 * failed again — once per retention window, forever.
 *
 * The verdict therefore lives in `ocrflow_files`, the ledger retention never
 * touches: enqueue refuses a file whose ledger row records a PERMANENT reason
 * for the SAME etag. New bytes (a new etag) earn a fresh attempt, because a
 * permanent verdict is about specific bytes, not about the path.
 */
class QueuePermanentFailureTest extends TestCase
{
	/** @var list<array<string, mixed>> */
	private array $jobs = [];

	/** @var list<array<string, mixed>> */
	private array $files = [];

	private int $nextId = 1;

	private int $lastInsertId = 0;

	/**
	 * A small in-memory database over `ocrflow_jobs` and `ocrflow_files`.
	 *
	 * Same shape as the one in QueueEmptySourceTest: the guards only mean
	 * something if the SELECTs can actually find the rows that were written.
	 */
	private function builder(): IQueryBuilder
	{
		$table = '';
		$mode = 'select';
		$conditions = [];
		$values = [];
		$sets = [];
		$params = [];

		$qb = $this->createMock(IQueryBuilder::class);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, mixed $value) use (&$conditions, &$params): string {
				$conditions[$column] = $params[ltrim((string) $value, ':')] ?? null;
				return $column . ' = ?';
			}
		);
		$expr->method('in')->willReturnCallback(
			function (string $column, mixed $value) use (&$conditions, &$params): string {
				$conditions[$column] = $params[ltrim((string) $value, ':')] ?? null;
				return $column . ' IN ?';
			}
		);
		$expr->method('isNull')->willReturnCallback(function (string $column) use (&$conditions): string {
			$conditions[$column] = null;
			return $column . ' IS NULL';
		});
		$qb->method('expr')->willReturn($expr);

		$qb->method('select')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null) use (&$params) {
				$name = 'p' . count($params);
				$params[$name] = $value;
				return ':' . $name;
			}
		);

		$qb->method('from')->willReturnCallback(
			function (string $name) use (&$table, &$qb): IQueryBuilder {
				$table = $name;
				return $qb;
			}
		);
		$qb->method('insert')->willReturnCallback(
			function (string $name) use (&$table, &$mode, &$qb): IQueryBuilder {
				$table = $name;
				$mode = 'insert';
				return $qb;
			}
		);
		$qb->method('update')->willReturnCallback(
			function (string $name) use (&$table, &$mode, &$qb): IQueryBuilder {
				$table = $name;
				$mode = 'update';
				return $qb;
			}
		);
		$qb->method('delete')->willReturnCallback(
			function (string $name) use (&$table, &$mode, &$qb): IQueryBuilder {
				$table = $name;
				$mode = 'delete';
				return $qb;
			}
		);
		$qb->method('values')->willReturnCallback(
			function (array $row) use (&$values, &$params, &$qb): IQueryBuilder {
				foreach ($row as $column => $placeholder) {
					$values[$column] = $params[ltrim((string) $placeholder, ':')] ?? null;
				}
				return $qb;
			}
		);
		$qb->method('set')->willReturnCallback(
			function (string $column, mixed $placeholder) use (&$sets, &$params, &$qb): IQueryBuilder {
				$sets[$column] = $params[ltrim((string) $placeholder, ':')] ?? null;
				return $qb;
			}
		);

		$qb->method('getLastInsertId')->willReturnCallback(function (): int {
			return $this->lastInsertId;
		});

		$matchesRow = function (array $row) use (&$conditions): bool {
			foreach ($conditions as $column => $value) {
				if (!array_key_exists($column, $row)) {
					return false;
				}
				if (is_array($value)) {
					if (!in_array($row[$column], $value, true)) {
						return false;
					}
					continue;
				}
				if ((string) $row[$column] !== (string) $value) {
					return false;
				}
			}
			return true;
		};

		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$table, &$mode, &$values, &$sets, &$conditions, $matchesRow): int {
				if ($mode === 'insert') {
					$row = $values;
					if ($table === 'ocrflow_jobs') {
						$row['id'] = $this->nextId++;
						$row += ['engine' => null, 'claimed_by' => null, 'pages' => null, 'chars' => null,
							'error' => null, 'claimed_at' => null, 'reason' => null, 'retryable' => null];
						$this->jobs[] = $row;
					} elseif ($table === 'ocrflow_files') {
						$this->files[] = $row;
					}
					$this->lastInsertId = (int) ($row['id'] ?? $this->lastInsertId);
					return 1;
				}
				if ($mode === 'update') {
					$touched = 0;
					if ($table === 'ocrflow_jobs') {
						foreach ($this->jobs as $index => $row) {
							if ($matchesRow($row)) {
								$this->jobs[$index] = array_merge($row, $sets);
								$touched++;
							}
						}
					} else {
						foreach ($this->files as $index => $row) {
							if ($matchesRow($row)) {
								$this->files[$index] = array_merge($row, $sets);
								$touched++;
							}
						}
					}
					return $touched;
				}
				if ($mode === 'delete' && $table === 'ocrflow_jobs') {
					$before = count($this->jobs);
					$this->jobs = array_values(array_filter($this->jobs, fn (array $row): bool => !$matchesRow($row)));
					return $before - count($this->jobs);
				}
				return 0;
			}
		);

		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$table, &$conditions, $matchesRow): IResult {
				$rows = match ($table) {
					'ocrflow_jobs' => $this->jobs,
					'ocrflow_files' => $this->files,
					default => [],
				};
				$matches = array_values(array_filter($rows, $matchesRow));
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($matches);
				$result->method('fetch')->willReturn($matches === [] ? false : $matches[0]);
				return $result;
			}
		);

		return $qb;
	}

	private function db(): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
		return $db;
	}

	private function queue(IDBConnection $db): Queue
	{
		return new Queue($db, new StageEvents(new RetryPolicy(), $this->createMock(LoggerInterface::class)));
	}

	/**
	 * Seed the ledger the way a finished permanent failure left it: terminal
	 * status, a vocabulary reason code, the etag of the failed bytes.
	 */
	private function seedLedger(string $status, ?string $reason, string $etag): void
	{
		$this->files[] = [
			'id' => 1,
			'file_id' => 7,
			'path' => '/alice/files/scan.pdf',
			'status' => $status,
			'reason' => $reason,
			'engine' => null,
			'stamp_version' => null,
			'size' => 123,
			'etag' => $etag,
			'updated_at' => 1,
		];
	}

	public function testAPermanentFailureForTheSameEtagIsNotEnqueued(): void
	{
		$this->seedLedger('error', 'broken_pdf', 'e1');

		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e1');

		$this->assertNull($id, 'a permanent verdict on the same bytes must block the enqueue');
		$this->assertSame([], $this->jobs, 'no job row may be written for a blocked enqueue');
	}

	public function testAPermanentFailureWithANewEtagIsEnqueued(): void
	{
		$this->seedLedger('error', 'broken_pdf', 'e1');

		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e2');

		$this->assertNotNull($id, 'new bytes earn a fresh attempt: a permanent verdict is about specific bytes');
	}

	public function testATransientFailureReasonIsEnqueued(): void
	{
		$this->seedLedger('error', 'engine_5xx', 'e1');

		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e1');

		$this->assertNotNull($id, 'a retryable failure keeps today\'s behaviour: the next scan retries it');
	}

	public function testADoneFileIsNotEnqueued(): void
	{
		$this->seedLedger('done', null, 'e1');

		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e1');

		$this->assertNull($id);
	}

	public function testAnUnknownReasonIsEnqueued(): void
	{
		// A code outside the vocabulary (or no code at all) must fail open:
		// an ambiguous diagnosis is not a permanent verdict.
		$this->seedLedger('error', 'made_up_code', 'e1');

		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e1');

		$this->assertNotNull($id, 'an unknown reason must not block the enqueue');
	}

	public function testAMissingReasonIsEnqueued(): void
	{
		$this->seedLedger('error', null, 'e1');

		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e1');

		$this->assertNotNull($id, 'no recorded reason means no verdict, so the file is enqueued');
	}

	public function testAMissingLedgerRowIsEnqueued(): void
	{
		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e1');

		$this->assertNotNull($id, 'a file with no ledger row has no verdict and must be enqueued');
	}

	public function testTheEscapeHatchClearsTheBlock(): void
	{
		$this->seedLedger('error', 'broken_pdf', 'e1');
		// A terminal job row that retention has not purged yet: it must go too,
		// or jobForVersion() would refuse the enqueue on its own.
		$this->jobs[] = [
			'id' => 9, 'path' => '/alice/files/scan.pdf', 'file_id' => 7, 'status' => 'error',
			'etag' => 'e1', 'size' => 123, 'engine' => null, 'claimed_by' => null, 'pages' => null,
			'chars' => null, 'error' => 'x', 'claimed_at' => null, 'reason' => 'x', 'retryable' => false,
			'finished_at' => 1, 'reason_code' => 'broken_pdf', 'attempts' => 0, 'created_at' => 1,
		];

		$queue = $this->queue($this->db());
		$queue->deleteTerminalForFile(7);
		$queue->clearPermanentVerdict(7);

		$id = $queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1');

		$this->assertNotNull($id, 'the operator override must let the file be queued again');
		$this->assertCount(1, $this->jobs, 'the new job replaces the deleted terminal row');
	}

	/**
	 * The verdict must survive the purge, and it can only do that if the
	 * ledger's `reason` carries the vocabulary CODE, not the worker's free
	 * text: free text cannot be matched against the permanent set.
	 */
	public function testFinishRecordsTheReasonCodeInTheLedger(): void
	{
		$this->jobs[] = [
			'id' => 9, 'path' => '/alice/files/scan.pdf', 'file_id' => 7, 'status' => 'claimed',
			'etag' => 'e1', 'size' => 123, 'engine' => 'tesseract', 'claim_token' => 'w', 'pages' => null,
			'chars' => null, 'error' => null, 'claimed_at' => 1, 'reason' => null, 'retryable' => null,
			'finished_at' => null, 'reason_code' => null, 'attempts' => 0, 'created_at' => 1,
		];

		$queue = $this->queue($this->db());
		$updated = $queue->finish(9, [
			'status' => 'error',
			'reason' => 'the pdf is broken',
			'reason_code' => 'broken_pdf',
			'retryable' => false,
			'error' => 'x',
		], 'w');

		$this->assertTrue($updated);
		$this->assertCount(1, $this->files);
		$this->assertSame('error', $this->files[0]['status']);
		$this->assertSame('error_file_broken', $this->files[0]['reason'], 'the ledger reason must be the code, so the verdict is matchable — and written in the new vocabulary, even when the worker still said the old name');
	}

	public function testAnEmptySourceWritesItsVerdictToTheLedger(): void
	{
		$this->queue($this->db())->enqueue('/alice/files/empty.pdf', 7, 0, 'e1');

		$this->assertCount(1, $this->files, 'the zero-byte verdict must be recorded where the purge cannot reach it');
		$this->assertSame('skipped', $this->files[0]['status']);
		$this->assertSame('error_source_file_zero_bytes', $this->files[0]['reason']);
	}
}
