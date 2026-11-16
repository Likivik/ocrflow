<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Command;

use OCA\OcrFlow\Command\Rescan;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * [unit] The terminal door spends money, so the two things that must hold are
 * that it never does so silently and never does so twice for the same row.
 *
 * `--dry-run` is the guard an operator needs before pointing this at 2,480
 * files: it must print the selection and touch nothing. Everything else is
 * plumbing around `Queue::forceRequeue()` — which the admin endpoint already
 * uses — so the tests here check the wiring, not the SQL.
 */
class RescanCommandTest extends TestCase
{
	/**
	 * @param list<int> $ids
	 */
	private function queue(array $ids = [], array $result = ['requeued' => [], 'refused' => []]): Queue
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('idsWithReasonCode')->willReturn($ids);
		$queue->method('forceRequeue')->willReturn($result);
		return $queue;
	}

	/**
	 * RetryPolicy is final and its vocabulary is data, not behaviour: the real
	 * instance answers `isKnown` from the table, which is exactly what the
	 * command must consult.
	 */
	private function policy(): RetryPolicy
	{
		return new RetryPolicy();
	}

	public function testDryRunPrintsTheSelectionAndSpendsNothing(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->expects($this->never())->method('forceRequeue');

		$tester = new CommandTester(new Rescan($queue, $this->policy()));
		$tester->execute(['--ids' => '4211,4183', '--dry-run' => true]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('would re-queue 2', $tester->getDisplay());
		$this->assertStringContainsString('4211,4183', $tester->getDisplay());
	}

	public function testAReasonCodeSelectsThePopulation(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('idsWithReasonCode')->willReturn([7, 8]);
		$queue->expects($this->once())
			->method('forceRequeue')
			->with([7, 8])
			->willReturn(['requeued' => [7, 8], 'refused' => []]);

		$tester = new CommandTester(new Rescan($queue, $this->policy()));
		$tester->execute(['--code' => 'skip_ocred_by_suspected_foreign']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('requeued 2, refused 0', $tester->getDisplay());
	}

	public function testAnUnknownCodeIsRefusedBeforeAnythingIsQueued(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->expects($this->never())->method('forceRequeue');

		$tester = new CommandTester(new Rescan($queue, $this->policy()));
		$tester->execute(['--code' => 'not_a_code_at_all']);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('unknown reason code', $tester->getDisplay());
	}

	public function testNoSelectorIsAnError(): void
	{
		$tester = new CommandTester(new Rescan($this->queue(), $this->policy()));
		$tester->execute([]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('--ids or --code is required', $tester->getDisplay());
	}

	public function testRefusalsAreNamedNotSwallowed(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('idsWithReasonCode')->willReturn([7, 9]);
		$queue->method('forceRequeue')->willReturn(['requeued' => [7], 'refused' => [9]]);

		$tester = new CommandTester(new Rescan($queue, $this->policy()));
		$tester->execute(['--code' => 'skip_text_native']);

		$this->assertStringContainsString('requeued 1, refused 1', $tester->getDisplay());
		$this->assertStringContainsString('refused (not done/skipped/error): 9', $tester->getDisplay());
	}
}
