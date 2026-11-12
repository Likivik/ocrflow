<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Command;

use OCA\OcrFlow\Service\Attention;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The attention worklist on the terminal.
 *
 *   occ ocrflow:errors
 *
 * Prints the same rows `GET /attention` and the admin panel show — the
 * three share one data source (the Attention service), so they cannot
 * disagree about whose work it is. Fixes happen through
 * `occ ocrflow:requeue <path>` once the cause is repaired.
 */
class Errors extends Command
{
	private const LIST_LIMIT = 500;

	public function __construct(
		private Attention $attention,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('ocrflow:errors')
			->setDescription('List the files that need a human: failures a retry cannot fix')
			->addOption(
				'code',
				null,
				InputOption::VALUE_REQUIRED,
				'show only rows with this reason code (e.g. error_provider_credentials_missing)',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$code = $input->getOption('code');
		$result = $this->attention->list(self::LIST_LIMIT, 0, is_string($code) && $code !== '' ? $code : null);

		if ($result['rows'] === []) {
			$output->writeln('<info>0 needing attention</info>');
			return Command::SUCCESS;
		}

		// A plain aligned table, not the Symfony Table helper: the app's
		// console dependency ships trimmed, and a hand-rolled layout is
		// one less thing to load on an occ call.
		$output->writeln(sprintf(
			'%-9s %-40s %-38s %-34s %8s %10s',
			'id',
			'path',
			'code',
			'label',
			'attempts',
			'age',
		));
		foreach ($result['rows'] as $row) {
			$output->writeln(sprintf(
				'%-9d %-40.40s %-38.38s %-34.34s %8d %10s',
				$row['id'],
				$row['path'],
				$row['code'],
				$row['label'],
				$row['attempts'],
				self::age($row['finished_at']),
			));
		}

		$total = $result['total'];
		$output->writeln('');
		$output->writeln(sprintf('<info>%d needing attention</info>', $total));
		if ($total > count($result['rows'])) {
			$output->writeln(sprintf('<comment>showing the newest %d of %d</comment>', count($result['rows']), $total));
		}
		return Command::SUCCESS;
	}

	/**
	 * A human age for a finished_at timestamp: "3d 4h", "17m", "now".
	 */
	private static function age(?int $finishedAt): string
	{
		if ($finishedAt === null) {
			return 'unknown';
		}
		$seconds = max(0, time() - $finishedAt);
		if ($seconds < 60) {
			return 'now';
		}
		if ($seconds < 3600) {
			return intdiv($seconds, 60) . 'm';
		}
		if ($seconds < 86400) {
			return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
		}
		return intdiv($seconds, 86400) . 'd ' . intdiv($seconds % 86400, 3600) . 'h';
	}
}
