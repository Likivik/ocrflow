<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Command;

use OCA\OcrFlow\Db\Queue;
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
 * It clears the verdict and deletes the file's terminal job rows (the same
 * rows retention would delete on schedule), so the next scan queues the file
 * again. The smallest version: one path, no --all, no etag handling — the
 * new upload path already earns its own attempt without help.
 */
class Requeue extends Command
{
	public function __construct(
		private Queue $queue,
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

		$file = $this->queue->findFileByPath($path);
		if ($file === null) {
			$output->writeln('<comment>no ocrflow record for this path; nothing to clear, and nothing blocking it either.</comment>');
			return Command::SUCCESS;
		}
		if ($file['status'] === Queue::STATUS_DONE) {
			$output->writeln('<comment>this file is recorded as done, not failed; nothing to retry.</comment>');
			return Command::SUCCESS;
		}

		$deleted = $this->queue->deleteTerminalForFile($file['file_id']);
		$this->queue->clearPermanentVerdict($file['file_id']);

		$output->writeln(sprintf(
			'cleared the failure verdict (%s) for %s; deleted %d terminal job row(s); the next scan will queue it again',
			$file['reason'] ?? 'no reason recorded',
			$path,
			$deleted,
		));
		return Command::SUCCESS;
	}
}
