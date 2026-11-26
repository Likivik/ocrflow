<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Controller\QueueController;
use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\WorkerRegistry;
use OCA\OcrFlow\Service\FileExchange;
use OCA\OcrFlow\Service\Locks;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\SettlementService;
use OCA\OcrFlow\Storage\SpendJournal;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] The claim gate: a file someone else is in is skipped, not locked.
 *
 * The gate walks the queued candidates. For each one it claims the row
 * FIRST and takes the run lock SECOND (the other order would let two
 * workers "take" the same file, because an existing own lock EXTENDS
 * instead of throwing). When the lock is refused — someone is editing the
 * file right now — the claim goes straight back:
 *
 *  - the row stays `queued` (no attempt burned on a file nobody can read),
 *  - the run costs nothing (the day is not charged for a skipped file),
 *  - the NEXT candidate has the turn in the same request.
 *
 * `Locks` is final, so it is not mocked here: the real class is wired to a
 * fake lock manager whose `lock()` refuses the first file and admits the
 * second. That way the test exercises the actual gate, not a stub of it.
 */
class QueueClaimLockGateTest extends TestCase
{
	private JobStore&MockObject $jobStore;

	private SettlementService&MockObject $settlement;

	private SpendJournal&MockObject $spend;

	private LoggerInterface&MockObject $logger;

	/** @var array<string, string> */
	private array $headers = [];

	/** @var list<array{file_id: int, token: string}> */
	private array $released = [];

	/** @var list<int> */
	private array $claimed = [];

	/** @var list<int> the file ids whose lock was attempted */
	private array $lockAttempts = [];

	protected function setUp(): void
	{
		$this->jobStore = $this->createMock(JobStore::class);
		$this->settlement = $this->createMock(SettlementService::class);
		$this->spend = $this->createMock(SpendJournal::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->headers = [];
		$this->released = [];
		$this->claimed = [];
		$this->lockAttempts = [];

		$this->spend->method('reserveForRun')->willReturn(true);
	}

	/**
	 * A job row as the queue hands it back after `claimById()`.
	 */
	private function candidate(int $id, int $fileId, string $token, string $path): Job
	{
		return Job::fromRow([
			'id' => $id,
			'file_id' => $fileId,
			'path' => $path,
			'status' => JobStore::STATUS_CLAIMED,
			'reason' => null,
			'reason_code' => null,
			'retryable' => null,
			'engine' => 'tesseract',
			'attempts' => 1,
			'size' => 100,
			'etag' => 'intake-etag',
			'pages' => null,
			'chars' => null,
			'error' => null,
			'created_at' => 1700000000,
			'claimed_at' => 1700000001,
			'finished_at' => null,
			'claim_token' => $token,
			'claimed_by_worker_id' => 11,
		]);
	}

	/**
	 * The real `Locks`, wired to a fake manager that refuses the files
	 * listed in $refused and admits every other one.
	 *
	 * @param list<int> $refused
	 */
	private function locks(array $refused): Locks
	{
		$manager = $this->createMock(ILockManager::class);
		$manager->method('isLockProviderAvailable')->willReturn(true);
		$manager->method('lock')->willReturnCallback(function (LockContext $ctx) use ($refused): ILock {
			$fileId = (int) $ctx->getNode()->getId();
			$this->lockAttempts[] = $fileId;
			if (in_array($fileId, $refused, true)) {
				throw new OwnerLockedException($this->createMock(ILock::class));
			}

			return $this->createMock(ILock::class);
		});

		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnCallback(function (int $fileId): array {
			$node = $this->createMock(Node::class);
			$node->method('getId')->willReturn($fileId);

			return [$node];
		});
		$root->method('getUserFolder')->willReturnCallback(function (string $owner): Folder {
			$folder = $this->createMock(Folder::class);
			$folder->method('get')->willReturnCallback(function (string $relative) {
				$file = $this->createMock(\OCP\Files\File::class);
				$file->method('getEtag')->willReturn('run-etag');

				return $file;
			});

			return $folder;
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				return $key === 'lock_while_running' ? 'yes' : $default;
			},
		);

		return new Locks($manager, $root, $config, $this->logger);
	}

	private function controller(Locks $locks): QueueController
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				return $key === 'worker_secret' ? 's3cret' : $default;
			},
		);

		$this->headers[Application::WORKER_SECRET_HEADER] = 's3cret';

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $this->headers[$name] ?? ''
		);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => $default
		);

		$this->jobStore->method('claimById')->willReturnCallback(function (int $id, string $worker, ?int $workerId = null): ?Job {
			$this->claimed[] = $id;

			return match ($id) {
				1 => $this->candidate(1, 11, 'tok-1', '/u/files/first.pdf'),
				2 => $this->candidate(2, 22, 'tok-2', '/u/files/second.pdf'),
				default => null,
			};
		});
		$this->jobStore->method('release')->willReturnCallback(function (int $id, string $token): bool {
			$this->released[] = ['file_id' => $id, 'token' => $token];

			return true;
		});
		$this->jobStore->method('setRunEtag')->willReturn(true);

		return new QueueController(
			Application::APP_ID,
			$request,
			$this->jobStore,
			$this->settlement,
			$config,
			$this->logger,
			$this->createMock(FileExchange::class),
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
			null,
			null,
			$this->spend,
			null,
			$locks,
		);
	}

	public function testALockedCandidateIsGivenBackAndTheNextOneRuns(): void
	{
		$this->jobStore->method('queuedIds')->willReturn([1, 2]);

		$response = $this->controller($this->locks(refused: [11]))->claim();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $response->getData()['id'] ?? null, 'the second candidate is the one that runs');

		$this->assertSame([1, 2], $this->claimed, 'both candidates are considered in the same request');
		$this->assertSame([11, 22], $this->lockAttempts, 'the lock is tried for each candidate');
		$this->assertSame(
			[['file_id' => 1, 'token' => 'tok-1']],
			$this->released,
			'the locked candidate is the only one given back',
		);
	}

	public function testTheGivenBackCandidateCostsNothing(): void
	{
		$this->jobStore->method('queuedIds')->willReturn([1, 2]);

		$this->spend->expects($this->once())->method('reserveForRun')
			->with('u', 2, 1, 1)
			->willReturn(true);
		$this->settlement->expects($this->never())->method('finish');

		$this->controller($this->locks(refused: [11]))->claim();
	}

	public function testAQueueOfOnlyLockedFilesAnswersNothingToDo(): void
	{
		$this->jobStore->method('queuedIds')->willReturn([1]);

		$response = $this->controller($this->locks(refused: [11]))->claim();

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame(
			[['file_id' => 1, 'token' => 'tok-1']],
			$this->released,
			'the row goes back to the queue',
		);
		$this->assertSame([11], $this->lockAttempts, 'the only candidate was tried once');
	}

	public function testTheClaimRecordsTheEtagTheRunWorksAgainst(): void
	{
		$this->jobStore->method('queuedIds')->willReturn([1, 2]);

		$this->jobStore->expects($this->once())->method('setRunEtag')
			->with(2, 'tok-2', 'run-etag')
			->willReturn(true);

		$this->controller($this->locks(refused: [11]))->claim();
	}
}
