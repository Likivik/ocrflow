<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use Psr\Log\LoggerInterface;

/**
 * One user's own attention rows: the needs_fix and Decide rows whose path is
 * under `/<uid>/files/`.
 *
 * The admin plane (AttentionController, OutcomesController) refuses every
 * non-admin, because those listings name other people's documents. That
 * refusal is right, and it left a normal user with instance-wide counts and
 * no way to see or retry their OWN failed files. This service is the
 * user-scoped reader and writer behind `GET /api/v1/mine` and
 * `POST /api/v1/mine/retry`.
 *
 * The scope is derived here, from the authenticated uid, and then applied by
 * the two readers it delegates to (Attention::list, Outcomes::list) — the
 * same query the admin pages run, narrowed, not a second implementation that
 * could drift. Every row is checked again on the way out, and every retry is
 * checked again before it runs: a listing and a retry can never disagree
 * about whose row it is.
 */
class Mine
{
	/** Never build more rows than this for one user in one request. */
	private const MAX_ROWS = 200;

	public function __construct(
		private Attention $attention,
		private Outcomes $outcomes,
		private ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * The caller's own rows: needs_fix (a job that needs a human) and redo
	 * (a file worth re-OCRing), newest first.
	 *
	 * @return array{rows: list<array<string, mixed>>, total: int, cap: int}
	 */
	public function list(string $uid, int $limit, int $offset = 0): array
	{
		$prefix = UserScope::prefixFor($uid);
		if ($prefix === '') {
			return ['rows' => [], 'total' => 0, 'cap' => $this->attention->cap()];
		}

		$limit = max(1, $limit);
		$offset = max(0, $offset);
		// Both origins are asked for enough rows to page the merged list;
		// neither is asked for the whole archive.
		$span = min($limit + $offset, self::MAX_ROWS);

		$rows = [];
		foreach ($this->attention->list($span, 0, null, RetryPolicy::VERDICT_NEEDS_FIX, $prefix)['rows'] as $row) {
			$rows[] = $this->row($row, 'job', true);
		}
		foreach ($this->outcomes->list('redo', $span, 0, null, $prefix)['rows'] as $row) {
			$origin = is_string($row['origin'] ?? null) ? $row['origin'] : 'ledger';
			// A stashed result is a decision (apply / discard), not a retry:
			// the button would re-bill a file whose conflict is still open.
			$rows[] = $this->row($row, $origin, $origin !== 'stash');
		}

		$rows = array_values(array_filter(
			$rows,
			static fn (array $row): bool => UserScope::owns((string) $row['path'], $prefix),
		));

		usort($rows, static function (array $a, array $b): int {
			$fa = (int) ($a['finished_at'] ?? 0);
			$fb = (int) ($b['finished_at'] ?? 0);
			if ($fa === $fb) {
				return (int) $b['id'] <=> (int) $a['id'];
			}
			return $fb <=> $fa;
		});

		$total = count($rows);
		return [
			'rows' => array_slice($rows, $offset, $limit),
			'total' => $total,
			'cap' => $this->attention->cap(),
		];
	}

	/**
	 * Retry the ids the caller named — the ones that are theirs, and only
	 * those. An id resolves to a job first (needs_fix) and then to a Decide
	 * row (redo); either way the STORED path decides whether the retry runs.
	 *
	 * Refusals are counted and returned, never silently skipped: "0 retried"
	 * with no explanation reads like a broken button, and a refusal the user
	 * cannot see is a refusal they will retry forever.
	 *
	 * @param list<mixed> $ids
	 * @return array{retried: int, refused: int, refused_ids: list<int>, cap: int}
	 */
	public function retry(string $uid, array $ids): array
	{
		$result = ['retried' => 0, 'refused' => 0, 'refused_ids' => [], 'cap' => $this->attention->cap()];
		$prefix = UserScope::prefixFor($uid);
		if ($prefix === '') {
			return $result;
		}

		// One id, one attempt, in a stable order: a request naming the same
		// id five times must not spend five times.
		$unique = [];
		foreach ($ids as $raw) {
			if (is_numeric($raw)) {
				$unique[(int) $raw] = true;
			}
		}
		$wanted = array_keys($unique);
		sort($wanted);

		foreach ($wanted as $index => $id) {
			// The cap is the ONE attention retry cap, counted in attempts:
			// a pasted list cannot spend more than a "retry all" click.
			if ($index >= $result['cap']) {
				$result['refused']++;
				$result['refused_ids'][] = $id;
				continue;
			}
			$outcome = $this->attention->retryOwned($id, $prefix);
			if ($outcome === Attention::RETRY_MISSING) {
				// Not a job id: a Decide row names a file, not a job.
				$outcome = $this->outcomes->retryRedoRow($id, $prefix);
			}
			if ($outcome === Attention::RETRY_DONE) {
				$result['retried']++;
			} else {
				$result['refused']++;
				$result['refused_ids'][] = $id;
			}
		}

		if ($result['refused'] > 0) {
			$this->logger?->info('ocrflow: mine: ' . $result['retried'] . ' retried, ' . $result['refused'] . ' refused for one user');
		}
		return $result;
	}

	/**
	 * One row, cut down to what the personal panel shows: the path, the fix
	 * hint, which queue it is on, how many attempts it has had and how old
	 * it is. `age` is seconds since the row was decided; null when the row
	 * does not know, so the panel can omit it instead of inventing a zero.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function row(array $row, string $origin, bool $retryable): array
	{
		$finished = isset($row['finished_at']) && $row['finished_at'] !== null ? (int) $row['finished_at'] : null;
		$age = $finished === null ? null : max(0, time() - $finished);
		return [
			// The id the retry endpoint accepts: a job id for a needs_fix
			// row, a Decide row id for a redo row.
			'id' => (int) ($row['id'] ?? 0),
			'origin' => $origin,
			'kind' => (($row['verdict'] ?? null) === RetryPolicy::VERDICT_REDO) ? 'redo' : 'needs_fix',
			'path' => (string) ($row['path'] ?? ''),
			'code' => (string) ($row['code'] ?? ''),
			'label' => (string) ($row['label'] ?? ''),
			'fix' => (string) ($row['fix'] ?? ''),
			'attempts' => isset($row['attempts']) && $row['attempts'] !== null ? (int) $row['attempts'] : null,
			'age' => $age,
			'retryable' => $retryable,
		];
	}
}
