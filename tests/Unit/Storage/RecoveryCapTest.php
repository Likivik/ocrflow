<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Storage;

use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\SettlementService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The recovery ceiling: `MAX_ATTEMPTS` bounds graceful failures, and
 * this bounds the ungraceful ones.
 *
 * A worker that dies mid-job (OOM, restart, kill) used to loop forever:
 * claim → die → requeue → claim → … — no verdict, invisible in every block
 * (a `claimed` row is neither "not processed" nor "can't read"), and a fresh
 * engine bill every cycle. The ceiling ends that loop with a real verdict in
 * both tables: `error_worker_died`.
 */
class RecoveryCapTest extends TestCase
{
	/** @var list<array<string, mixed>> */
	private array $ltCalls = [];

	/** @var list<array<string, mixed>> */
	private array $gteCalls = [];

	public function testTheRecoverySelectExcludesRunsAtTheCeiling(): void
	{
		// What gets requeued is `attempts < ATTEMPTS_CEILING`; the ceiling
		// rows are the doomed set. The SQL filter is the whole mechanism, so
		// pin its shape: a refactor that drops it reopens the infinite loop.
		$store = $this->storeReturning([]);
		$store->requeueStale(JobStore::CLAIM_LEASE_SECONDS);

		$ltCalls = array_filter($this->ltCalls, static fn (array $c): bool => $c['x'] === 'j.attempts');
		$this->assertCount(1, $ltCalls, 'the requeue select carries the ceiling filter');
		$this->assertSame(JobStore::ATTEMPTS_CEILING, (int) array_values($ltCalls)[0]['y'], 'the ceiling is the one shared constant');
	}

	public function testDoomedRunsAreTheComplementOfWhatGetsRequeued(): void
	{
		$store = $this->storeReturning([]);
		$store->doomedClaims(JobStore::CLAIM_LEASE_SECONDS);

		$gteCalls = array_filter($this->gteCalls, static fn (array $c): bool => $c['x'] === 'j.attempts');
		$this->assertCount(1, $gteCalls, 'the doomed select is the ceiling complement');
		$this->assertSame(JobStore::ATTEMPTS_CEILING, (int) array_values($gteCalls)[0]['y']);
	}

	public function testTheRecoveryVerdictRidesTheNormalSettlePath(): void
	{
		// `recordWorkerDied` must write like every other verdict — job row,
		// ledger row and spend booking together — so it reuses `finish()`.
		// The payload is the contract: a verdict, not retryable, and no page
		// count (unknown keeps the booking's estimate).
		$settlement = new class(
			$this->createMock(IDBConnection::class),
			$this->createMock(JobStore::class),
			$this->createMock(\OCA\OcrFlow\Storage\Ledger::class),
			new \OCA\OcrFlow\Service\RetryPolicy(),
		) extends SettlementService {
			/** @var list<array{id: int, result: array, token: string}> */
			public array $finished = [];

			public function finish(int $id, array $result, string $claimToken, ?int $expectedClaimRevision = null): bool
			{
				$this->finished[] = ['id' => $id, 'result' => $result, 'token' => $claimToken, 'revision' => $expectedClaimRevision];

				return true;
			}
		};

		$job = new Job();
		$job->setId(42);
		$job->setClaimToken(str_repeat('t', 64));

		$settlement->recordWorkerDied($job);

		$this->assertCount(1, $settlement->finished);
		$call = $settlement->finished[0];
		$this->assertSame(42, $call['id']);
		$this->assertSame(str_repeat('t', 64), $call['token'], 'the verdict settles the claim it was taken for');
		$this->assertSame('error', $call['result']['status']);
		$this->assertSame('error_worker_died', $call['result']['reason_code']);
		$this->assertFalse($call['result']['retryable'], 'no try left: a human must decide');
		$this->assertArrayNotHasKey('pages', $call['result'], 'pages unknown: the books keep the booked estimate');
	}

	// ── seams ──────────────────────────────────────────────────────────────

	private function storeReturning(array $rows): JobStore
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1 = 1');
		$expr->method('lt')->willReturnCallback(function ($x, $y) {
			$this->ltCalls[] = ['x' => $x, 'y' => $y];

			return '1 = 1';
		});
		$expr->method('gte')->willReturnCallback(function ($x, $y) {
			$this->gteCalls[] = ['x' => $x, 'y' => $y];

			return '1 = 1';
		});
		$expr->method('orX')->willReturn($this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class));
		$expr->method('isNull')->willReturn('1 = 1');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v): string => is_scalar($v) ? (string) $v : ':p');
		$qb->method('createFunction')->willReturn('0');
		foreach (['select', 'addSelect', 'from', 'leftJoin', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'update', 'set'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new JobStore($db, new \OCA\OcrFlow\Service\RetryPolicy());
	}
}
