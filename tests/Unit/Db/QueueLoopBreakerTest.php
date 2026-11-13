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
 * [unit] The per-file loop breaker and the zero-page decision row.
 *
 * Catches: a scan that re-queues the same bytes for the same file once its
 * job rows are purged — an unbounded re-OCR loop. The count
 * (`times_enqueued`, migration 0.1.7) is only written on scan-initiated
 * enqueues; past the first one the file is forced to `needs_fix` with
 * `error_queued_twice_for_same_bytes`. The operator override
 * (`Attention::retryFile()`) resets the counter and stays exempt, so a
 * requeue always works exactly once. The refusal's decision row must
 * report zero engine pages: a refusal spends nothing, and a skip path
 * that still pays the provider is the failure this catches.
 *
 * The real-SQL choreography runs in the Nextcloud integration job
 * (tests/integration/app-smoke.sh); this harness cannot run a database.
 */
class QueueLoopBreakerTest extends TestCase
{
	/** @var list<array<string, mixed>> */
	private array $jobs = [];

	/** @var list<array<string, mixed>> */
	private array $files = [];

	/** @var list<array<string, mixed>> */
	private array $stageRows = [];

	private int $nextId = 1;

	private int $lastInsertId = 0;

	/**
	 * A small in-memory database over the three tables the breaker touches.
	 * Same shape as the one in QueuePermanentFailureTest, plus
	 * `ocrflow_stage_events` so the refusal's decision row is observable.
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
						$row += ['engine' => null, 'claim_token' => null, 'claimed_by' => null,
							'pages' => null, 'chars' => null, 'error' => null, 'claimed_at' => null,
							'reason' => null, 'retryable' => null, 'reason_code' => null];
						$this->jobs[] = $row;
					} elseif ($table === 'ocrflow_files') {
						$this->files[] = $row;
					} elseif ($table === 'ocrflow_stage_events') {
						$row += ['id' => count($this->stageRows) + 1];
						$this->stageRows[] = $row;
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
					'ocrflow_stage_events' => $this->stageRows,
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

	/** Retention's work: every job row gone, the ledger untouched. */
	private function purgeJobs(): void
	{
		$this->jobs = [];
	}

	public function testTheSecondScanEnqueueForIdenticalBytesIsRefusedWithNeedsFix(): void
	{
		$db = $this->db();
		$queue = $this->queue($db);

		$first = $queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract', fromScan: true);
		$this->assertNotNull($first, 'the first scan enqueue must go through');

		$this->purgeJobs();

		$second = $queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract', fromScan: true);
		$this->assertNull($second, 'a second scan enqueue for the same file_id+etag must be refused');

		// The refusal is a needs_fix worklist row, not silence.
		$this->assertCount(1, $this->jobs);
		$this->assertSame('error', $this->jobs[0]['status']);
		$this->assertSame('error_queued_twice_for_same_bytes', $this->jobs[0]['reason_code']);
		$this->assertFalse((bool) $this->jobs[0]['retryable'], 'the loop breaker is never retried automatically');
		$this->assertSame('error', $this->files[0]['status'], 'the ledger carries the verdict the purge cannot reach');
		$this->assertSame('error_queued_twice_for_same_bytes', $this->files[0]['reason']);
	}

	public function testTheRefusalDecisionReportsZeroEnginePages(): void
	{
		$db = $this->db();
		$queue = $this->queue($db);
		$queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract', fromScan: true);
		$this->purgeJobs();
		$queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract', fromScan: true);

		$this->assertCount(1, $this->stageRows, 'the refusal must leave a decision row');
		$this->assertSame('intake', $this->stageRows[0]['stage']);
		$this->assertSame('error_queued_twice_for_same_bytes', $this->stageRows[0]['reason_code']);
		$this->assertSame(0, $this->stageRows[0]['cost_pages'], 'a skip spends no engine pages — a refusal must not look like billed work');
	}

	public function testAnUploadEventEnqueueIsExemptFromTheBreaker(): void
	{
		$db = $this->db();
		$queue = $this->queue($db);
		$queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract', fromScan: true);
		$this->purgeJobs();

		// Not scan-initiated: a genuinely new user action, never held against
		// the file, and never counted either.
		$id = $queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract');

		$this->assertNotNull($id, 'only scan-initiated enqueues feed the breaker');
		$this->assertSame(1, (int) $this->files[0]['times_enqueued'], 'the upload-event enqueue must not change the count');
	}

	public function testNewBytesResetTheBudget(): void
	{
		$db = $this->db();
		$queue = $this->queue($db);
		$queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract', fromScan: true);
		$this->purgeJobs();

		// The user edited the file: different bytes are different work.
		$id = $queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e2', 'tesseract', fromScan: true);
		$this->assertNotNull($id);
		$this->assertSame(1, (int) $this->files[0]['times_enqueued'], 'a new etag restarts the count at one, it does not stack');
	}

	public function testTheOperatorRequeueResetsTheCounterAndWorksExactlyOnce(): void
	{
		$db = $this->db();
		$queue = $this->queue($db);

		$queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract', fromScan: true);
		$this->purgeJobs();
		$this->assertNull($queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract', fromScan: true), 'refused before the override');

		// What Attention::retryFile() does, in order.
		$queue->deleteTerminalForFile(7);
		$queue->clearPermanentVerdict(7);
		$queue->resetTimesEnqueued(7);
		$this->assertSame(0, (int) $this->files[0]['times_enqueued'], 'the override must reset the counter');

		$again = $queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract', fromScan: true);
		$this->assertNotNull($again, 'a requeue buys exactly one fresh scan enqueue');

		$this->purgeJobs();
		$onceMore = $queue->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'tesseract', fromScan: true);
		$this->assertNull($onceMore, 'and only one — the breaker is armed again');
	}
}
