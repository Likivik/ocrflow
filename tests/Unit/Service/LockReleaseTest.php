<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

require_once __DIR__ . '/../BackgroundJob/oc_hooks_emitter.php';

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\BackgroundJob\ReconcileJob;
use OCA\OcrFlow\Controller\QueueController;
use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\WorkerRegistry;
use OCA\OcrFlow\Service\EnginePolicy;
use OCA\OcrFlow\Service\FileExchange;
use OCA\OcrFlow\Service\Locks;
use OCA\OcrFlow\Service\PendingResults;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\EnqueueService;
use OCA\OcrFlow\Storage\Ledger;
use OCA\OcrFlow\Storage\SettlementService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\LockContext;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** [unit] Every way a run ends gives its file lock back, including failure. */
class LockReleaseTest extends TestCase
{
	/** @var list<int> */
	private array $released = [];
	/** @var list<string> */
	private array $unlockOwners = [];
	private bool $nodeExists = true;
	/** @var list<mixed> */
	private array $dbParams = [];

	protected function setUp(): void
	{
		$this->released = [];
		$this->unlockOwners = [];
		$this->nodeExists = true;
		$this->dbParams = [];
	}

	private function locks(): Locks
	{
		$manager = $this->createMock(ILockManager::class);
		$manager->method('isLockProviderAvailable')->willReturn(true);
		$manager->method('unlock')->willReturnCallback(function (LockContext $context): ILock {
			$this->released[] = (int) $context->getNode()->getId();
			$this->unlockOwners[] = $context->getOwner();
			return $this->createMock(ILock::class);
		});

		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnCallback(function (int $fileId): array {
			if (!$this->nodeExists) {
				return [];
			}
			$node = $this->createMock(Node::class);
			$node->method('getId')->willReturn($fileId);
			return [$node];
		});

		return new Locks(
			$manager,
			$root,
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function job(): Job
	{
		$job = new Job();
		$job->setId(9);
		$job->setFileId(7);
		$job->setPath('/alice/files/source.pdf');
		$job->setStatus(JobStore::STATUS_CLAIMED);
		$job->setClaimToken('tok');
		$job->setClaimRevision(4);
		$job->setAttempts(1);
		$job->setEtag('source-etag');
		$job->setEngine('tesseract');

		return $job;
	}

	private function db(): IDBConnection
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1 = 1');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value) {
			$this->dbParams[] = $value;
			return ':p';
		});
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('executeStatement')->willReturn(1);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}

	private function settlement(Job $job, Ledger $ledger, Locks $locks): SettlementService
	{
		$jobs = $this->createMock(JobStore::class);
		$jobs->method('getById')->willReturn($job);
		$policy = new RetryPolicy();

		return new SettlementService(
			$this->db(),
			$jobs,
			$ledger,
			$policy,
			$this->createMock(LoggerInterface::class),
			new StageEvents($policy, $this->createMock(LoggerInterface::class)),
			null,
			$locks,
		);
	}

	public function testAFinishedRunReleasesItsLock(): void
	{
		$settlement = $this->settlement($this->job(), $this->createMock(Ledger::class), $this->locks());

		$this->assertTrue($settlement->finish(9, ['status' => 'done'], 'tok'));
		$this->assertSame([7], $this->released);
	}

	public function testEachClaimReleasesOnlyItsOwnLockIdentity(): void
	{
		$settlement = $this->settlement($this->job(), $this->createMock(Ledger::class), $this->locks());

		$settlement->finish(9, ['status' => 'done'], 'old-claim');
		$settlement->finish(9, ['status' => 'done'], 'new-claim');

		$this->assertCount(2, $this->unlockOwners);
		$this->assertNotSame($this->unlockOwners[0], $this->unlockOwners[1]);
		$this->assertStringNotContainsString('old-claim', $this->unlockOwners[0]);
		$this->assertStringNotContainsString('new-claim', $this->unlockOwners[1]);
	}

	public function testAFailedSettleStillReleasesItsLock(): void
	{
		$ledger = $this->createMock(Ledger::class);
		$ledger->method('recordFile')->willThrowException(new \RuntimeException('ledger is down'));
		$settlement = $this->settlement($this->job(), $ledger, $this->locks());

		try {
			$settlement->finish(9, ['status' => 'done'], 'tok');
			$this->fail('the settle error must still reach the caller');
		} catch (\RuntimeException $e) {
			$this->assertSame('ledger is down', $e->getMessage());
		}
		$this->assertSame([7], $this->released);
	}

	public function testFinishingWhenNoLockCanBeResolvedDoesNotThrow(): void
	{
		$this->nodeExists = false;
		$settlement = $this->settlement($this->job(), $this->createMock(Ledger::class), $this->locks());

		$this->assertTrue($settlement->finish(9, ['status' => 'done'], 'tok'));
		$this->assertSame([], $this->released);
	}

	public function testAGracefulWorkerReleaseAlsoReleasesTheFile(): void
	{
		$job = $this->job();
		$jobs = $this->createMock(JobStore::class);
		$jobs->method('getById')->willReturn($job);
		$jobs->expects($this->once())->method('release')->with(9, 'tok')->willReturn(true);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('s3cret');
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => match ($name) {
			Application::WORKER_SECRET_HEADER => 's3cret',
			Application::CLAIM_HEADER => 'tok',
			default => '',
		});
		$controller = new QueueController(
			Application::APP_ID,
			$request,
			$jobs,
			$this->createMock(SettlementService::class),
			$config,
			$this->createMock(LoggerInterface::class),
			$this->createMock(FileExchange::class),
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
			null,
			null,
			null,
			null,
			$this->locks(),
		);

		$this->assertTrue($controller->release(9)->getData()['released']);
		$this->assertSame([7], $this->released);
	}

	public function testRecoverySettlementIsFencedByClaimRevision(): void
	{
		$settlement = $this->settlement($this->job(), $this->createMock(Ledger::class), $this->locks());

		$this->assertTrue($settlement->finish(9, ['status' => 'error'], 'tok', 4));
		$this->assertContains(4, $this->dbParams, 'a renewed claim must fail the terminal recovery fence');
	}

	public function testTheSweepDrainsPendingObservationsAfterRecovery(): void
	{
		$jobs = $this->createMock(JobStore::class);
		$jobs->method('requeueStale')->willReturn(0);
		$jobs->method('doomedClaims')->willReturn([]);
		$engineConfig = $this->createMock(IConfig::class);
		$engineConfig->method('getAppValue')->willReturn('tesseract');
		$engine = new EnginePolicy($engineConfig);
		$enqueue = $this->createMock(EnqueueService::class);
		$enqueue->expects($this->once())->method('drainPendingBatch')
			->with(500, 'tesseract')
			->willReturn(['drained' => 1, 'deferred' => 0, 'refused' => 0]);

		$this->sweep($jobs, $this->createMock(SettlementService::class), null, $enqueue, $engine);
	}

	private function sweep(
		JobStore $jobs,
		SettlementService $settlement,
		?Locks $fileLocks = null,
		?EnqueueService $enqueue = null,
		?EnginePolicy $enginePolicy = null,
	): void {
		$workers = $this->createMock(WorkerRegistry::class);
		$workers->method('pruneStale')->willReturn(0);
		$pending = $this->createMock(PendingResults::class);
		$pending->method('sweepExpired')->willReturn(0);
		$pending->method('totalArtifactBytes')->willReturn(0);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');

		$sweep = new ReconcileJob(
			$this->createMock(ITimeFactory::class),
			$jobs,
			$this->createMock(LoggerInterface::class),
			$config,
			$this->createMock(ILockingProvider::class),
			$workers,
			$pending,
			$settlement,
			null,
			$fileLocks,
			$enqueue,
			$enginePolicy,
		);
		$run = new \ReflectionMethod(ReconcileJob::class, 'run');
		$run->setAccessible(true);
		$run->invoke($sweep, null);
	}

	public function testARequeuedStaleRunReleasesItsFileLock(): void
	{
		$job = $this->job();
		$jobs = $this->createMock(JobStore::class);
		$jobs->method('requeueStale')->willReturnCallback(function (int $seconds, ?array &$pairs): int {
			$pairs = [9 => ['id' => 9, 'attempts' => 1, 'claim_token' => 'stale-claim']];
			return 1;
		});
		$jobs->method('doomedClaims')->willReturn([]);
		$jobs->method('getById')->willReturn($job);

		$this->sweep($jobs, $this->createMock(SettlementService::class), $this->locks());

		$this->assertSame([7], $this->released);
	}

	public function testTheStaleSweepSettlesDoomedClaimsThroughTheReleasePath(): void
	{
		$job = $this->job();
		$jobs = $this->createMock(JobStore::class);
		$jobs->method('requeueStale')->willReturn(0);
		$jobs->method('doomedClaims')->willReturn([$job]);
		$jobs->expects($this->never())->method('getById')
			->with($this->anything());
		$settlement = $this->createMock(SettlementService::class);
		$settlement->expects($this->once())->method('recordWorkerDied')->with($job)->willReturn(true);

		$this->sweep($jobs, $settlement);
	}
}
