<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Storage\JobStore;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

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

	/** @var list<string> every column an update assigns */
	private array $sets = [];

	private function jobs(int $updated = 1, array $row = []): JobStore
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([$row + [
			'id' => 938,
			'attempts' => 2,
			'claim_token' => 'stale-token',
			'claim_revision' => 4,
		]]);

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
		foreach (['select', 'from', 'leftJoin', 'where', 'andWhere', 'update', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('set')->willReturnCallback(function (string $column, mixed $value) use ($qb): IQueryBuilder {
			$this->sets[] = $column;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = null) {
			$this->params[] = $value;
			return ':p' . count($this->params);
		});
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn($updated);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new JobStore($db, new RetryPolicy());
	}

	public function testRecoveryAsksTheLeaseBeforeTheHeartbeat(): void
	{
		$requeued = $this->jobs()->requeueStale(JobStore::CLAIM_LEASE_SECONDS);

		$this->assertSame(1, $requeued);
		$this->assertContains('isNull:j.claim_expires_at', $this->predicates, 'a pre-lease claim is expired by definition');
		$this->assertContains('lt:j.claim_expires_at', $this->predicates, 'an expired lease is the primary rule');
		$this->assertContains('lt:j.claimed_at', $this->predicates, 'the hard cap catches a worker that renews forever');
		$this->assertContains('isNull:w.id', $this->predicates, 'a vanished holder is still a reason to recover');
	}

	public function testTheHardCapIsTwoHours(): void
	{
		$this->assertSame(7200, JobStore::CLAIM_HARD_CAP_SECONDS);
	}

	public function testTheLeaseIsShorterThanTheReconcileTick(): void
	{
		// The reaper runs every 300 s; a lease longer than the tick would add a
		// whole tick of delay to every recovery for no reason.
		$this->assertLessThan(300, JobStore::CLAIM_LEASE_SECONDS);
	}

	public function testRenewAndReleaseAreBothGuardedByTheClaimToken(): void
	{
		$jobs = $this->jobs();

		$this->params = [];
		$this->assertTrue($jobs->renewClaim(7, 'token'));
		$this->assertContains('token', $this->params, 'a renew without the token would be a free takeover');

		$this->params = [];
		$this->assertTrue($jobs->release(7, 'token'));
		$this->assertContains('token', $this->params, 'a release without the token would be a free takeover');
	}

	public function testRenewalChangesTheRevisionRecoveryMustMatch(): void
	{
		$this->sets = [];
		$this->assertTrue($this->jobs()->renewClaim(7, 'token'));

		$this->assertContains(
			'claim_revision',
			$this->sets,
			'a renewal that leaves the revision unchanged can be requeued from an old snapshot'
		);
	}

	public function testRecoveryIsFencedByTheObservedTokenAndRevision(): void
	{
		$pairs = null;
		$recovered = $this->jobs()->requeueStale(JobStore::CLAIM_LEASE_SECONDS, $pairs);

		$this->assertSame(1, $recovered);
		$this->assertContains('stale-token', $this->params, 'recovery must not overwrite a replacement claim');
		$this->assertContains(4, $this->params, 'recovery must not overwrite a renewed lease');
		$this->assertSame([938], array_keys($pairs ?? []));
	}

	public function testAPreRegistryClaimWithNoTokenIsMatchedAsNull(): void
	{
		$this->predicates = [];
		$recovered = $this->jobs(row: ['claim_token' => null])->requeueStale(JobStore::CLAIM_LEASE_SECONDS);

		$this->assertSame(1, $recovered);
		$this->assertContains('isNull:claim_token', $this->predicates, 'NULL never equals the empty string in SQL');
	}

	public function testARecoveryThatLostTheRaceReturnsNoCleanup(): void
	{
		$pairs = null;
		$recovered = $this->jobs(updated: 0)->requeueStale(JobStore::CLAIM_LEASE_SECONDS, $pairs);

		$this->assertSame(0, $recovered);
		$this->assertSame([], $pairs, 'cleanup must not run for a claim that was renewed or replaced');
	}
}
