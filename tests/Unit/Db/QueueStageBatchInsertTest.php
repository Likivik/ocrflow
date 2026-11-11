<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The stage rows a worker reports are written as ONE multi-row INSERT per
 * chunk, not one round-trip per row. At flood scale (20k jobs x up to 7
 * stages) the per-row loop was ~140k INSERT round-trips a night; one
 * statement per job is ~20k.
 *
 * Pinned here:
 *
 * 1. N stage rows produce exactly ONE INSERT statement with N value rows.
 * 2. An empty stage list writes nothing at all.
 * 3. A single row still works (one statement, one value row).
 * 4. The chunk boundary: rows beyond the documented chunk size split into
 *    one statement per chunk, never one unbounded statement.
 * 5. A row that fails inside a chunk must not lose the others: the batch
 *    falls back to one statement per row and skips only the row that
 *    fails on its own.
 */
class QueueStageBatchInsertTest extends TestCase
{
	/** @var list<array{sql: string, params: array, types: array}> */
	private array $statements = [];

	/** @var list<array<string, mixed>> */
	private array $rows = [];

	/** @var list<string> */
	private array $tables = [];

	/** Every raw INSERT fails when true: the conflict-path harness. */
	private bool $failBatchStatements = false;

	/** Per-row fallback inserts whose duration_ms equals this fail alone. */
	private ?int $failingDuration = null;

	/** @var list<array<string, mixed>> */
	private array $warnings = [];

	private ?array $jobRow = null;

	private function db(): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->qb());
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = [], array $types = []) {
				if ($this->failBatchStatements) {
					throw new DbException('batch failed');
				}
				$this->statements[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
				return 1;
			}
		);
		return $db;
	}

	private function qb(): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1=1');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('insert')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$this->tables[] = $table;
				return $qb;
			}
		);

		// Per-builder placeholder map: named parameters belong to the
		// builder that created them and are resolved on `values()`.
		$params = [];
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null) use (&$params): string {
				$name = 'p' . count($params);
				$params[$name] = $value;
				return ':' . $name;
			}
		);
		$qb->method('values')->willReturnCallback(
			function (array $values) use ($qb, &$params): IQueryBuilder {
				$row = [];
				foreach ($values as $column => $placeholder) {
					$row[$column] = $params[ltrim((string) $placeholder, ':')] ?? null;
				}
				$this->rows[] = $row;
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () {
				// The per-row fallback: a row whose duration is the
				// poisoned one fails on its own statement, exactly one
				// row, and the rest of the chunk survives.
				$last = $this->rows[count($this->rows) - 1] ?? null;
				if ($this->failingDuration !== null && $last !== null && $last['duration_ms'] === $this->failingDuration) {
					// The statement failed: the row it carried is not
					// in the table, so it must not count as written.
					array_pop($this->rows);
					throw new DbException('row failed');
				}
				return 1;
			}
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($this->jobRow !== null ? [$this->jobRow] : []);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jobRow(): array
	{
		return [
			'id' => 7,
			'file_id' => null,
			'path' => '/u/files/a.pdf',
			'status' => 'claimed',
			'reason' => null,
			'reason_code' => null,
			'retryable' => null,
			'engine' => 'google',
			'claimed_by' => 'worker-1',
			'attempts' => 1,
			'size' => 100,
			'etag' => 'e1',
			'pages' => null,
			'chars' => null,
			'error' => null,
			'created_at' => 1,
			'claimed_at' => 2,
			'finished_at' => null,
		];
	}

	private function queue(IDBConnection $db, LoggerInterface $logger): Queue
	{
		return new Queue($db, new StageEvents(new RetryPolicy(), $logger), $logger);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function events(int $n): array
	{
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$out[] = ['stage' => 'recognize', 'reason_code' => null, 'duration_ms' => $i, 'cost_pages' => 1];
		}
		return $out;
	}

	/**
	 * The raw INSERT statements that reached the connection, in order.
	 *
	 * @return list<string>
	 */
	private function insertSql(): array
	{
		return array_map(
			static fn (array $s): string => $s['sql'],
			array_values(array_filter($this->statements, static fn (array $s): bool => str_starts_with($s['sql'], 'INSERT INTO')))
		);
	}

	public function testManyStageRowsAreOneInsertStatement(): void
	{
		$this->jobRow = $this->jobRow();
		$logger = $this->createMock(LoggerInterface::class);

		$applied = $this->queue($this->db(), $logger)->finish(7, [
			'status' => 'done',
			'stage_events' => $this->events(6),
		], 'worker-1');

		$this->assertTrue($applied);
		$sql = $this->insertSql();
		$this->assertCount(1, $sql, 'six stage rows must be one INSERT statement, got ' . count($sql));
		$this->assertSame(6, substr_count($sql[0], '(?, ?, ?, ?, ?, ?)'), 'the statement must carry six value rows');
		$this->assertSame(
			'INSERT INTO *PREFIX*ocrflow_stage_events (job_id, stage, reason_code, duration_ms, cost_pages, created_at) VALUES '
			. implode(', ', array_fill(0, 6, '(?, ?, ?, ?, ?, ?)')),
			$sql[0],
		);

		// created_at reaches the driver as DateTime objects, one per row.
		$datetimes = array_filter($this->statements[0]['params'], static fn ($p): bool => $p instanceof \DateTimeInterface);
		$this->assertCount(6, $datetimes);
	}

	public function testAnEmptyStageListWritesNothing(): void
	{
		$this->jobRow = $this->jobRow();
		$logger = $this->createMock(LoggerInterface::class);

		$applied = $this->queue($this->db(), $logger)->finish(7, [
			'status' => 'done',
			'stage_events' => [],
		], 'worker-1');

		$this->assertTrue($applied);
		$this->assertSame([], $this->insertSql());
		$this->assertSame([], $this->rows);
		$this->assertNotContains('ocrflow_stage_events', $this->tables);
	}

	public function testASingleStageRowIsOneStatement(): void
	{
		$this->jobRow = $this->jobRow();
		$logger = $this->createMock(LoggerInterface::class);

		$applied = $this->queue($this->db(), $logger)->finish(7, [
			'status' => 'done',
			'stage_events' => [
				['stage' => 'recognize', 'reason_code' => null, 'duration_ms' => 5, 'cost_pages' => 2],
			],
		], 'worker-1');

		$this->assertTrue($applied);
		$sql = $this->insertSql();
		$this->assertCount(1, $sql);
		$this->assertSame(1, substr_count($sql[0], '(?, ?, ?, ?, ?, ?)'));
		$statement = $this->statements[0];
		$this->assertSame(7, $statement['params'][0]);
		$this->assertSame('recognize', $statement['params'][1]);
		$this->assertNull($statement['params'][2]);
		$this->assertSame(5, $statement['params'][3]);
		$this->assertSame(2, $statement['params'][4]);
		$this->assertInstanceOf(\DateTimeInterface::class, $statement['params'][5]);
	}

	public function testRowsBeyondTheChunkSizeSplitIntoTwoStatements(): void
	{
		// 501 rows against a chunk size of 500: two statements, 500 + 1
		// value rows — never one unbounded statement.
		$rows = [];
		for ($i = 0; $i < 501; $i++) {
			$rows[] = ['stage' => 'recognize', 'reason_code' => null, 'duration_ms' => $i, 'cost_pages' => null];
		}

		$queue = $this->queue($this->db(), $this->createMock(LoggerInterface::class));
		$method = new \ReflectionMethod(Queue::class, 'insertStageRows');
		$method->setAccessible(true);
		$method->invoke($queue, 7, $rows);

		$sql = $this->insertSql();
		$this->assertCount(2, $sql, '501 rows at a chunk size of 500 must be two statements');
		$this->assertSame(500, substr_count($sql[0], '(?, ?, ?, ?, ?, ?)'));
		$this->assertSame(1, substr_count($sql[1], '(?, ?, ?, ?, ?, ?)'));
	}

	public function testAConflictingRowInsideAChunkDoesNotLoseTheOthers(): void
	{
		// The batch statement fails (a constraint a future schema adds, or
		// a transient error). The rows that can be written on their own
		// must still be written: only the row that fails on its own is
		// lost, with a warning — one bad row must not cost the chunk.
		$this->jobRow = $this->jobRow();
		$this->failBatchStatements = true;
		$this->failingDuration = 2;

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');

		$applied = $this->queue($this->db(), $logger)->finish(7, [
			'status' => 'done',
			'stage_events' => [
				['stage' => 'recognize', 'reason_code' => null, 'duration_ms' => 1],
				['stage' => 'deliver', 'reason_code' => null, 'duration_ms' => 2],
				['stage' => 'compose', 'reason_code' => null, 'duration_ms' => 3],
			],
		], 'worker-1');

		$this->assertTrue($applied);
		// The good rows landed through the per-row fallback; the poisoned
		// one is absent.
		$stages = array_map(static fn (array $r): string => (string) $r['stage'], $this->rows);
		$this->assertSame(['recognize', 'compose'], $stages);
	}
}
