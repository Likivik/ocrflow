<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\BackgroundJob;

use OCA\OcrFlow\BackgroundJob\ReconcileJob;
use OCA\OcrFlow\Db\Queue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/oc_hooks_emitter.php';

/**
 * Retention config and the overlap guard.
 *
 * `jobs_retention_days` defaults to 90; `0` disables the sweep entirely (the
 * operator's way of saying "keep everything"); anything that is not a
 * positive whole number is a typo, not a policy, and becomes 90.
 *
 * The cron timer fires every five minutes and `oc_jobs.reserved_at` is epoch
 * zero, so nothing reserves this job exclusively — two ticks can overlap. The
 * server's own DB-backed locking provider decides, not the clock.
 */
class RetentionSweepTest extends TestCase
{
	private const NOW = 1700000000;

	private ITimeFactory&MockObject $time;
	private Queue&MockObject $queue;
	private LoggerInterface&MockObject $logger;
	private IConfig&MockObject $config;
	private ILockingProvider&MockObject $locks;

	/** @var list<string> */
	private array $logLines = [];

	private bool $lockHeld = false;

	protected function setUp(): void
	{
		$this->time = $this->createMock(ITimeFactory::class);
		$this->queue = $this->createMock(Queue::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->config = $this->createMock(IConfig::class);
		$this->locks = $this->createMock(ILockingProvider::class);

		$this->time->method('getTime')->willReturn(self::NOW);

		// Unlocked by default: the guard is a separate test.
		$this->locks->method('isLocked')->willReturnCallback(function (): bool {
			return $this->lockHeld;
		});
		$this->locks->method('acquireLock')->willReturnCallback(function (string $token): void {
			$this->logLines[] = "lock:$token";
		});
		$this->locks->method('releaseLock')->willReturnCallback(function (string $token): void {
			$this->logLines[] = "unlock:$token";
		});

		$this->logger->method('info')->willReturnCallback(function (string $message): void {
			$this->logLines[] = $message;
		});

		$this->config->method('getAppValue')->willReturnCallback(
			function (string $appId, string $key, string $default = ''): string {
				if ($key === 'jobs_retention_days') {
					return $this->retentionDays ?? $default;
				}
				return $default;
			},
		);
	}

	private string $retentionDays = '90';

	private function runJob(): void
	{
		$job = new ReconcileJob(
			$this->time,
			$this->queue,
			$this->logger,
			$this->config,
			$this->locks,
		);
		$method = new \ReflectionMethod(ReconcileJob::class, 'run');
		$method->invoke($job, null);
	}

	private function withDays(string $days): void
	{
		$this->retentionDays = $days;
	}

	public function testTheDefaultIsNinetyDays(): void
	{
		$this->queue->expects($this->once())->method('purgeTerminal')->with(
			self::NOW - 90 * 86400,
			ReconcileJob::RETENTION_MAX_PER_RUN,
		)->willReturn(['jobs' => 0, 'stage_events' => 0]);
		$this->runJob();
	}

	public function testAnExplicitDaysValueIsHonoured(): void
	{
		$this->withDays('30');
		$this->queue->expects($this->once())->method('purgeTerminal')->with(
			self::NOW - 30 * 86400,
			ReconcileJob::RETENTION_MAX_PER_RUN,
		)->willReturn(['jobs' => 0, 'stage_events' => 0]);
		$this->runJob();
	}

	public function testZeroDisablesTheSweepEntirely(): void
	{
		$this->withDays('0');
		$this->queue->expects($this->never())->method('purgeTerminal');
		$this->locks->expects($this->never())->method('acquireLock');
		$this->runJob();
	}

	#[DataProvider('garbageValues')]
	public function testGarbageFallsBackToTheDefault(string $garbage): void
	{
		$this->withDays($garbage);
		$this->queue->expects($this->once())->method('purgeTerminal')->with(
			self::NOW - 90 * 86400,
			ReconcileJob::RETENTION_MAX_PER_RUN,
		)->willReturn(['jobs' => 0, 'stage_events' => 0]);
		$this->runJob();
	}

	public static function garbageValues(): array
	{
		return [
			'non-numeric' => ['soon'],
			'negative' => ['-7'],
			'fractional' => ['2.5'],
			'empty' => [''],
			'spaces' => ['  '],
		];
	}

	public function testASecondRunIsSkippedWhileTheFirstHoldsTheLock(): void
	{
		$this->lockHeld = true;
		$this->queue->expects($this->never())->method('purgeTerminal');
		$this->runJob();
	}

	public function testTheLockIsReleasedAfterTheSweep(): void
	{
		$this->queue->method('purgeTerminal')->willReturn(['jobs' => 1, 'stage_events' => 0]);
		$this->runJob();

		$lock = array_search('lock:' . ReconcileJob::RETENTION_LOCK, $this->logLines, true);
		$unlock = array_search('unlock:' . ReconcileJob::RETENTION_LOCK, $this->logLines, true);
		$this->assertNotFalse($lock);
		$this->assertNotFalse($unlock);
		$this->assertGreaterThan($lock, $unlock, 'the lock is released, not abandoned');
	}

	public function testTheLockIsReleasedEvenWhenTheSweepThrows(): void
	{
		$this->queue->method('purgeTerminal')->willThrowException(new \RuntimeException('db gone'));
		try {
			$this->runJob();
			$this->fail('the sweep error must propagate');
		} catch (\RuntimeException $e) {
			// expected: a broken sweep must not take the lock down with it
		}
		$this->assertContains('unlock:' . ReconcileJob::RETENTION_LOCK, $this->logLines);
	}

	public function testOneSummaryLineOnlyWhenSomethingWasDeleted(): void
	{
		$this->queue->method('purgeTerminal')->willReturn(['jobs' => 5, 'stage_events' => 7]);
		$this->runJob();

		$retention = array_values(array_filter(
			$this->logLines,
			static fn (string $line): bool => str_contains($line, 'deleted'),
		));
		$this->assertCount(1, $retention, 'one summary line, not one per chunk');
		$this->assertStringContainsString('5 job', $retention[0]);
		$this->assertStringContainsString('7 stage', $retention[0]);
		$this->assertStringContainsString('90 day', $retention[0]);
	}

	public function testNothingIsLoggedWhenNothingWasDeleted(): void
	{
		$this->queue->method('purgeTerminal')->willReturn(['jobs' => 0, 'stage_events' => 0]);
		$this->runJob();

		$this->assertSame(
			['lock:' . ReconcileJob::RETENTION_LOCK, 'unlock:' . ReconcileJob::RETENTION_LOCK],
			$this->logLines,
			'a no-op sweep is silent',
		);
	}
}
