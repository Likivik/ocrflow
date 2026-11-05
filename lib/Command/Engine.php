<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Command;

use OCA\OcrFlow\Service\EnginePolicy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show or set the OCR engine.
 *
 * It is a command rather than a config key in the docs because the value decides
 * whether page images of users' documents leave the server. An administrator
 * should say so once, deliberately, and be able to ask what the instance is
 * currently doing.
 *
 *   occ ocrflow:engine                # what is configured now
 *   occ ocrflow:engine tesseract      # offline, no third party
 *   occ ocrflow:engine none           # stop queueing work
 */
class Engine extends Command
{
	public function __construct(
		private EnginePolicy $policy,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('ocrflow:engine')
			->setDescription('Show or set the OCR engine (tesseract, google, minimax, none)')
			->addArgument(
				'engine',
				InputArgument::OPTIONAL,
				'tesseract (offline), google (Cloud Vision), minimax (API), or none to stop queueing',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$requested = $input->getArgument('engine');

		if ($requested === null) {
			$current = $this->policy->current();
			$output->writeln('engine: ' . $current);
			if ($current === EnginePolicy::NONE) {
				$output->writeln('<comment>No engine chosen: uploads are not queued and folder scans are skipped.</comment>');
				$output->writeln('  occ ocrflow:engine tesseract   # offline');
				$output->writeln('  occ ocrflow:engine google      # sends page images to Google Cloud Vision');
			}
			return Command::SUCCESS;
		}

		$engine = strtolower(trim((string) $requested));
		if ($engine !== EnginePolicy::NONE && !in_array($engine, EnginePolicy::ENGINES, true)) {
			$output->writeln(sprintf(
				'<error>Unknown engine "%s". Choose one of: %s, or none.</error>',
				$engine,
				implode(', ', EnginePolicy::ENGINES),
			));
			return Command::FAILURE;
		}

		$this->policy->set($engine);
		$output->writeln('engine: ' . $engine);

		if ($engine === 'google') {
			$output->writeln('<comment>google sends a page image of every document to Google Cloud Vision.</comment>');
		}
		if ($engine === EnginePolicy::NONE) {
			$output->writeln('<comment>Nothing will be queued. Jobs already queued keep the engine they were stamped with.</comment>');
		}

		return Command::SUCCESS;
	}
}
