<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Command;

use OCA\OcrFlow\Service\Outcomes as OutcomesService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The outcomes tabs on the terminal.
 *
 *   occ ocrflow:outcomes --verdict=redo
 *
 * Reads the SAME Outcomes service the admin panel and
 * `GET /api/v1/outcomes` read, so the three doors cannot disagree about
 * whose work it is — one reader, no command-side reimplementation of the
 * tab mapping. `occ ocrflow:errors` stays the needs_fix worklist door it
 * always was; this command is every tab.
 *
 * The row lines start with the origin and the row id, so a script (and
 * the identical-ids test) can compare the command's answer with the
 * endpoint's, row for row.
 */
class Outcomes extends Command
{
	private const LIST_LIMIT = 500;

	public function __construct(
		private OutcomesService $outcomes,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('ocrflow:outcomes')
			->setDescription('List the outcome rows of one verdict: needs_fix, redo, wait, settled or dropped')
			->addOption(
				'verdict',
				null,
				InputOption::VALUE_REQUIRED,
				'the tab to list: needs_fix, redo, wait, settled, dropped (default: redo)',
				'redo',
			)
			->addOption(
				'code',
				null,
				InputOption::VALUE_REQUIRED,
				'show only rows with this reason code (e.g. skip_ocred_by_suspected_foreign)',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$verdict = (string) $input->getOption('verdict');
		if (!in_array($verdict, OutcomesService::TABS, true)) {
			$output->writeln('<error>unknown verdict "' . $verdict . '"; expected one of: ' . implode(', ', OutcomesService::TABS) . '</error>');
			return Command::FAILURE;
		}

		$code = $input->getOption('code');
		$result = $this->outcomes->list($verdict, self::LIST_LIMIT, 0, is_string($code) && $code !== '' ? $code : null);

		if ($result['rows'] === []) {
			$output->writeln('<info>0 ' . $verdict . '</info>');
			return Command::SUCCESS;
		}

		// A plain aligned table, not the Symfony Table helper: the app's
		// console dependency ships trimmed, and a hand-rolled layout is
		// one less thing to load on an occ call.
		$output->writeln(sprintf(
			'%-7s %-9s %-40s %-38s %-10s %6s %10s',
			'origin',
			'id',
			'path',
			'code',
			'engine',
			'pages',
			'age',
		));
		foreach ($result['rows'] as $row) {
			$engine = ($row['engine'] ?? null) !== null
				? $row['engine'] . (($row['engine_version'] ?? null) !== null ? ' ' . $row['engine_version'] : '')
				: '-';
			$output->writeln(sprintf(
				'%-7s %-9d %-40.40s %-38.38s %-10.10s %6s %10s',
				$row['origin'],
				(int) $row['id'],
				$row['path'],
				$row['code'],
				$engine,
				($row['pages'] ?? null) !== null ? (string) (int) $row['pages'] : '-',
				self::age($row['finished_at'] ?? null),
			));
		}

		$total = $result['total'];
		$output->writeln('');
		$output->writeln(sprintf('<info>%d %s</info>', $total, $verdict));
		if ($total > count($result['rows'])) {
			$output->writeln(sprintf('<comment>showing the newest %d of %d</comment>', count($result['rows']), $total));
		}
		if ($verdict === 'wait') {
			$output->writeln('<comment>wait rows are terminal: MAX_ATTEMPTS is spent and nothing retries them automatically</comment>');
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
