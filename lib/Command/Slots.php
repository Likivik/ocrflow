<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Command;

use OCA\OcrFlow\Service\SlotsPolicy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show or set how many OCR jobs the worker runs at once.
 *
 * The worker re-reads this between jobs, so a change lands without a restart
 * and without a rebuild. A retired slot finishes the file it holds and exits:
 * resizing never drops a claim, so nothing is redone.
 *
 *   occ ocrflow:slots        # what it is now, and what the host can hold
 *   occ ocrflow:slots 3      # run three at a time
 *   occ ocrflow:slots 2      # back to the default after a batch
 *
 * Nothing clamps the number. Each slot holds roughly 600 MB of peak memory on
 * a scanned document, so the honest advice is arithmetic, not a limit: 4 GB
 * free carries two comfortably and three tightly.
 */
class Slots extends Command
{
	public function __construct(
		private SlotsPolicy $policy,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('ocrflow:slots')
			->setDescription('Show or set how many OCR jobs the worker runs at once')
			->addArgument(
				'slots',
				InputArgument::OPTIONAL,
				'number of concurrent jobs (the worker re-reads it between jobs)',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$requested = $input->getArgument('slots');

		if ($requested === null) {
			$current = $this->policy->current();
			$output->writeln('slots: ' . $current . ($this->policy->isSet() ? '' : ' (default)'));
			$output->writeln('  occ ocrflow:slots 3   # three at a time takes effect without a restart');
			return Command::SUCCESS;
		}

		if (!ctype_digit((string) $requested)) {
			$output->writeln(sprintf('<error>"%s" is not a whole number of slots.</error>', $requested));
			return Command::FAILURE;
		}

		$slots = (int) $requested;
		if (!$this->policy->set($slots)) {
			$output->writeln('<error>slots must be at least 1; a worker with zero slots only looks busy.</error>');
			return Command::FAILURE;
		}

		$output->writeln('slots: ' . $slots);
		$output->writeln('  the worker resizes on its next idle poll; nothing in flight is dropped');
		return Command::SUCCESS;
	}
}
