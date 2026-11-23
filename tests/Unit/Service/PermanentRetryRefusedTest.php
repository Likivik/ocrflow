<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Storage\EnqueueService;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\Ledger;
use OCA\OcrFlow\Service\Attention;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * [unit] A permanent failure must not be retryable through the endpoint.
 *
 * The panel hides Retry for rows whose code is permanent (a damaged file, a
 * zero-byte source): another attempt can only fail the same way, and on a paid
 * engine it is paid for twice. `retryById()` only checked the verdict, and
 * those codes are `needs_fix` — so a direct POST to `/api/v1/mine/retry` with
 * such a job id queued exactly the doomed work the UI refuses to offer.
 *
 * The row's own `retryable` answer is the authority; this test fails the
 * moment that guard is removed.
 */
class PermanentRetryRefusedTest extends TestCase
{
	/** @var JobStore&MockObject */
	private JobStore $jobs;
	/** @var Ledger&MockObject */
	private Ledger $ledger;
	/** @var EnqueueService&MockObject */
	private EnqueueService $enqueue;
	private Attention $attention;

	protected function setUp(): void
	{
		$this->jobs = $this->createMock(JobStore::class);
		$this->ledger = $this->createMock(Ledger::class);
		$this->enqueue = $this->createMock(EnqueueService::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('500');
		$this->attention = new Attention($this->jobs, $this->ledger, $this->enqueue, new RetryPolicy(), $config, new NullLogger());
	}

	private function job(string $code, string $reason): Job
	{
		return Job::fromRow([
			'id' => 11,
			'file_id' => 7,
			'path' => '/bob/files/broken.pdf',
			'status' => 'needs_fix',
			'reason' => $reason,
			'reason_code' => $code,
			'retryable' => null,
			'engine' => 'google',
			'claim_token' => null,
			'attempts' => 1,
			'size' => 1024,
			'etag' => 'abc123',
			'pages' => null,
			'chars' => null,
			'error' => null,
			'created_at' => 0,
			'claimed_at' => null,
			'finished_at' => null,
		]);
	}

	public function testADamagedFileIsRefusedWithoutQueueingAnything(): void
	{
		$this->jobs->method('getById')->with(11)->willReturn($this->job('error_file_broken', 'broken_pdf'));
		$this->enqueue->expects($this->never())->method('enqueue');

		self::assertFalse(
			$this->attention->retryById(11),
			'error_file_broken is needs_fix but permanent; a retry must be refused',
		);
	}

	public function testAZeroByteSourceIsRefusedWithoutQueueingAnything(): void
	{
		$this->jobs->method('getById')->with(11)->willReturn($this->job('error_source_file_zero_bytes', 'empty_source'));
		$this->enqueue->expects($this->never())->method('enqueue');

		self::assertFalse($this->attention->retryById(11));
	}

	public function testATransientFailureStillQueuesAnotherAttempt(): void
	{
		$this->jobs->method('getById')->with(11)->willReturn($this->job('error_provider_timed_out', 'engine_timeout'));
		$this->jobs->method('deleteTerminalForFile')->willReturn(1);
		$this->ledger->method('findFileByPath')->willReturn([
			'file_id' => 7,
			'path' => '/bob/files/broken.pdf',
			'status' => 'needs_fix',
			'reason' => 'engine_timeout',
			'etag' => 'abc123',
		]);
		$this->enqueue->expects($this->once())->method('enqueue')->with('/bob/files/broken.pdf', 7, null, 'abc123');

		self::assertTrue(
			$this->attention->retryById(11),
			'a provider timeout is retryable; the guard must not swallow it',
		);
	}
}
