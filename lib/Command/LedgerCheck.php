<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Command;

use OCA\OcrFlow\Storage\Ledger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prove the per-file ledger still says what the job rows say.
 *
 * The report reads the ledger, because a job row is a run and the ledger is the
 * file. That only holds while every settle path writes the ledger — and the way
 * to know one does not is to compare them.
 *
 *   occ ocrflow:ledger:check            # the first 500 disagreements
 *   occ ocrflow:ledger:check --limit 50
 *
 * Read-only: it prints disagreements and exits non-zero. It never repairs
 * anything, because a repair that guesses is how a ledger stops being evidence.
 */
class LedgerCheck extends Command
{
	public function __construct(
		private Ledger $ledger,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('ocrflow:ledger:check')
			->setDescription('Report files whose ledger row disagrees with their last settled job row')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'how many disagreements to print', '500');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$limit = max(1, (int) $input->getOption('limit'));
		$report = $this->ledger->ledgerDrift($limit);
		$total = (int) $report['total'];
		$drift = $report['rows'];

		if ($total === 0) {
			$output->writeln('<info>ocrflow: the ledger agrees with the job rows.</info>');
			return Command::SUCCESS;
		}

		$output->writeln(
			'<comment>' . $total . ' file(s) where the ledger and the last settled job row disagree'
			. ($total > count($drift) ? ' (showing ' . count($drift) . ')' : '') . ':</comment>',
		);
		foreach ($drift as $row) {
			$output->writeln(sprintf(
				'  file %d: ledger %s (%s) · job %s (%s)',
				$row['file_id'],
				$row['ledger_status'],
				$row['ledger_reason'] ?? '-',
				$row['job_status'],
				$row['job_reason'] ?? '-',
			));
		}
		$output->writeln('Nothing was changed. A disagreement means a settle path forgot the ledger.');

		return Command::FAILURE;
	}
}
