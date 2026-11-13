<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Db\Queue;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * THE outcomes reader: the one service behind the five admin tabs, the
 * `GET /api/v1/outcomes` endpoint and `occ ocrflow:outcomes`. No tab, no
 * endpoint and no command may query an origin on its own — they all come
 * here, so they cannot disagree about whose work it is.
 *
 * Three origins, five tabs:
 *
 *   jobs           needs_fix (Needs you), wait (Waiting), drop (Dropped).
 *                  Read through Attention::list(), generalised by verdict —
 *                  the SAME query `GET /attention` and `occ ocrflow:errors`
 *                  ride, not a second implementation.
 *   ledger         redo (Decide): the redo codes and the below-bar stamps;
 *                  settled (Settled): the nothing_to_do codes and the done
 *                  rows, done counted separately. The bar decides between
 *                  Decide and Settled at READ time, against the bar that is
 *                  current now — a row is never rewritten with a verdict.
 *   pending_results redo (Decide): a stashed result the owner must decide
 *                  on. While the stash is pending it suppresses its twin
 *                  ledger row, so one conflict is one row, not two.
 *
 * Ledger rows whose code belongs to the jobs verdicts (needs_fix, wait,
 * drop) are NOT listed here: their job row is the listed one, and listing
 * both would double-count one file. Jobs in flight (queued, claimed) have
 * no verdict yet and appear nowhere.
 *
 * The ledger is read whole and classified in PHP, not in SQL, because the
 * Decide/Settled split for `skip_ocred_by_ocrflow` rows is the engine bar —
 * a config value compared with numeric version semantics SQL cannot express
 * portably. The ledger is one row per file the app has ever decided on, so
 * the scan cost is bounded by the file count, not by the job history.
 */
class Outcomes
{
	/** The five tabs, in panel order. */
	public const TABS = [
		'needs_fix',
		'redo',
		'wait',
		'settled',
		'dropped',
	];

	private const LEDGER = 'ocrflow_files';
	private const PENDING = 'ocrflow_pending_results';

	/** Config key: what one thousand re-OCR'd pages cost, for the honest estimate. */
	public const PRICE_PER_1000_KEY = 'redo_cost_per_1000_pages';

	public function __construct(
		private Attention $attention,
		private RetryPolicy $retryPolicy,
		private EnginePolicy $enginePolicy,
		private PendingResults $pendingResults,
		private IConfig $config,
		private ?IDBConnection $db = null,
		private ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * One tab: its rows, newest first, and the row count after
	 * classification. An unknown verdict lists nothing — it must not fall
	 * back to some other tab's rows.
	 *
	 * `$pathPrefix` narrows every origin to one user's files
	 * (`/alice/files/`), which is how the personal panel reads its own
	 * Decide rows. Same prefix, same authority as Attention::list(): the
	 * caller passes what the authenticated uid resolves to, never a
	 * request parameter.
	 *
	 * @param list<string> $ids file ids (the redo selection)
	 * @return array{rows: list<array<string, mixed>>, total: int, cap: int}
	 */
	public function list(string $verdict, int $limit, int $offset = 0, ?string $code = null, ?string $pathPrefix = null): array
	{
		if (!in_array($verdict, self::TABS, true)) {
			return ['rows' => [], 'total' => 0, 'cap' => $this->attention->cap()];
		}

		// The jobs verdict RetryPolicy spells 'drop'; the tab is "Dropped".
		$jobsVerdict = ['needs_fix' => RetryPolicy::VERDICT_NEEDS_FIX, 'wait' => RetryPolicy::VERDICT_WAIT, 'dropped' => RetryPolicy::VERDICT_DROP][$verdict] ?? null;
		if ($jobsVerdict !== null) {
			// The jobs tabs ride Attention::list() — one query for all doors.
			$result = $this->attention->list($limit, $offset, $code, $jobsVerdict, $pathPrefix);
			foreach ($result['rows'] as &$row) {
				$row['origin'] = 'job';
			}
			return $result;
		}

		$rows = $this->classified($verdict, $code, $pathPrefix);
		$total = count($rows);
		if ($offset >= $total || $limit <= 0) {
			return ['rows' => [], 'total' => $total, 'cap' => $this->attention->cap()];
		}
		return [
			'rows' => array_slice($rows, $offset, $limit),
			'total' => $total,
			'cap' => $this->attention->cap(),
		];
	}

	/**
	 * The tab badges: one count per tab, plus `done` counted separately
	 * inside Settled (done rows are the app's own successful writes; the
	 * other settled rows are decisions to leave a file alone).
	 *
	 * @return array{needs_fix: int, redo: int, wait: int, settled: int, dropped: int, done: int}
	 */
	public function summary(): array
	{
		$settled = $this->classified('settled');
		return [
			'needs_fix' => $this->attention->countForVerdict(RetryPolicy::VERDICT_NEEDS_FIX),
			'redo' => count($this->classified(RetryPolicy::VERDICT_REDO)),
			'wait' => $this->attention->countForVerdict(RetryPolicy::VERDICT_WAIT),
			'settled' => count($settled),
			'dropped' => $this->attention->countForVerdict(RetryPolicy::VERDICT_DROP),
			'done' => count(array_filter($settled, static fn (array $row): bool => (bool) $row['done'])),
		];
	}

	/**
	 * The honest estimate for a redo selection, computed BEFORE any money
	 * is spent: how many files, how many pages (only where a row knows its
	 * page count — the ledger does not), and what that costs. The cost
	 * exists only when a price is configured AND every selected row knows
	 * its pages; otherwise it is null and the panel says "unknown", never a
	 * fabricated number.
	 *
	 * @param list<int> $fileIds
	 * @return array{files: int, pages: int, pages_known: bool, cost: ?float}
	 */
	public function estimate(array $fileIds, ?string $code): array
	{
		return $this->estimateFor($this->selectedRedoRows($fileIds, $code));
	}

	/**
	 * Re-OCR a selection of Decide rows, by file ids or by code, capped by
	 * the SAME cap the attention retry-all uses. One click can never
	 * re-bill more than the cap, whatever the selection named. The answer
	 * carries the estimate of what it actually spent.
	 *
	 * @param list<int> $fileIds
	 * @return array{retried: int, cap: int, estimate: array{files: int, pages: int, pages_known: bool, cost: ?float}}
	 */
	public function redo(array $fileIds, ?string $code): array
	{
		$rows = $fileIds === [] && ($code === null || $code === '')
			? []
			: $this->selectedRedoRows($fileIds, $code);

		$cap = $this->attention->cap();
		$spend = array_slice($rows, 0, $cap);
		foreach ($spend as $row) {
			$this->attention->retryByFileId((int) $row['file_id'], (string) $row['path'], 'outcomes');
		}
		return [
			'retried' => count($spend),
			'cap' => $cap,
			'estimate' => $this->estimateFor($spend),
		];
	}

	/**
	 * Retry ONE Decide row on behalf of its owner.
	 *
	 * A Decide row names a FILE (the ledger and the stash are per-file), so
	 * this resolves the row id back to its file and re-checks the stored
	 * path against `$pathPrefix` before spending anything. A stashed result
	 * cannot be retried at all — it has to be applied or discarded — so it
	 * is refused rather than silently re-billed. RETRY_MISSING means "no
	 * such Decide row", which the caller may use to say "no such row"
	 * without revealing which ids exist.
	 */
	public function retryRedoRow(int $rowId, string $pathPrefix): string
	{
		foreach ($this->classified(RetryPolicy::VERDICT_REDO) as $row) {
			if ((int) ($row['id'] ?? 0) !== $rowId) {
				continue;
			}
			if (!UserScope::owns((string) ($row['path'] ?? ''), $pathPrefix)) {
				$this->logger?->warning('ocrflow: mine: decide row ' . $rowId . ' belongs to another user\'s files; retry refused');
				return Attention::RETRY_REFUSED;
			}
			if (($row['origin'] ?? '') === 'stash') {
				return Attention::RETRY_REFUSED;
			}
			$this->attention->retryByFileId((int) $row['file_id'], (string) $row['path'], 'mine');
			return Attention::RETRY_DONE;
		}
		return Attention::RETRY_MISSING;
	}

	/**
	 * Apply a stashed pending result to its file. Refuses — with the
	 * warning a human reads — when versioning is unavailable (the previous
	 * content would be unrecoverable) or the file is gone.
	 *
	 * @return array{status: string, warning: ?string}
	 */
	public function apply(int $id): array
	{
		return $this->pendingResults->apply($id);
	}

	/**
	 * Discard a stashed pending result: the artifact bytes are deleted and
	 * the row stays as the audit trail.
	 *
	 * @return array{status: string, warning: ?string}
	 */
	public function discard(int $id): array
	{
		return $this->pendingResults->discard($id);
	}

	/**
	 * The code → label filter options for one tab, from the vocabulary.
	 *
	 * @return array<string, string>
	 */
	public function codesFor(string $verdict): array
	{
		$v = ['settled' => RetryPolicy::VERDICT_NOTHING_TO_DO, 'dropped' => RetryPolicy::VERDICT_DROP][$verdict] ?? $verdict;
		$out = [];
		foreach ($this->retryPolicy->codesForVerdict($v) as $code) {
			$entry = $this->retryPolicy->entry($code);
			if ($entry !== null) {
				$out[$code] = $entry['label'];
			}
		}
		return $out;
	}

	/**
	 * The price the panel multiplies the page count by, so "~$X" can be
	 * shown BEFORE the click. Null when no honest price is configured.
	 *
	 * @return array{per_1000_pages: ?float}
	 */
	public function pricing(): array
	{
		return ['per_1000_pages' => $this->price()];
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array{files: int, pages: int, pages_known: bool, cost: ?float}
	 */
	private function estimateFor(array $rows): array
	{
		$pages = 0;
		$pagesKnown = $rows !== [];
		foreach ($rows as $row) {
			$pages += (int) ($row['pages'] ?? 0);
			if (($row['pages'] ?? null) === null) {
				$pagesKnown = false;
			}
		}
		$price = $this->price();
		return [
			'files' => count($rows),
			'pages' => $pages,
			'pages_known' => $pagesKnown,
			'cost' => ($pagesKnown && $price !== null) ? round($pages * $price / 1000, 4) : null,
		];
	}

	private function price(): ?float
	{
		$raw = trim((string) $this->config->getAppValue(Application::APP_ID, self::PRICE_PER_1000_KEY, ''));
		if ($raw === '' || !is_numeric($raw)) {
			return null;
		}
		$price = (float) $raw;
		return $price > 0 ? $price : null;
	}

	/**
	 * The redo rows a selection names: by file id, or every row carrying
	 * the code. Order stays the tab's order (newest first), so the cap
	 * spends the newest first.
	 *
	 * @param list<int> $fileIds
	 * @return list<array<string, mixed>>
	 */
	private function selectedRedoRows(array $fileIds, ?string $code): array
	{
		$rows = $this->classified(RetryPolicy::VERDICT_REDO);
		if ($code !== null && $code !== '') {
			return array_values(array_filter(
				$rows,
				fn (array $row): bool => ($row['code'] ?? null) === $code
					|| in_array($row['code'] ?? null, $this->retryPolicy->spellingsOf($code), true),
			));
		}
		return array_values(array_filter(
			$rows,
			static fn (array $row): bool => in_array((int) ($row['file_id'] ?? 0), $fileIds, true),
		));
	}

	/**
	 * The rows of one ledger/stash tab, classified and newest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function classified(string $verdict, ?string $code = null, ?string $pathPrefix = null): array
	{
		$ledgerRows = $this->fetchLedgerRows();

		$paths = [];
		foreach ($ledgerRows as $row) {
			$paths[(int) ($row['file_id'] ?? 0)] = (string) ($row['path'] ?? '');
		}

		$stashed = [];
		foreach ($this->fetchPendingRows() as $row) {
			if (($row['status'] ?? null) === 'pending') {
				$stashed[(int) ($row['file_id'] ?? 0)] = $row;
			}
		}

		$settledVerdict = $verdict === 'settled';
		$rows = [];
		foreach ($ledgerRows as $row) {
			$mapped = $this->classifyLedgerRow($row, $stashed);
			if ($mapped === null || ($settledVerdict ? ($mapped['verdict'] ?? null) !== 'settled' : ($mapped['verdict'] ?? null) !== RetryPolicy::VERDICT_REDO)) {
				continue;
			}
			if ($code !== null && $code !== '' && !in_array($mapped['code'], $this->retryPolicy->spellingsOf($code), true)) {
				continue;
			}
			$rows[] = $mapped;
		}

		if (!$settledVerdict) {
			// The stash rows are Decide rows of their own: the pending
			// artifact, its engine facts and its page count. The path is
			// the file's path in the ledger — the pending table has none.
			foreach ($stashed as $fileId => $stash) {
				$stashRow = $this->stashRow($stash, $paths[$fileId] ?? '');
				if ($code !== null && $code !== '' && !in_array($stashRow['code'], $this->retryPolicy->spellingsOf($code), true)) {
					continue;
				}
				$rows[] = $stashRow;
			}
		}

		usort($rows, static function (array $a, array $b): int {
			$fa = (int) ($a['finished_at'] ?? 0);
			$fb = (int) ($b['finished_at'] ?? 0);
			$idA = (int) ($a['id'] ?? 0);
			$idB = (int) ($b['id'] ?? 0);
			if ($fa === $fb && $a['origin'] === $b['origin']) {
				return $idB <=> $idA;
			}
			return $fb <=> $fa;
		});

		// The scope filter is applied to the MAPPED rows, because that is
		// where every origin's path has landed on one field: the ledger
		// owns one, and a stash borrows its file's. One predicate, one
		// answer, whichever table the row came from.
		if ($pathPrefix !== null && $pathPrefix !== '') {
			$rows = array_values(array_filter(
				$rows,
				static fn (array $row): bool => UserScope::owns((string) ($row['path'] ?? ''), $pathPrefix),
			));
		}
		return $rows;
	}

	/**
	 * One ledger row → one tab row, or null when the row belongs to no tab
	 * here (its job row is the listed one, or it is in flight).
	 *
	 * @param array<string, mixed> $row
	 * @param array<int, array<string, mixed>> $stashed
	 * @return array<string, mixed>|null
	 */
	private function classifyLedgerRow(array $row, array $stashed): ?array
	{
		$code = $row['reason'] ?? null;
		if (!is_string($code) || $code === '') {
			// A ledger row without a code is a done row: a successful write
			// of ours. Settled, and counted separately.
			if (($row['status'] ?? null) === Queue::STATUS_DONE) {
				return $this->tabRow($row, 'settled', done: true);
			}
			return null;
		}
		$canonical = $this->retryPolicy->canonical($code);
		$verdict = $this->retryPolicy->verdict($canonical);

		// skip_ocred_by_ocrflow rows carry FACTS; the bar decides the tab at
		// read time, so the same row moves when the bar moves.
		if ($canonical === 'skip_ocred_by_ocrflow') {
			// A stamp with NO engine was written before provenance was
			// recorded (before the engine column existed). Missing facts
			// are never a reason to spend: it cannot be compared to the
			// bar, so it is NOT a redo candidate — Settled, provenance
			// unknown, with the note saying so. Only a KNOWN engine below
			// the bar goes to Decide.
			$engine = is_string($row['engine'] ?? null) && $row['engine'] !== '' ? $row['engine'] : null;
			if ($engine === null) {
				return $this->tabRow(
					$row,
					'settled',
					done: false,
					note: 'engine unknown: stamped before provenance was recorded',
				);
			}
			$verdict = $this->enginePolicy->stampVerdict(
				$engine,
				is_string($row['engine_version'] ?? null) && $row['engine_version'] !== '' ? $row['engine_version'] : null,
			) === RetryPolicy::VERDICT_NOTHING_TO_DO
				? 'settled'
				: RetryPolicy::VERDICT_REDO;
		}

		if ($verdict === RetryPolicy::VERDICT_REDO) {
			// The stash twin decides this file while it is pending: one
			// conflict, one row.
			if (array_key_exists((int) ($row['file_id'] ?? 0), $stashed)) {
				return null;
			}
			return $this->tabRow($row, RetryPolicy::VERDICT_REDO);
		}
		if ($verdict === RetryPolicy::VERDICT_NOTHING_TO_DO || $verdict === 'settled') {
			return $this->tabRow($row, 'settled', done: false);
		}
		// needs_fix / wait / drop / work: the job row is the listed one.
		return null;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function tabRow(array $row, string $verdict, bool $done = false, ?string $note = null): array
	{
		$canonical = $this->retryPolicy->canonical((string) ($row['reason'] ?? ''));
		$entry = $this->retryPolicy->entry($canonical);
		return [
			'id' => (int) ($row['id'] ?? 0),
			'origin' => 'ledger',
			'file_id' => (int) ($row['file_id'] ?? 0),
			'path' => (string) ($row['path'] ?? ''),
			'code' => $canonical === '' ? 'done' : $canonical,
			'label' => $entry['label'] ?? 'done',
			'fix' => $entry['fix'] ?? '',
			'error' => null,
			'attempts' => null,
			'finished_at' => ($row['updated_at'] ?? null) !== null ? (int) $row['updated_at'] : null,
			'engine' => ($row['engine'] ?? null) !== null && $row['engine'] !== '' ? (string) $row['engine'] : null,
			'engine_version' => ($row['engine_version'] ?? null) !== null && $row['engine_version'] !== '' ? (string) $row['engine_version'] : null,
			'pages' => null,
			'etag' => ($row['etag'] ?? null) !== null ? (string) $row['etag'] : null,
			'pending' => false,
			'done' => $done,
			'note' => $note,
			'verdict' => $verdict,
		];
	}

	/**
	 * @param array<string, mixed> $stash
	 * @return array<string, mixed>
	 */
	private function stashRow(array $stash, string $path): array
	{
		$canonical = 'skip_source_changed_result_saved';
		$entry = $this->retryPolicy->entry($canonical);
		return [
			'id' => (int) ($stash['id'] ?? 0),
			'origin' => 'stash',
			'file_id' => (int) ($stash['file_id'] ?? 0),
			'path' => $path,
			'code' => 'skip_source_changed_result_saved',
			'label' => $entry['label'] ?? 'Stashed result',
			'fix' => $entry['fix'] ?? 'Apply or discard the stashed result',
			'error' => null,
			'attempts' => null,
			'finished_at' => ($stash['created_at'] ?? null) !== null ? (int) $stash['created_at'] : null,
			'engine' => ($stash['engine'] ?? null) !== null && $stash['engine'] !== '' ? (string) $stash['engine'] : null,
			'engine_version' => ($stash['engine_version'] ?? null) !== null && $stash['engine_version'] !== '' ? (string) $stash['engine_version'] : null,
			'pages' => ($stash['pages'] ?? null) !== null ? (int) $stash['pages'] : null,
			'etag' => ($stash['etag'] ?? null) !== null ? (string) $stash['etag'] : null,
			'pending' => true,
			'done' => false,
			'verdict' => RetryPolicy::VERDICT_REDO,
		];
	}

	/**
	 * The whole ledger, one row per decided file. Classification happens in
	 * the reader, not in SQL: the Decide/Settled split is the engine bar,
	 * which is config compared with numeric version semantics.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function fetchLedgerRows(): array
	{
		if ($this->db === null) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'path', 'status', 'reason', 'engine', 'engine_version', 'etag', 'updated_at')
			->from(self::LEDGER);
		return $qb->executeQuery()->fetchAll();
	}

	/**
	 * The pending stashes, status = pending. Applied and discarded rows are
	 * history, not decisions waiting to be made.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function fetchPendingRows(): array
	{
		if ($this->db === null) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'etag', 'artifact_path', 'engine', 'engine_version', 'pages', 'created_at', 'status')
			->from(self::PENDING)
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		return $qb->executeQuery()->fetchAll();
	}
}
