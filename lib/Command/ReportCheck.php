<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Command;

use OCA\OcrFlow\Report\PanelReport;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prove the aggregate report says what the id-list report says.
 *
 * The panel report was rewritten twice for the same reason: it counted files by
 * loading all of them. The rewrite is only worth anything if it produces the
 * same numbers, and "the same numbers" cannot mean "the same code" — an
 * implementation that shares its readers with the new one agrees with it by
 * construction, mistakes included. So this command carries its **own SQL**, the
 * old algorithm end to end, and compares.
 *
 *   occ ocrflow:report:check --compare            # every user with files
 *   occ ocrflow:report:check --compare --user bob
 *
 * Three runs per uid: legacy, aggregate, legacy. If the two legacy runs differ,
 * a worker settled something between them and the comparison proves nothing —
 * that is reported as UNSTABLE, not as a mismatch, and retried.
 *
 * Exit codes: 0 agree, 1 differ, 2 unstable after retries.
 */
class ReportCheck extends Command
{
	private const IN_CHUNK = 500;
	private const MAX_TRIES = 3;

	public function __construct(
		private IDBConnection $db,
		private PanelReport $report,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('ocrflow:report:check')
			->setDescription('Compare the aggregate panel report with the id-list arithmetic')
			->addOption('compare', null, InputOption::VALUE_NONE, 'run both implementations and compare (required)')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'only this uid')
			->addOption('all', null, InputOption::VALUE_NONE, 'every uid that has files (default when --user is absent)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$uid = $input->getOption('user');
		if (!is_string($uid) || $uid === '') {
			$uid = null;
		}

		$uids = $uid !== null ? [$uid] : $this->uidsWithFiles();
		if ($uids === []) {
			$output->writeln('<comment>ocrflow: no user has files; nothing to compare.</comment>');
			return Command::SUCCESS;
		}

		$mismatches = 0;
		$unstable = 0;

		foreach ($uids as $thisUid) {
			$result = $this->compareOne($thisUid);
			if ($result === null) {
				$unstable++;
				$output->writeln('<comment>' . $thisUid . ': UNSTABLE — the data changed under both runs; not compared.</comment>');
				continue;
			}
			[$legacy, $aggregate, $diffs] = $result;
			if ($diffs === []) {
				$output->writeln('<info>' . $thisUid . ': agree (' . $this->summary($aggregate) . ')</info>');
				continue;
			}
			$mismatches++;
			$output->writeln('<error>' . $thisUid . ': ' . count($diffs) . ' difference(s)</error>');
			foreach ($diffs as $field => $pair) {
				$output->writeln(sprintf('  %-24s legacy=%-8s aggregate=%-8s', $field, $this->show($pair[0]), $this->show($pair[1])));
			}
			$output->writeln('  raw legacy:    ' . json_encode($legacy));
			$output->writeln('  raw aggregate: ' . json_encode($aggregate));
		}

		if ($unstable > 0 && $mismatches === 0) {
			$output->writeln('<comment>' . $unstable . ' user(s) could not be compared while the queue was busy. Re-run when idle.</comment>');
			return 2;
		}
		if ($mismatches > 0) {
			$output->writeln('<error>' . $mismatches . ' user(s) disagree. The aggregate report is wrong until proven otherwise.</error>');
			return Command::FAILURE;
		}
		return Command::SUCCESS;
	}

	/**
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, array{0: mixed, 1: mixed}>}|null
	 *         null when the data moved mid-comparison
	 */
	private function compareOne(string $uid): ?array
	{
		for ($try = 0; $try < self::MAX_TRIES; $try++) {
			$before = $this->legacy($uid);
			$aggregate = $this->normalise($this->report->forUser($uid));
			$after = $this->legacy($uid);

			if ($this->comparable($before) !== $this->comparable($after)) {
				continue;
			}
			return [$before, $aggregate, $this->diff($this->comparable($before), $this->comparable($aggregate))];
		}
		return null;
	}

	/**
	 * The old algorithm, with its own SQL. Deliberately not built on
	 * `DiskFiles`/`PanelReport`: a second opinion that reuses the first opinion
	 * is not a second opinion.
	 *
	 * @return array<string, mixed>
	 */
	private function legacy(string $uid): array
	{
		$ids = [];
		$rows = $this->db->executeQuery(
			'SELECT f.fileid FROM oc_filecache f'
			. ' INNER JOIN oc_storages s ON s.numeric_id = f.storage'
			. " WHERE s.id = ? AND f.path LIKE 'files/%' AND f.size > 0"
			. " AND (LOWER(f.name) LIKE '%.pdf' OR LOWER(f.name) LIKE '%.jpg' OR LOWER(f.name) LIKE '%.jpeg'"
			. " OR LOWER(f.name) LIKE '%.png' OR LOWER(f.name) LIKE '%.webp' OR LOWER(f.name) LIKE '%.heic')",
			['home::' . $uid],
		)->fetchAll();
		foreach ($rows as $row) {
			$ids[(int) $row['fileid']] = 1;
		}
		$fileIds = array_keys($ids);

		$ledger = [];
		foreach (array_chunk($fileIds, self::IN_CHUNK) as $chunk) {
			$placeholders = implode(',', array_fill(0, count($chunk), '?'));
			$found = $this->db->executeQuery(
				'SELECT file_id, reason, engine, engine_version FROM oc_ocrflow_files WHERE file_id IN (' . $placeholders . ')',
				$chunk,
				array_fill(0, count($chunk), IQueryBuilder::PARAM_INT),
			)->fetchAll();
			foreach ($found as $row) {
				$ledger[(int) $row['file_id']] = $row;
			}
		}

		$live = [];
		foreach (array_chunk($fileIds, self::IN_CHUNK) as $chunk) {
			$placeholders = implode(',', array_fill(0, count($chunk), '?'));
			$found = $this->db->executeQuery(
				"SELECT file_id, status FROM oc_ocrflow_jobs WHERE status IN ('queued','claimed') AND file_id IN (" . $placeholders . ')',
				$chunk,
				array_fill(0, count($chunk), IQueryBuilder::PARAM_INT),
			)->fetchAll();
			foreach ($found as $row) {
				$fileId = (int) $row['file_id'];
				// A claim outranks a queued row — the rule the aggregate uses.
				if (($live[$fileId] ?? '') !== 'claimed') {
					$live[$fileId] = (string) $row['status'];
				}
			}
		}

		$queue = ['queued' => 0, 'claimed' => 0];
		$byCode = [];
		$read = [];
		$noText = [];
		$settled = 0;
		foreach ($ledger as $fileId => $row) {
			if (isset($live[$fileId])) {
				$queue[$live[$fileId]]++;
				continue;
			}
			$code = $row['reason'] === null ? '' : (string) $row['reason'];
			if ($code === PanelReport::GHOST_REASON) {
				continue;
			}
			$settled++;
			$byCode[$code] = ($byCode[$code] ?? 0) + 1;
			$engineKey = ($row['engine'] ?? '') . '|' . ($row['engine_version'] ?? '');
			if ($code === '' || $code === 'skip_ocred_by_ocrflow') {
				$read[$engineKey] = ($read[$engineKey] ?? 0) + 1;
			}
			if ($code === 'skip_ocred_by_ocrflow_found_nothing') {
				$noText[($row['engine'] ?? '') . '|'] = ($noText[($row['engine'] ?? '') . '|'] ?? 0) + 1;
			}
		}
		foreach ($live as $fileId => $status) {
			if (!isset($ledger[$fileId])) {
				$queue[$status]++;
			}
		}

		return $this->normalise([
			'total' => count($ids),
			'never_processed' => count($ids) - $settled - count($live),
			'empty' => $this->legacyScopeCount($uid, true),
			'unreadable_format' => $this->legacyScopeCount($uid, false),
			'queue' => $queue,
			'by_code' => $byCode,
			'read_by_engine' => $this->legacyEngineSplit($read),
			'no_text_by_engine' => $this->legacyEngineSplit($noText),
		]);
	}

	private function legacyScopeCount(string $uid, bool $empty): int
	{
		$sql = 'SELECT COUNT(*) FROM oc_filecache f INNER JOIN oc_storages s ON s.numeric_id = f.storage'
			. " WHERE s.id = ? AND f.path LIKE 'files/%'";
		$sql .= $empty
			? " AND f.size = 0 AND (LOWER(f.name) LIKE '%.pdf' OR LOWER(f.name) LIKE '%.jpg' OR LOWER(f.name) LIKE '%.jpeg'"
				. " OR LOWER(f.name) LIKE '%.png' OR LOWER(f.name) LIKE '%.webp' OR LOWER(f.name) LIKE '%.heic')"
			: " AND f.size > 0 AND (LOWER(f.name) LIKE '%.tif' OR LOWER(f.name) LIKE '%.tiff')";
		return (int) $this->db->executeQuery($sql, ['home::' . $uid])->fetchOne();
	}

	/**
	 * @param array<string, int> $counts
	 * @return list<array{engine: ?string, version: ?string, count: int}>
	 */
	private function legacyEngineSplit(array $counts): array
	{
		$out = [];
		foreach ($counts as $key => $count) {
			[$engine, $version] = explode('|', $key, 2);
			$out[] = ['engine' => $engine === '' ? null : $engine, 'version' => $version === '' ? null : $version, 'count' => $count];
		}
		usort($out, static function (array $a, array $b): int {
			// Count first, then the name: a tie broken by row order is a
			// difference that shows up on one database and not another.
			return ($b['count'] <=> $a['count'])
				?: ((string) ($a['engine'] ?? '') <=> (string) ($b['engine'] ?? ''))
				?: ((string) ($a['version'] ?? '') <=> (string) ($b['version'] ?? ''));
		});
		return $out;
	}

	/**
	 * Sort everything that has an order, so two implementations are compared on
	 * values and not on row order.
	 *
	 * @param array<string, mixed> $report
	 * @return array<string, mixed>
	 */
	private function normalise(array $report): array
	{
		// `===` on arrays is order-sensitive, and the two implementations build
		// their maps in different orders: the legacy one inserts `queued` first
		// because that is how the loop meets it, the aggregate one lists
		// `claimed` first because that is the precedence rule. Same numbers, and
		// that is what this command exists to say.
		ksort($report['by_code']);
		if (is_array($report['queue'] ?? null)) {
			ksort($report['queue']);
		}
		return $report;
	}

	/**
	 * @param array<string, mixed> $report
	 * @return array<string, mixed>
	 */
	private function comparable(array $report): array
	{
		// never_processed is the one field that can legitimately be clamped to 0
		// by the aggregate while the legacy arithmetic can go negative for a
		// moment during a settle. Compare everything else exactly, and this one
		// with the clamp applied to both sides.
		$report['never_processed'] = max(0, (int) $report['never_processed']);
		return $report;
	}

	/**
	 * @param array<string, mixed> $legacy
	 * @param array<string, mixed> $aggregate
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	private function diff(array $legacy, array $aggregate): array
	{
		$diffs = [];
		foreach ($legacy as $field => $value) {
			$other = $aggregate[$field] ?? null;
			if ($value !== $other) {
				$diffs[$field] = [$value, $other];
			}
		}
		return $diffs;
	}

	/**
	 * @return list<string>
	 */
	private function uidsWithFiles(): array
	{
		$out = [];
		$rows = $this->db->executeQuery("SELECT id FROM oc_storages WHERE id LIKE 'home::%' ORDER BY id")->fetchAll();
		foreach ($rows as $row) {
			$out[] = substr((string) $row['id'], strlen('home::'));
		}
		return $out;
	}

	private function summary(array $report): string
	{
		return sprintf(
			'total %d, never %d, queue %d, settled codes %d',
			(int) $report['total'],
			(int) $report['never_processed'],
			(int) $report['queue']['queued'] + (int) $report['queue']['claimed'],
			count($report['by_code']),
		);
	}

	private function show(mixed $value): string
	{
		return is_scalar($value) || $value === null ? (string) json_encode($value) : substr((string) json_encode($value), 0, 60);
	}
}
