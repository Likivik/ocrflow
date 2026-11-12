<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Command;

use OCA\OcrFlow\Command\Requeue;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Attention;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The escape hatch must clear BOTH halves of the block: the ledger verdict
 * and the terminal job rows retention may not have purged yet. The retry
 * itself lives in the Attention service — the same implementation the
 * /attention endpoint uses — so this command is only the door.
 */
class RequeueTest extends TestCase
{
	private function command(Queue $queue): Requeue
	{
		return new Requeue(new Attention($queue, new RetryPolicy(), $this->createMock(IConfig::class)));
	}

	public function testItClearsTheVerdictAndTheTerminalJobRows(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('findFileByPath')->willReturn([
			'file_id' => 7,
			'path' => 'alice/files/scan.pdf',
			'status' => 'error',
			'reason' => 'broken_pdf',
			'etag' => 'e1',
		]);
		$queue->expects($this->once())->method('deleteTerminalForFile')->with(7)->willReturn(2);
		$queue->expects($this->once())->method('clearPermanentVerdict')->with(7);

		$tester = new CommandTester($this->command($queue));
		$tester->execute(['path' => 'alice/files/scan.pdf']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('broken_pdf', $tester->getDisplay());
		$this->assertStringContainsString('2 terminal job row', $tester->getDisplay());
	}

	public function testAnUnknownPathIsNotAnError(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('findFileByPath')->willReturn(null);
		$queue->expects($this->never())->method('deleteTerminalForFile');
		$queue->expects($this->never())->method('clearPermanentVerdict');

		$tester = new CommandTester($this->command($queue));
		$tester->execute(['path' => 'nobody/knows.pdf']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('no ocrflow record', $tester->getDisplay());
	}

	public function testADoneFileIsRefusedGently(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('findFileByPath')->willReturn([
			'file_id' => 7, 'path' => 'a.pdf', 'status' => 'done', 'reason' => null, 'etag' => 'e1',
		]);
		$queue->expects($this->never())->method('deleteTerminalForFile');

		$tester = new CommandTester($this->command($queue));
		$tester->execute(['path' => 'a.pdf']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('done, not failed', $tester->getDisplay());
	}
}
