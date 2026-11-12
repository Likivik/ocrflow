<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Command;

use OCA\OcrFlow\Service\Attention;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The operator escape hatch for the permanent-failure block.
 *
 * `Queue::enqueue()` refuses a file whose ledger row records a PERMANENT
 * failure for the current etag: the same bytes can never succeed, so
 * re-queueing them only re-bills the engine. That verdict is right until a
 * human says otherwise — a fixed engine, a changed policy, a misdiagnosis —
 * and "otherwise" needs a voice:
 *
 *   occ ocrflow:requeue alice/files/scan.pdf
 *
 * The retry itself lives in the Attention service, so the command and the
 * admin panel's Retry button are two front doors to one implementation.
 * The smallest version: one path, no --all — the panel's capped
 * "retry all" is the bulk door, and new uploads earn their own attempt
 * without help.
 */
class Requeue extends Command
{
	public function __construct(
		private Attention $attention,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('ocrflow:requeue')
			->setDescription('Clear the permanent-failure verdict of one file so the next scan retries it')
			->addArgument(
				'path',
				InputArgument::REQUIRED,
				'the file path as ocrflow recorded it, e.g. alice/files/scan.pdf (see the skip line in nextcloud.log)',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$path = trim((string) $input->getArgument('path'));
		if ($path === '') {
			$output->writeln('<error>path is required</error>');
			return Command::FAILURE;
		}

		$result = $this->attention->retryPath($path);

		if ($result['status'] === 'missing') {
			$output->writeln('<comment>no ocrflow record for this path; nothing to clear, and nothing blocking it either.</comment>');
			return Command::SUCCESS;
		}
		if ($result['status'] === 'done') {
			$output->writeln('<comment>this file is recorded as done, not failed; nothing to retry.</comment>');
			return Command::SUCCESS;
		}

		$output->writeln(sprintf(
			'cleared the failure verdict (%s) for %s; deleted %d terminal job row(s); the next scan will queue it again',
			$result['reason'] ?? 'no reason recorded',
			$path,
			$result['deleted'],
		));
		return Command::SUCCESS;
	}
}
