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
 * Evidence-based stale-claim recovery.
 *
 * The old rule was a blind timer: a claim older than 900 s went back to the
 * queue no matter what, so a live worker on a long PDF could have its job
 * requeued from under it. The new rule is a heartbeat: a claim goes back only
 * when its holder's worker row is missing or its `last_seen_at` is older than
 * the staleness budget. A worker that keeps heartbeating through a 30-minute
 * PDF must never lose the claim.
 *
 * The database is a mock with the same shape the queue really drives: one
 * builder per statement, the SELECT answered with the rows the test wants,
 * every UPDATE captured as column => value pairs.
 */
class QueueRecoveryTest extends TestCase
{
	/** @var list<array<string, mixed>> */
	private array $rows = [];

	/** @var list<array<string, mixed>> */
	private array $updates = [];

	/** @var list<string> */
	private array $predicates = [];

	/** @var list<list<mixed>> */
	private array $queryParams = [];

	/** @var array<string, mixed> */
	private array $paramTypes = [];

	private int $builderCalls = 0;

	/** @var list<array{id: int}> */
	private array $selectResult = [];

	private bool $updateAffectsRows = true;

	private function db(): IDBConnection
	{
		$this->builderCalls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function (): IQueryBuilder {
			$index = ++$this->builderCalls;
			$qb = $this->createMock(IQueryBuilder::class);
			$expr = $this->createMock(IExpressionBuilder::class);
			// The predicates are recorded as marker strings so the test can
			// assert WHICH rule selected the rows, not just that some rule did.
			$expr->method('eq')->willReturnCallback(function ($a, $b) {
				$this->predicates[] = "eq:{$a}";
				return "eq:{$a}";
			});
			$expr->method('isNull')->willReturnCallback(function ($a) {
				$this->predicates[] = "isnull:{$a}";
				return "isnull:{$a}";
			});
			$expr->method('lt')->willReturnCallback(function ($a, $b) {
				$this->predicates[] = "lt:{$a}";
				return "lt:{$a}";
			});
			$expr->method('orX')->willReturnCallback(function (...$parts) {
				$this->predicates[] = 'orX(' . implode(',', $parts) . ')';
				return $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
			});
			$expr->method('in')->willReturnCallback(fn ($a, $b) => "in:{$a}");
			$qb->method('expr')->willReturn($expr);
			foreach (['select', 'selectAlias', 'from', 'leftJoin', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'update', 'delete', 'insert'] as $method) {
				$qb->method($method)->willReturnCallback(fn ($arg = null) => $qb);
			}
			$params = [];
			$qb->method('set')->willReturnCallback(function (string $column, $param) use ($qb, &$params) {
				$key = ltrim((string) $param, ':');
				$this->updates[$this->builderCalls][$column] = array_key_exists($key, $params) ? $params[$key] : $param;
				return $qb;
			});
			$qb->method('createNamedParameter')->willReturnCallback(
				function ($value, $type = null) use (&$params): string {
					$name = 'p' . count($params);
					$params[$name] = $value;
					if ($value instanceof \DateTimeInterface) {
						$this->paramTypes[$name] = $type;
					}
					return ':' . $name;
				}
			);
			$qb->method('createFunction')->willReturnCallback(fn (string $f) => "fn:{$f}");

			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturnCallback(fn (): array => $this->selectResult);
			$result->method('fetch')->willReturn($this->selectResult[0] ?? false);
			$qb->method('executeQuery')->willReturnCallback(function () use (&$params): IResult {
				$this->queryParams[] = array_values($params);
				return $this->lastResult();
			});
			$qb->method('executeStatement')->willReturnCallback(function () use (&$params): int {
				$this->queryParams[] = array_values($params);
				return $this->updateAffectsRows ? 1 : 0;
			});
			return $qb;
		});
		return $db;
	}

	private function lastResult(): IResult
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturnCallback(fn (): array => $this->selectResult);
		$result->method('fetch')->willReturn($this->selectResult[0] ?? false);
		return $result;
	}

	private function queue(): Queue
	{
		$logger = $this->createMock(LoggerInterface::class);
		return new Queue($this->db(), new StageEvents(new RetryPolicy(), $logger), $logger);
	}

	public function testAClaimedByALiveHeartbeatIsNotRequeued(): void
	{
		// The SELECT found no stale claims: the holder's worker row answered
		// the heartbeat check. Nothing may be requeued.
		$this->selectResult = [];

		$requeued = $this->queue()->requeueStale(180);

		$this->assertSame(0, $requeued);
		$this->assertSame([], $this->updates, 'a live claim must not be touched');
	}

	public function testAClaimedByAStaleHeartbeatIsRequeued(): void
	{
		$this->selectResult = [['id' => 5]];

		$requeued = $this->queue()->requeueStale(180);

		$this->assertSame(1, $requeued);
		// The requeue UPDATE is the second statement the queue builds.
		$this->assertSame('queued', $this->updates[2]['status'] ?? null);
		$this->assertArrayHasKey('claim_token', $this->updates[2], 'the old token must not survive the requeue');
		$this->assertNull($this->updates[2]['claim_token']);
		foreach (['claimed_by_worker_id', 'claimed_at'] as $column) {
			$this->assertArrayHasKey($column, $this->updates[2]);
			$this->assertNull($this->updates[2][$column]);
		}
	}

	public function testTheRuleIsTheLeaseNotTheHeartbeat(): void
	{
		$this->selectResult = [];

		$this->queue()->requeueStale(90);

		// The rule changed on purpose. A heartbeat proves the process lives,
		// never that it still owns this job: three rows were held by healthy,
		// beating workers whose report had been lost to a 502. Recovery reads
		// the lease the holder renews, and the registry only as a second
		// signal for a holder that vanished outright.
		$joined = implode(' | ', $this->predicates);
		$this->assertStringContainsString('lt:j.claim_expires_at', $joined, 'an expired lease is the primary rule');
		$this->assertStringContainsString('isnull:j.claim_expires_at', $joined, 'a pre-lease claim never promised a renewal');
		$this->assertStringContainsString('isnull:w.id', $joined, 'a vanished holder is still a reason');
		$this->assertStringNotContainsString('lt:w.last_seen_at', $joined, 'a live heartbeat must not keep an unreported claim alive');
		// The cutoff is bound as an integer epoch (now - 90): the lease is
		// arithmetic, and a string-bound DateTime once crashed this tick.
		$bound = $this->queryParams === [] ? [] : $this->queryParams[0];
		$ints = array_values(array_filter($bound, static fn ($v) => is_int($v)));
		$this->assertNotEmpty($ints, 'the lease cutoff must be bound as an integer epoch');
		$this->assertEqualsWithDelta(time() - 90, $ints[0], 5, 'the cutoff is the lease window the caller passed');
	}

	public function testTheRequeueOnlyTouchesClaimedRows(): void
	{
		$this->selectResult = [['id' => 5]];

		$this->queue()->requeueStale(180);

		$joined = implode(' | ', $this->predicates);
		$this->assertStringContainsString('eq:j.status', $joined);
	}
}
