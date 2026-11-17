<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\Controller\StatusController;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\AccessGate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * "Skipped" is six decisions wearing one number.
 *
 * A photo, a born-digital PDF, our own stamp, a foreign tesseract layer and an
 * unsupported type mean opposite things to the person reading the panel, and
 * the worker already records which one it was in `reason_code`. The status
 * endpoint splits the total by that code and carries the sentence the app
 * already writes for it, so the panel stays a dumb reader.
 */
class StatusSkipReasonsTest extends TestCase
{
	private function controller(Queue $queue, ?RetryPolicy $policy = null): StatusController
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');

		return new StatusController(
			'ocrflow',
			$this->createMock(IRequest::class),
			$queue,
			$this->createMock(IJobList::class),
			$this->createMock(IUserSession::class),
			new AccessGate($config, $this->createMock(IGroupManager::class)),
			new EnginePolicy($config),
			$policy ?? new RetryPolicy(),
		);
	}

	public function testTheSkippedTotalCarriesItsReasons(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('counts')->willReturn(['skipped' => 9, 'done' => 12]);
		$queue->method('skippedByReason')->willReturn([
			'skip_text_native' => 6,
			'skip_classifier_says_photo' => 3,
		]);

		$data = $this->controller($queue)->index()->getData();
		$reasons = $data['skip_reasons'];

		$this->assertCount(2, $reasons);
		$this->assertSame('skip_text_native', $reasons[0]['code']);
		$this->assertSame(6, $reasons[0]['count']);
		$this->assertSame(
			(new RetryPolicy())->entry('skip_text_native')['text'],
			$reasons[0]['label'],
			'the label is the sentence the app already writes for the code',
		);
		$this->assertSame(9, $data['queue']['skipped'], 'the total stays, as the sum of the parts');
	}

	public function testACodeWithNoSentenceFallsBackToTheCode(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('counts')->willReturn(['skipped' => 1]);
		$queue->method('skippedByReason')->willReturn(['skip_made_up' => 1]);

		$reasons = $this->controller($queue)->index()->getData()['skip_reasons'];

		$this->assertSame('skip_made_up', $reasons[0]['label']);
	}

	public function testTheLegacyBucketIsShownSoThePartsAddUp(): void
	{
		// 5,453 production rows were skipped before reason codes existed.
		// Hiding them would make the breakdown a smaller, wrong number.
		$queue = $this->createMock(Queue::class);
		$queue->method('counts')->willReturn(['skipped' => 5455]);
		$queue->method('skippedByReason')->willReturn(['' => 5453, 'skip_text_native' => 2]);

		$reasons = $this->controller($queue)->index()->getData()['skip_reasons'];

		$this->assertSame('', $reasons[0]['code']);
		$this->assertSame(5453, $reasons[0]['count']);
		$this->assertStringContainsString('No reason recorded', $reasons[0]['label']);
		$this->assertSame(5455, array_sum(array_column($reasons, 'count')));
	}

	public function testNoSkippedRowsMeansNoReasons(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('counts')->willReturn(['done' => 4]);
		$queue->method('skippedByReason')->willReturn([]);

		$this->assertSame([], $this->controller($queue)->index()->getData()['skip_reasons']);
	}
}
