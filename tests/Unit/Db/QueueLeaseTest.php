<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Recovery reads the lease, not the worker's heartbeat.
 *
 * Three live rows proved why: the holder was healthy and heartbeating, its
 * report was lost to a 502 during a deploy, and the claim could never be
 * recovered because "alive" was the only question being asked.
 */
class QueueLeaseTest extends TestCase
{
	/** @var list<mixed> every value bound as a query parameter */
	private array $params = [];

	/** @var list<string> every predicate the recovery query asks for */
	private array $predicates = [];

	private function queue(): Queue
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([['id' => 938]]);

		$composite = $this->createMock(ICompositeExpression::class);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('in')->willReturn('in');
		$expr->method('lt')->willReturnCallback(function ($column, $ignored) {
			$this->predicates[] = 'lt:' . (string) $column;
			return 'lt';
		});
		$expr->method('isNull')->willReturnCallback(function ($column) {
			$this->predicates[] = 'isNull:' . (string) $column;
			return 'isnull';
		});
		$expr->method('orX')->willReturnCallback(function (...$parts) use ($composite) {
			$this->predicates[] = 'orX:' . count($parts);
			return $composite;
		});

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'leftJoin', 'where', 'andWhere', 'update', 'set', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = null) {
			$this->params[] = $value;
			return ':p' . count($this->params);
		});
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new Queue($db, new StageEvents(new RetryPolicy(), $this->createMock(LoggerInterface::class)));
	}

	public function testRecoveryAsksTheLeaseBeforeTheHeartbeat(): void
	{
		$requeued = $this->queue()->requeueStale(Queue::CLAIM_LEASE_SECONDS);

		$this->assertSame(1, $requeued);
		$this->assertContains('isNull:j.claim_expires_at', $this->predicates, 'a pre-lease claim is expired by definition');
		$this->assertContains('lt:j.claim_expires_at', $this->predicates, 'an expired lease is the primary rule');
		$this->assertContains('lt:j.claimed_at', $this->predicates, 'the hard cap catches a worker that renews forever');
		$this->assertContains('isNull:w.id', $this->predicates, 'a vanished holder is still a reason to recover');
	}

	public function testTheHardCapIsTwoHours(): void
	{
		$this->assertSame(7200, Queue::CLAIM_HARD_CAP_SECONDS);
	}

	public function testTheLeaseIsShorterThanTheReconcileTick(): void
	{
		// The reaper runs every 300 s; a lease longer than the tick would add a
		// whole tick of delay to every recovery for no reason.
		$this->assertLessThan(300, Queue::CLAIM_LEASE_SECONDS);
	}

	public function testRenewAndReleaseAreBothGuardedByTheClaimToken(): void
	{
		$queue = $this->queue();

		$this->params = [];
		$this->assertTrue($queue->renewClaim(7, 'token'));
		$this->assertContains('token', $this->params, 'a renew without the token would be a free takeover');

		$this->params = [];
		$this->assertTrue($queue->release(7, 'token'));
		$this->assertContains('token', $this->params, 'a release without the token would be a free takeover');
	}
}
