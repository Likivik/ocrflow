<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Command;

use OCA\OcrFlow\AppInfo\Application;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate (or show) the shared secret the worker authenticates with.
 *
 * The secret is generated once with 32 bytes of CSPRNG, stored in app config,
 * and printed exactly once for the operator to put into the container env. It is
 * never logged, never returned by an HTTP endpoint, and rotating it is the
 * deliberate `--rotate` action, which invalidates the running worker until its
 * env is updated.
 */
class WorkerSecret extends Command
{
	public function __construct(
		private IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('ocrflow:worker:secret')
			->setDescription('Print the shared secret the OCR worker authenticates with')
			->addOption('rotate', null, InputOption::VALUE_NONE, 'Generate a new secret, invalidating the current worker');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$secret = (string) $this->config->getAppValue(Application::APP_ID, 'worker_secret', '');

		if ($secret === '' || $input->getOption('rotate')) {
			$secret = bin2hex(random_bytes(32));
			$this->config->setAppValue(Application::APP_ID, 'worker_secret', $secret);
			$output->writeln('<info>New worker secret generated. Set it in the worker container as:</info>');
			$output->writeln('  OCRFLOW_WORKER_SECRET=' . $secret);
			$output->writeln('<comment>Update the container env now — the running worker is unauthorized until you do.</comment>');
			return Command::SUCCESS;
		}

		$output->writeln($secret);
		return Command::SUCCESS;
	}
}
