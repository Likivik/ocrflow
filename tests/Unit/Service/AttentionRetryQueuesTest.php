<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Db\Queue;
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
	/** @var Queue&MockObject */
	private Queue $queue;
	private Attention $attention;

	protected function setUp(): void
	{
		$this->queue = $this->createMock(Queue::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('500');
		$this->attention = new Attention(
			$this->queue,
			new RetryPolicy(),
			$config,
			new NullLogger(),
		);
	}

	public function testRetryingAFileQueuesItAgain(): void
	{
		$this->queue->method('findFileByPath')
			->with('/bob/files/broken.pdf')
			->willReturn([
				'file_id' => 7,
				'path' => '/bob/files/broken.pdf',
				'status' => 'needs_fix',
				'reason' => 'error_file_broken',
				'etag' => 'abc123',
			]);
		$this->queue->method('deleteTerminalForFile')->with(7)->willReturn(2);

		$this->queue->expects($this->once())
			->method('enqueue')
			->with('/bob/files/broken.pdf', 7, null, 'abc123')
			->willReturn(42);

		$deleted = $this->attention->retryByFileId(7, '/bob/files/broken.pdf');

		self::assertSame(2, $deleted, 'the two terminal rows must still be reported as deleted');
	}

	public function testRetryStillQueuesWhenTheLedgerHasNoEtag(): void
	{
		$this->queue->method('findFileByPath')->willReturn(null);

		$this->queue->expects($this->once())
			->method('enqueue')
			->with('/bob/files/broken.pdf', 7, null, null)
			->willReturn(43);

		$this->attention->retryByFileId(7, '/bob/files/broken.pdf');
	}
}
