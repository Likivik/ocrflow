<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Command;

use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Service\RetryPolicy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-OCR files the pipeline already decided about — the terminal door.
 *
 * The admin panel's "Rescan anyway" is a browser action; this is the same
 * decision without a session. It exists because the operator's bulk work is
 * not a click: 2,480 files carry a pre-worker OCRmyPDF layer and each one is
 * a row the worker already skipped.
 *
 *   occ ocrflow:rescan --code skip_ocred_by_suspected_foreign --limit 200
 *   occ ocrflow:rescan --ids 4211,4183
 *
 * The re-queue itself lives in `Queue::forceRequeue()`, so this command and
 * the panel are two front doors to one implementation, and the worker's force
 * bit is set the same way in both.
 *
 * Sized for the job, not for comfort: `--limit` defaults to 200 because each
 * row costs one engine run per page. `--dry-run` prints the selection without
 * spending anything.
 */
class Rescan extends Command
{
	public function __construct(
		private JobStore $jobs,
		private RetryPolicy $retryPolicy,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('ocrflow:rescan')
			->setDescription('Re-OCR settled jobs with force set, by reason code or by id')
			->addOption('code', null, InputOption::VALUE_REQUIRED, 'reason_code to select, e.g. skip_ocred_by_suspected_foreign')
			->addOption('ids', null, InputOption::VALUE_REQUIRED, 'comma-separated job ids')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'maximum rows to re-queue (default 200)', '200')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'print what would be re-queued, spend nothing');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$ids = $this->ids((string) $input->getOption('ids'));
		$code = trim((string) $input->getOption('code'));
		$limit = max(1, (int) $input->getOption('limit'));

		if ($ids === [] && $code === '') {
			$output->writeln('<error>--ids or --code is required</error>');
			return Command::FAILURE;
		}
		if ($ids === [] && !$this->retryPolicy->isKnown($code)) {
			$output->writeln('<error>unknown reason code: ' . $code . '</error>');
			return Command::FAILURE;
		}

		if ($ids === []) {
			$ids = $this->jobs->idsWithReasonCode($code, $limit);
			if ($ids === []) {
				$output->writeln('<comment>nothing to rescan for ' . $code . '</comment>');
				return Command::SUCCESS;
			}
		}
		$ids = array_slice($ids, 0, $limit);

		if ($input->getOption('dry-run')) {
			$output->writeln('<comment>would re-queue ' . count($ids) . ' job(s): '
				. implode(',', $ids) . '</comment>');
			return Command::SUCCESS;
		}

		$result = $this->jobs->forceRequeue($ids);
		$output->writeln('requeued ' . count($result['requeued'])
			. ', refused ' . count($result['refused']));
		if ($result['refused'] !== []) {
			// A refusal is not a failure: a queued or claimed row belongs to
			// somebody else's work in flight. Say which, so a second run does
			// not look like it did nothing.
			$output->writeln('<comment>refused (not done/skipped/error): '
				. implode(',', $result['refused']) . '</comment>');
		}

		return Command::SUCCESS;
	}

	/**
	 * @return int[]
	 */
	private function ids(string $raw): array
	{
		$out = [];
		foreach (explode(',', $raw) as $part) {
			$part = trim($part);
			if ($part !== '' && ctype_digit($part)) {
				$out[] = (int) $part;
			}
		}
		return array_values(array_unique($out));
	}
}
