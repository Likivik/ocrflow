<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
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

	/** The caller's file was moved to their trash bin. */
	public const DELETE_DONE = 'deleted';

	/** The id names no node in the caller's own folder. */
	public const DELETE_MISSING = 'missing';

	/** The node exists but is not inside the caller's `/<uid>/files/`. */
	public const DELETE_REFUSED = 'refused';

	/** The delete was attempted and failed for a reason that is not ownership or absence. */
	public const DELETE_FAILED = 'failed';

	public function __construct(
		private Attention $attention,
		private Outcomes $outcomes,
		private ?LoggerInterface $logger = null,
		private ?IRootFolder $rootFolder = null,
		private ?IUserMountCache $mountCache = null,
		private ?RetryPolicy $retryPolicy = null,
	) {
	}

	private function policy(): RetryPolicy
	{
		return $this->retryPolicy ??= new RetryPolicy();
	}

	/**
	 * The caller's own rows: needs_fix (a job that needs a human) and redo
	 * (a file worth re-OCRing), newest first.
	 *
	 * @return array{rows: list<array<string, mixed>>, total: int, cap: int}
	 */
	public function list(string $uid, int $limit, int $offset = 0, ?string $bucket = null, ?string $search = null): array
	{
		$prefix = UserScope::prefixFor($uid);
		if ($prefix === '') {
			return ['rows' => [], 'total' => 0, 'counts' => ['all' => 0, 'needs_fix' => 0, 'redo' => 0], 'cap' => $this->attention->cap()];
		}

		$limit = max(1, $limit);
		$offset = max(0, $offset);
		// Each side is asked for enough rows to page the merged list; the
		// global top (offset+limit) cannot contain a row outside a side's own
		// top (offset+limit), so this is exact, not a guess.
		$span = min($limit + $offset, self::MAX_ROWS);

		$jobRows = [];
		$jobResult = $this->attention->list($span, 0, null, RetryPolicy::VERDICT_ACTIONABLE, $prefix, $search);
		foreach ($jobResult['rows'] as $row) {
			// Actionable, not needs_fix: the list must contain the rows a retry
			// can help, or the Retry button has nowhere to appear.
			$jobRows[] = $this->row($row, 'job', $this->policy()->isRetryable((string) ($row['code'] ?? '')));
		}

		$redoRows = [];
		$redoResult = $this->outcomes->list('redo', $span, 0, null, $prefix, $search);
		foreach ($redoResult['rows'] as $row) {
			$origin = is_string($row['origin'] ?? null) ? $row['origin'] : 'ledger';
			// A stashed result is a decision (apply / discard), not a retry:
			// the button would re-bill a file whose conflict is still open.
			$redoRows[] = $this->row($row, $origin, $origin !== 'stash');
		}

		// The ownership predicate lives in the two queries (each side owns its
		// WHERE); no post-filter here — a row the count counted but the list
		// dropped is exactly the two-doors disease this replaces.
		$page = self::page(
			$jobRows,
			(int) $jobResult['total'],
			$redoRows,
			(int) $redoResult['total'],
			$bucket,
			$offset,
			$limit,
		);

		return $page + ['cap' => $this->attention->cap()];
	}

	/**
	 * The pure core: merge two ordered sides, count them, page them.
	 *
	 * Everything the section shows is derivable from this one answer: the
	 * heading is `total`, the chips are `counts`, and the union of the pages
	 * is the list — the same rows the totals were taken over. The order is
	 * total (finished_at, id, origin), so a row cannot fall between two pages
	 * or appear on both.
	 *
	 * @param list<array<string, mixed>> $jobRows the needs_fix side, already ordered
	 * @param list<array<string, mixed>> $redoRows the redo side, already ordered
	 * @return array{rows: list<array<string, mixed>>, total: int, counts: array{all: int, needs_fix: int, redo: int}}
	 */
	public static function page(array $jobRows, int $jobTotal, array $redoRows, int $redoTotal, ?string $bucket, int $offset, int $limit): array
	{
		$wantJob = $bucket !== 'redo';
		$wantRedo = $bucket !== 'needs_fix';

		$rows = [];
		if ($wantJob) {
			$rows = array_merge($rows, $jobRows);
		}
		if ($wantRedo) {
			$rows = array_merge($rows, $redoRows);
		}

		usort($rows, static function (array $a, array $b): int {
			$fa = (int) ($a['finished_at'] ?? 0);
			$fb = (int) ($b['finished_at'] ?? 0);
			$ia = (int) ($a['id'] ?? 0);
			$ib = (int) ($b['id'] ?? 0);
			$oa = (string) ($a['origin'] ?? '');
			$ob = (string) ($b['origin'] ?? '');

			return ($fb <=> $fa) ?: ($ib <=> $ia) ?: strcmp($oa, $ob);
		});

		return [
			'rows' => array_slice($rows, $offset, $limit),
			'total' => ($wantJob ? $jobTotal : 0) + ($wantRedo ? $redoTotal : 0),
			'counts' => [
				'all' => $jobTotal + $redoTotal,
				'needs_fix' => $jobTotal,
				'redo' => $redoTotal,
			],
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
	 * Move one of the caller's own files to their trash bin.
	 *
	 * The node is resolved from the CALLER's user folder, and then its stored
	 * path is checked against the caller's `/<uid>/files/` scope before
	 * anything happens to it — file ids are global, so an id alone is not
	 * evidence of ownership. A foreign file is refused with the delete never
	 * attempted (403), a vanished one is 404, and neither is ever a 500.
	 *
	 * `$node->delete()` is the filesystem-layer call, which is what the
	 * files_trashbin hooks intercept. A WebDAV DELETE would bypass them and
	 * destroy the file.
	 */
	public function delete(string $uid, int $fileId): string
	{
		$prefix = UserScope::prefixFor($uid);
		if ($prefix === '' || $this->rootFolder === null) {
			return self::DELETE_REFUSED;
		}

		try {
			$folder = $this->rootFolder->getUserFolder($uid);
			$nodes = $folder->getById($fileId);
		} catch (NotPermittedException) {
			// The account exists but its folder is not readable here. That
			// says nothing about ownership of THIS id, so it is a refusal:
			// nothing was deleted and the answer must not read as success.
			return self::DELETE_REFUSED;
		} catch (NotFoundException) {
			// No user folder at all: there is nothing of theirs to delete,
			// and "gone" is the honest answer for every id they name.
			return self::DELETE_MISSING;
		} catch (\Throwable $e) {
			// A missing account lands here on some versions (NoUserException).
			// It is not an ownership answer, so it is not counted as one.
			$this->logger?->warning('ocrflow: mine: cannot open the user folder for a delete: ' . $e->getMessage());
			return self::DELETE_MISSING;
		}

		$node = $nodes[0] ?? null;
		if ($node === null) {
			// A user folder resolves only the ids inside that user's own
			// space (Root::getById does the same: it searches the mounts of
			// the CURRENT user), so an empty answer means "not here" — which
			// is either "gone" or "not yours", and the panel must say which.
			//
			// The mount cache is the one public API that knows which user's
			// mount holds an id, so it turns the empty answer into the right
			// one. This IS an existence oracle for file ids, deliberately:
			// "403, not yours" is the honest answer, ids are global and
			// guessable anyway, and the oracle never reveals anything the
			// caller could act on.
			return $this->heldByAnotherUser($uid, $fileId) ? self::DELETE_REFUSED : self::DELETE_MISSING;
		}

		// The one ownership rule, the same one the listing applies. A
		// resolved node that is not under the caller's scope is refused
		// BEFORE the delete: `never()`, not "deleted nothing".
		if (!UserScope::owns($node->getPath(), $prefix)) {
			$this->logger?->warning('ocrflow: mine: refused a delete of a node outside the caller\'s scope');
			return self::DELETE_REFUSED;
		}

		try {
			$node->delete();
		} catch (NotPermittedException) {
			// A lock or a read-only mount: the file is still there, so this
			// is a refusal and the caller must not be told it is gone.
			return self::DELETE_REFUSED;
		} catch (NotFoundException) {
			// Vanished between the lookup and the unlink, so the trash never
			// saw it; the honest answer is the same 404 a second click gets.
			return self::DELETE_MISSING;
		} catch (\Throwable $e) {
			$this->logger?->error('ocrflow: mine: delete failed for file ' . $fileId . ': ' . $e->getMessage());
			return self::DELETE_FAILED;
		}

		return self::DELETE_DONE;
	}

	/**
	 * Does any user OTHER than $uid have a mount holding this file id?
	 *
	 * "Nobody has it" and "somebody else has it" are the two answers a user
	 * folder cannot give, and they are exactly the two a Delete must
	 * distinguish (404 vs 403).
	 */
	private function heldByAnotherUser(string $uid, int $fileId): bool
	{
		if ($this->mountCache === null) {
			return false;
		}
		try {
			$mounts = $this->mountCache->getMountsForFileId($fileId);
		} catch (\Throwable $e) {
			// A cache that cannot answer is not an ownership finding: the
			// safe reading is "not yours", which is 404 and deletes nothing.
			$this->logger?->warning('ocrflow: mine: cannot ask the mount cache about a file id: ' . $e->getMessage());
			return false;
		}
		foreach ($mounts as $mount) {
			$owner = $mount->getUser();
			if ($owner === null || $owner->getUID() === $uid) {
				// The caller's own mount, or an entry with no user: this is
				// "gone for this request", not someone else's file.
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Delete a selection: one request, one answer that says what happened to
	 * every id. Bounded by the same `attention_retry_cap` a retry-all obeys,
	 * counted in attempts, so a pasted list cannot delete more files than a
	 * deliberate batch would.
	 *
	 * Refusals are counted and named, never silently skipped — "deleted 2"
	 * for a list of three reads as though the third one went too.
	 *
	 * @param list<mixed> $ids
	 * @return array{deleted: int, refused: int, refused_ids: list<int>, cap: int}
	 */
	public function deleteMany(string $uid, array $ids): array
	{
		$result = ['deleted' => 0, 'refused' => 0, 'refused_ids' => [], 'cap' => $this->attention->cap()];
		if (UserScope::prefixFor($uid) === '') {
			return $result;
		}

		// One id, one attempt: a request naming the same id five times must
		// not spend the cap five times.
		$unique = [];
		foreach ($ids as $raw) {
			if (is_numeric($raw)) {
				$unique[(int) $raw] = true;
			}
		}
		$wanted = array_keys($unique);
		sort($wanted);

		foreach ($wanted as $index => $id) {
			if ($index >= $result['cap']) {
				$result['refused']++;
				$result['refused_ids'][] = $id;
				continue;
			}
			if ($this->delete($uid, $id) === self::DELETE_DONE) {
				$result['deleted']++;
			} else {
				$result['refused']++;
				$result['refused_ids'][] = $id;
			}
		}

		// One log line per batch, the way the retry door does it: the ids
		// themselves are the user's file names, and a log is not the place
		// for them.
		if ($result['deleted'] > 0 || $result['refused'] > 0) {
			$this->logger?->info('ocrflow: mine: ' . $result['deleted'] . ' deleted, ' . $result['refused'] . ' refused for one user');
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
		return \OCA\OcrFlow\Report\FileRow::of($row, $origin, $retryable);
	}
}
