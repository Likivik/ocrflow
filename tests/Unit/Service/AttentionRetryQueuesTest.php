<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

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
 * [unit] A retry must actually queue the file.
 *
 * The panel's message used to read "Queued again — the next OCR run picks it
 * up." while the backend only cleared state: it deleted the terminal rows and
 * the permanent verdict, then stopped. Nothing was queued, and no periodic
 * scan would have found the file afterwards — the operator had to guess that
 * they needed to start a scan of that folder by hand. A button that says it
 * queued something must queue something; this test fails the moment the
 * enqueue call disappears from Attention::retryFile().
 */
class AttentionRetryQueuesTest extends TestCase
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
		$this->attention = new Attention(
			$this->jobs,
			$this->ledger,
			$this->enqueue,
			new RetryPolicy(),
			$config,
			new NullLogger(),
		);
	}

	public function testRetryingAFileQueuesItAgain(): void
	{
		$this->ledger->method('findFileByPath')
			->with('/bob/files/broken.pdf')
			->willReturn([
				'file_id' => 7,
				'path' => '/bob/files/broken.pdf',
				'status' => 'needs_fix',
				'reason' => 'error_file_broken',
				'etag' => 'abc123',
			]);
		$this->jobs->method('deleteTerminalForFile')->with(7)->willReturn(2);

		$this->enqueue->expects($this->once())
			->method('enqueue')
			->with('/bob/files/broken.pdf', 7, null, 'abc123')
			->willReturn(42);

		$deleted = $this->attention->retryByFileId(7, '/bob/files/broken.pdf');

		self::assertSame(2, $deleted, 'the two terminal rows must still be reported as deleted');
	}

	public function testRetryStillQueuesWhenTheLedgerHasNoEtag(): void
	{
		$this->ledger->method('findFileByPath')->willReturn(null);

		$this->enqueue->expects($this->once())
			->method('enqueue')
			->with('/bob/files/broken.pdf', 7, null, null)
			->willReturn(43);

		$this->attention->retryByFileId(7, '/bob/files/broken.pdf');
	}
}
