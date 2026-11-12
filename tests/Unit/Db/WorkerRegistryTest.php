<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Db\WorkerRegistry;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The worker registry: registration, heartbeat, and the prune that keeps the
 * table from outliving the workers it describes.
 *
 * A worker registers once at startup (its container name and its own version),
 * then heartbeats every interval. A row whose `last_seen_at` falls behind is
 * pruned by the same upkeep job that requeues stale claims — no new scheduler.
 */
class WorkerRegistryTest extends TestCase
{
	/** @var list<array<string, mixed>> */
	private array $inserted = [];

	/** @var list<array<string, mixed>> */
	private array $updated = [];

	/** @var list<array<string, mixed>> */
	private array $deleted = [];

	/** @var list<mixed> */
	private array $namedParams = [];

	private int $builderCalls = 0;

	private int $statementResult = 1;

	private int $insertId = 11;

	private IDBConnection&MockObject $db;

	protected function setUp(): void
	{
		$this->builderCalls = 0;
		$this->inserted = [];
		$this->updated = [];
		$this->deleted = [];

		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(function (): IQueryBuilder {
			$index = ++$this->builderCalls;
			$qb = $this->createMock(IQueryBuilder::class);
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('eq')->willReturnCallback(fn ($a, $b) => "eq:{$a}");
			$expr->method('lt')->willReturnCallback(fn ($a, $b) => "lt:{$a}");
			$qb->method('expr')->willReturn($expr);
			foreach (['select', 'from', 'where', 'andWhere', 'update', 'delete', 'insert', 'set'] as $method) {
				$qb->method($method)->willReturnSelf();
			}
			$params = [];
			$qb->method('createNamedParameter')->willReturnCallback(
				function ($value, $type = null) use (&$params): string {
					$this->namedParams[] = $value;
					$name = 'p' . count($params);
					$params[$name] = $value;
					return ':' . $name;
				}
			);
			$qb->method('values')->willReturnCallback(function (array $values) use ($qb, &$params): IQueryBuilder {
				$row = [];
				foreach ($values as $column => $placeholder) {
					$row[$column] = $params[ltrim((string) $placeholder, ':')] ?? null;
				}
				$this->inserted[] = $row;
				return $qb;
			});
			// `set` pairs land per statement; tag them by the builder index.
			$qb->method('set')->willReturnCallback(function (string $column, $param) use ($qb, $index, &$params): IQueryBuilder {
				$this->updated[$index][$column] = $params[ltrim((string) $param, ':')] ?? null;
				return $qb;
			});
			$qb->method('getLastInsertId')->willReturnCallback(fn (): int => $this->insertId);
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturn(false);
			$qb->method('executeQuery')->willReturn($result);
			$qb->method('executeStatement')->willReturnCallback(function () use ($index): int {
				// The delete is the statement whose table we recorded.
				return $this->statementResult;
			});
			$qb->method('delete')->willReturnCallback(function (string $table) use ($qb): IQueryBuilder {
				$this->deleted[] = ['table' => $table];
				return $qb;
			});
			return $qb;
		});
	}

	public function testRegisterWritesNameVersionAndBothTimestamps(): void
	{
		$registry = new WorkerRegistry($this->db);

		$id = $registry->register('worker-a', '0.4.0');

		$this->assertSame(11, $id);
		$this->assertCount(1, $this->inserted);
		$row = $this->inserted[0];
		$this->assertSame('worker-a', $row['name']);
		$this->assertSame('0.4.0', $row['version']);
		$this->assertInstanceOf(\DateTimeInterface::class, $row['started_at']);
		$this->assertInstanceOf(\DateTimeInterface::class, $row['last_seen_at']);
	}

	public function testHeartbeatRefreshesLastSeenOnly(): void
	{
		$registry = new WorkerRegistry($this->db);

		$this->assertTrue($registry->heartbeat(11));

		$this->assertArrayHasKey(1, $this->updated);
		$this->assertArrayHasKey('last_seen_at', $this->updated[1]);
		$this->assertInstanceOf(\DateTimeInterface::class, $this->updated[1]['last_seen_at']);
		$this->assertArrayNotHasKey('started_at', $this->updated[1]);
		// The heartbeat targets the caller's own row.
		$this->assertContains(11, $this->namedParams);
	}

	public function testHeartbeatForAnUnknownWorkerAnswersFalse(): void
	{
		$this->statementResult = 0;
		$registry = new WorkerRegistry($this->db);

		$this->assertFalse($registry->heartbeat(999));
	}

	public function testPruneDeletesRowsNotSeenWithinTheBudget(): void
	{
		$registry = new WorkerRegistry($this->db);

		$pruned = $registry->pruneStale(180);

		$this->assertSame(1, $pruned);
		$this->assertSame('ocrflow_workers', $this->deleted[0]['table'] ?? null);
		// The cutoff is compared against last_seen_at, with a real datetime.
		$cutoffs = array_values(array_filter($this->namedParams, fn ($p) => $p instanceof \DateTimeInterface));
		$this->assertNotEmpty($cutoffs, 'prune must pass a datetime cutoff');
	}
}
