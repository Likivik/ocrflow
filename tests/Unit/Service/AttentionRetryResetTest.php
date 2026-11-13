<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Attention;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] The operator's requeue resets the loop-breaker counter.
 *
 * Catches: a retry that clears the verdict but leaves `times_enqueued`
 * armed — the next scan would refuse the very file the operator just
 * requeued, so `occ ocrflow:requeue` and `POST /attention/retry` would
 * answer success while queueing nothing.
 */
class AttentionRetryResetTest extends TestCase
{
	public function testRetryByIdResetsTimesEnqueued(): void
	{
		$job = new Job();
		$job->setId(9);
		$job->setPath('/alice/files/scan.pdf');
		$job->setReasonCode('error_queued_twice_for_same_bytes');
		$job->setFileId(7);

		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturn($job);
		$queue->expects($this->once())->method('deleteTerminalForFile')->with(7)->willReturn(1);
		$queue->expects($this->once())->method('clearPermanentVerdict')->with(7);
		$queue->expects($this->once())->method('resetTimesEnqueued')->with(7);

		$attention = new Attention(
			$queue,
			$this->retryPolicyWithTheCode(),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertTrue($attention->retryById(9), 'the loop-breaker code is a needs_fix, so the worklist must retry it');
	}

	public function testRetryPathResetsTimesEnqueuedToo(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('findFileByPath')->willReturn([
			'file_id' => 7,
			'path' => '/alice/files/scan.pdf',
			'status' => 'error',
			'reason' => 'error_queued_twice_for_same_bytes',
		]);
		$queue->expects($this->once())->method('deleteTerminalForFile')->with(7)->willReturn(0);
		$queue->expects($this->once())->method('clearPermanentVerdict')->with(7);
		$queue->expects($this->once())->method('resetTimesEnqueued')->with(7);

		$attention = new Attention(
			$queue,
			$this->retryPolicyWithTheCode(),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
		);

		$result = $attention->retryPath('/alice/files/scan.pdf');

		$this->assertSame('cleared', $result['status']);
	}

	private function retryPolicyWithTheCode(): \OCA\OcrFlow\Service\RetryPolicy
	{
		return new \OCA\OcrFlow\Service\RetryPolicy();
	}
}
