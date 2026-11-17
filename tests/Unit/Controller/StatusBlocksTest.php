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
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Six blocks per FILE, and the rows that add up to each of them.
 *
 * The numbers below are the live ones measured on 2026-11-18, so a change in the
 * shape of the report breaks this test with the old truth visible in the diff.
 */
class StatusBlocksTest extends TestCase
{
	private function controller(array $report): StatusController
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');

		$queue = $this->createMock(Queue::class);
		$queue->method('fileReport')->willReturn($report);
		$queue->method('counts')->willReturn(['done' => 2657, 'skipped' => 1]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('likivik');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new StatusController(
			'ocrflow',
			$this->createMock(IRequest::class),
			$queue,
			$this->createMock(IJobList::class),
			$session,
			new AccessGate($config, $this->createMock(IGroupManager::class)),
			new EnginePolicy($config),
			new RetryPolicy(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function liveReport(): array
	{
		return [
			'total' => 7112,
			'never_processed' => 30,
			'empty' => 5,
			'unreadable_format' => 2,
			'queue' => ['queued' => 0, 'claimed' => 0],
			'by_code' => [
				'' => 2035,
				'skip_ocred_by_ocrflow' => 607,
				'skip_ocred_by_ocrflow_found_nothing' => 15,
				'skip_text_native' => 2512,
				'skip_classifier_says_photo' => 1908,
				'error_file_broken' => 5,
			],
			'read_by_engine' => [
				['engine' => 'google', 'version' => '3.16.0', 'count' => 1630],
				['engine' => 'google', 'version' => null, 'count' => 1012],
			],
		];
	}

	public function testTheSixBlocksComeInPanelOrder(): void
	{
		$blocks = $this->controller($this->liveReport())->index()->getData()['blocks'];

		$this->assertSame(
			['not_processed', 'queue', 'read', 'born_digital', 'image', 'error'],
			array_column($blocks, 'id'),
		);
		$this->assertSame('Not processed yet', $blocks[0]['label']);
	}

	public function testNotProcessedIsAFactAboutTheDisk(): void
	{
		$blocks = $this->controller($this->liveReport())->index()->getData()['blocks'];

		$this->assertSame(30, $blocks[0]['count'], 'no code can produce this number');
		$this->assertSame([], $blocks[0]['rows']);
	}

	public function testTheQueueRowsSayProcessedNotRead(): void
	{
		// The panel is about files being processed, so the queue speaks that
		// language; "read" was left over from the first cut of this report.
		$queue = $this->controller($this->liveReport())->index()->getData()['blocks'][1];

		$this->assertSame(
			['waiting to be processed', 'being processed right now'],
			array_column($queue['rows'], 'label'),
		);
	}

	public function testTheReadBlockCountsFilesAndItsRowsSumToIt(): void
	{
		$read = $this->controller($this->liveReport())->index()->getData()['blocks'][2];

		// 2035 read with a stored result + 607 whose result was purged but whose
		// stamp is ours + 15 read with no text = 2,657 files, each counted once.
		$this->assertSame(2657, $read['count']);
		$this->assertSame(2657, array_sum(array_column($read['rows'], 'count')));
		$this->assertSame('by google 3.16.0', $read['rows'][0]['label']);
		$this->assertSame('by google, version not recorded', $read['rows'][1]['label']);
		$this->assertSame('read it, no text to transcribe', $read['rows'][2]['label']);
	}

	public function testAnUnknownVersionIsNotFlaggedAsBelowBar(): void
	{
		$read = $this->controller($this->liveReport())->index()->getData()['blocks'][2];

		$this->assertFalse($read['rows'][0]['below_bar'], 'google 3.16.0 meets the default bar');
		$this->assertFalse($read['rows'][1]['below_bar'], 'an unrecorded version is unknown, not bad');
	}

	public function testTheErrorBlockIsLabelledWithTheAppsOwnSentence(): void
	{
		$error = $this->controller($this->liveReport())->index()->getData()['blocks'][5];

		$this->assertSame(5, $error['count']);
		$this->assertSame('This file is damaged and cannot be read.', $error['rows'][0]['label']);
	}

	public function testBornDigitalAndImageAreCountedButHaveNoRows(): void
	{
		$blocks = $this->controller($this->liveReport())->index()->getData()['blocks'];

		$this->assertSame(2512, $blocks[3]['count']);
		$this->assertSame([], $blocks[3]['rows'], 'a fact about the file has no causes to list');
		$this->assertSame(1908, $blocks[4]['count']);
	}

	public function testTheFilesOutsideTheProcessableSetAreOnlyAFootnote(): void
	{
		$data = $this->controller($this->liveReport())->index()->getData();

		$this->assertSame(['empty' => 5, 'unreadable_format' => 2], $data['footnote']);
	}
}
