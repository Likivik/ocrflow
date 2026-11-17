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

	public function testReadByNamesTheEngineAndItsVersion(): void
	{
		// The question is "is my archive read, and by whom" — not "how many
		// runs happened". The ledger is never purged, so this survives.
		$queue = $this->createMock(Queue::class);
		$queue->method('counts')->willReturn(['done' => 2036]);
		$queue->method('skippedByReason')->willReturn([]);
		$queue->method('errorReasons')->willReturn([]);
		$queue->method('provenanceCounts')->willReturn([
			['engine' => 'google', 'version' => '3.15.0', 'count' => 2036],
			['engine' => 'tesseract', 'version' => '5.3.0', 'count' => 9],
			['engine' => 'google', 'version' => null, 'count' => 605],
			['engine' => null, 'version' => null, 'count' => 5470],
		]);

		$readBy = $this->controller($queue)->index()->getData()['read_by'];

		$this->assertSame('google 3.15.0', $readBy[0]['label']);
		$this->assertFalse($readBy[0]['below_bar'], 'the default bar is google 3.15.0 — this meets it');
		$this->assertTrue($readBy[1]['below_bar'], 'tesseract is not in the accepted engines, so it is below the bar');
		$this->assertSame('google (version not recorded)', $readBy[2]['label']);
		$this->assertFalse($readBy[2]['below_bar'], 'an unrecorded version is unknown, not condemned');
		$this->assertCount(3, $readBy, 'files with no engine are not "read by" anything and are filtered out');
	}

	public function testErrorReasonsCarryTheSameSentencesAsTheSkipSplit(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('counts')->willReturn(['error' => 5]);
		$queue->method('skippedByReason')->willReturn([]);
		$queue->method('provenanceCounts')->willReturn([]);
		$queue->method('errorReasons')->willReturn([
			'error_source_file_zero_bytes' => 3,
			'error_worker_file_gone' => 2,
		]);

		$reasons = $this->controller($queue)->index()->getData()['error_reasons'];

		$this->assertSame('error_source_file_zero_bytes', $reasons[0]['code']);
		$this->assertSame(3, $reasons[0]['count']);
		$this->assertSame('This file is empty (0 bytes).', $reasons[0]['label']);
	}
}
