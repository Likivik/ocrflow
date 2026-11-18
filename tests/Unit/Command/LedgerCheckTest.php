<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Command;

use OCA\OcrFlow\Command\LedgerCheck;
use OCA\OcrFlow\Db\Queue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The report reads the ledger, so the ledger must be checked.
 *
 * The command is an audit, not a repair: it reports and exits non-zero. A
 * repair that guesses is how a ledger stops being evidence.
 */
class LedgerCheckTest extends TestCase
{
	private function tester(array $drift): CommandTester
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('ledgerDrift')->willReturn($drift);

		return new CommandTester(new LedgerCheck($queue));
	}

	public function testAnAgreeingLedgerExitsZero(): void
	{
		$tester = $this->tester([]);

		$status = $tester->execute([]);

		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringContainsString('agrees with the job rows', $tester->getDisplay());
	}

	public function testADisagreementIsPrintedAndFails(): void
	{
		$tester = $this->tester([[
			'file_id' => 4242,
			'ledger_status' => 'skipped',
			'ledger_reason' => 'skip_text_native',
			'job_status' => 'done',
			'job_reason' => null,
		]]);

		$status = $tester->execute([]);
		$display = $tester->getDisplay();

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('file 4242', $display);
		$this->assertStringContainsString('ledger skipped (skip_text_native)', $display);
		$this->assertStringContainsString('Nothing was changed', $display);
	}

	public function testAMissingLedgerRowIsItsOwnKindOfDrift(): void
	{
		// A file settled in the job table with no ledger row at all is worse
		// than a wrong value: the report cannot see the file at all.
		$tester = $this->tester([[
			'file_id' => 7,
			'ledger_status' => '(no ledger row)',
			'ledger_reason' => null,
			'job_status' => 'done',
			'job_reason' => null,
		]]);

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertStringContainsString('(no ledger row)', $tester->getDisplay());
	}
}
