<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Storage;

use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The run log: one row per queue attempt, and the only place a claim exists.
 *
 * A job row is a run — queued, claimed, settled, eventually purged. It is not
 * the file: that is `Ledger`. The two were one class while the report read job
 * rows; the report reads the ledger now, so the split is by subject rather than
 * by convenience.
 *
 * Claiming stays portable: no `SKIP LOCKED`, no `RETURNING`. A claim is a
 * conditional UPDATE keyed on the row still being `queued`; if another worker
 * got there first the update touches zero rows and we try the next candidate.
 */
class JobStore
{
	public const STATUS_QUEUED = 'queued';
	public const STATUS_CLAIMED = 'claimed';
	public const STATUS_DONE = 'done';
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_ERROR = 'error';

	/**
	 * How long a claim is valid without a renewal.
	 *
	 * Long enough that a slow page does not lose its job between renewals,
	 * short enough that an orphaned claim is back in the queue while the person
	 * is still looking at the panel.
	 */
	public const CLAIM_LEASE_SECONDS = 90;

	/**
	 * Absolute ceiling for one claim, renewals included. A worker that renews
	 * forever on a job that will never finish is indistinguishable from one
	 * making progress, so the lease alone cannot catch it.
	 */
	public const CLAIM_HARD_CAP_SECONDS = 7200;

	private const JOBS = 'ocrflow_jobs';

	/**
	 * Two methods here read the ledger instead of writing it: the scan loop
	 * breaker counts a file version's previous enqueues, and the terminal-row
	 * cleanup checks whether a verdict survives. Read, never write.
	 */
	private const FILES = 'ocrflow_files';
	private const MAX_CLAIM_TRIES = 8;

	/** Ids per chunked statement; small enough to keep locks short. */
	private const DELETE_CHUNK = 500;

	/** Ids per `IN (`. Nextcloud refuses more than 1 000 on every driver. */
	private const IN_CHUNK = 900;

	private const STAGE_EVENTS = 'ocrflow_stage_events';

	public function __construct(
		private IDBConnection $db,
		private RetryPolicy $retryPolicy,
	) {
	}

	/**
	 * The etag predicate, matching the one the ledger uses: a NULL etag means
	 * "any bytes", because a caller that does not know the version must not be
	 * answered as if it did.
	 */
	protected function deleteStageRowsById(array $ids): int
	{
		if ($ids === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::STAGE_EVENTS)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $qb->executeStatement();
	}

	protected function selectStageIdsForJobs(array $jobIds): array
	{
		if ($jobIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::STAGE_EVENTS)
			->where($qb->expr()->in('job_id', $qb->createNamedParameter($jobIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC')
			->setMaxResults(PHP_INT_MAX);
		return array_map(intval(...), array_column($qb->executeQuery()->fetchAll(), 'id'));
	}

	protected function selectOrphanStageIds(int $cutoff, int $limit): array
	{
		$qb = $this->db->getQueryBuilder();
		// A DATETIME column wants the object, not a formatted string.
		$cutoffDate = \DateTime::createFromFormat('U', (string) $cutoff);
		$qb->select('se.id')
			->from(self::STAGE_EVENTS, 'se')
			->leftJoin('se', self::JOBS, 'j', $qb->expr()->eq('j.id', 'se.job_id'))
			->where($qb->expr()->isNull('j.id'))
			->andWhere($qb->expr()->lt('se.created_at', $qb->createNamedParameter($cutoffDate, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->orderBy('se.id', 'ASC')
			->setMaxResults($limit);
		return array_map(intval(...), array_column($qb->executeQuery()->fetchAll(), 'id'));
	}

	private function etagPredicate(IQueryBuilder $qb, ?string $etag): string
	{
		return $etag === null
			? $qb->expr()->isNull('etag')
			: $qb->expr()->eq('etag', $qb->createNamedParameter($etag));
	}

	/**
	 * Oldest queued job, now owned by $worker. Null when the queue is empty.
	 *
	 * @throws Exception
	 */
	public function claim(string $worker, ?int $workerId = null): ?Job
	{
		for ($try = 0; $try < self::MAX_CLAIM_TRIES; $try++) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from(self::JOBS)
				->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_QUEUED)))
				->orderBy('id', 'ASC')
				->setMaxResults(1);
			$row = $qb->executeQuery()->fetch();
			if ($row === false) {
				return null;
			}
			$id = (int) $row['id'];

			// The token IS the claim: 32 random bytes, known to this row and
			// the one worker that asked for it. Every later call on this job
			// must present it; the old `claimed_by` name guarded nothing.
			$token = bin2hex(random_bytes(32));
			$up = $this->db->getQueryBuilder();
			$up->update(self::JOBS)
				->set('status', $up->createNamedParameter(self::STATUS_CLAIMED))
				->set('claim_token', $up->createNamedParameter($token))
				->set('claimed_by_worker_id', $up->createNamedParameter($workerId, IQueryBuilder::PARAM_INT))
				->set('claimed_at', $up->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				// The lease starts now and is renewed while the worker works;
				// recovery reads this, never the holder's heartbeat.
				->set('claim_expires_at', $up->createNamedParameter(time() + self::CLAIM_LEASE_SECONDS, IQueryBuilder::PARAM_INT))
				->set('attempts', $up->createFunction('attempts + 1'))
				->where($up->expr()->eq('id', $up->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($up->expr()->eq('status', $up->createNamedParameter(self::STATUS_QUEUED)));
			if ($up->executeStatement() === 1) {
				return $this->getById($id);
			}
			// Someone else claimed it between our SELECT and UPDATE: try again.
		}
		return null;
	}

	/**
	 * Extend the lease on a claim this worker still holds.
	 *
	 * False means the lease is gone — requeued by recovery, settled elsewhere,
	 * or the token is not the holder's. The worker's only correct answer is to
	 * stop working on that job: its result would be refused anyway.
	 * @throws Exception
	 */
	public function renewClaim(int $id, string $claimToken): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::JOBS)
			->set('claim_expires_at', $qb->createNamedParameter(time() + self::CLAIM_LEASE_SECONDS, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CLAIMED)))
			->andWhere($qb->expr()->eq('claim_token', $qb->createNamedParameter($claimToken)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Hand a claim back without a verdict.
	 *
	 * The worker calls this when it cannot report an outcome — its report got a
	 * 5xx and the retries ran out. The alternative is waiting out the lease,
	 * which is minutes of a slot sitting on a job that nobody is doing. The
	 * attempt already counted at claim time stays counted: a release is not a
	 * free retry.
	 * @throws Exception
	 */
	public function release(int $id, string $claimToken): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::JOBS)
			->set('status', $qb->createNamedParameter(self::STATUS_QUEUED))
			->set('claim_token', $qb->createNamedParameter(null))
			->set('claimed_by_worker_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
			->set('claimed_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
			->set('claim_expires_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CLAIMED)))
			->andWhere($qb->expr()->eq('claim_token', $qb->createNamedParameter($claimToken)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * @throws Exception
	 */
	public function getById(int $id): ?Job
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::JOBS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$rows = $qb->executeQuery()->fetchAll();
		return $rows === [] ? null : Job::fromRow($rows[0]);
	}

	/**
	 * Put a claimed job back in the queue — evidence-based recovery.
	 *
	 * The OLD rule was a blind timer (`claimed_at` older than N seconds),
	 * which requeued a live worker's 30-minute PDF from under it. The rule
	 * now is the holder's heartbeat: a claim goes back only when its worker
	 * row is missing (a pre-registry claim, or the row was pruned) or its
	 * `last_seen_at` fell behind the staleness budget. A worker that keeps
	 * heartbeating never loses the claim, however long the job runs.
	 *
	 * @param int $heartbeatStaleSeconds how stale a worker's heartbeat may be
	 * @throws Exception
	 */
	public function requeueStale(int $heartbeatStaleSeconds = self::CLAIM_LEASE_SECONDS): int
	{
		$now = time();
		$leaseCutoff = $now - max(1, $heartbeatStaleSeconds);
		$hardCutoff = $now - self::CLAIM_HARD_CAP_SECONDS;
		$select = $this->db->getQueryBuilder();
		$select->select('j.id')
			->from(self::JOBS, 'j')
			->leftJoin('j', 'ocrflow_workers', 'w', $select->expr()->eq('w.id', 'j.claimed_by_worker_id'))
			->where($select->expr()->eq('j.status', $select->createNamedParameter(self::STATUS_CLAIMED)))
			->andWhere($select->expr()->orX(
				// The lease is the primary rule. A pre-lease row (NULL) never
				// promised a renewal, so it counts as expired — that is what
				// recovers the claims written before this column existed.
				$select->expr()->isNull('j.claim_expires_at'),
				$select->expr()->lt('j.claim_expires_at', $select->createNamedParameter($leaseCutoff, IQueryBuilder::PARAM_INT)),
				// The holder vanished: its registry row is gone.
				$select->expr()->isNull('j.claimed_by_worker_id'),
				$select->expr()->isNull('w.id'),
				// A worker that renews forever on a hung job.
				$select->expr()->lt('j.claimed_at', $select->createNamedParameter($hardCutoff, IQueryBuilder::PARAM_INT)),
			));
		$ids = [];
		foreach ($select->executeQuery()->fetchAll() as $row) {
			$ids[] = (int) $row['id'];
		}
		if ($ids === []) {
			return 0;
		}

		$released = 0;
		foreach (array_chunk($ids, self::IN_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::JOBS)
				->set('status', $qb->createNamedParameter(self::STATUS_QUEUED))
				->set('claim_token', $qb->createNamedParameter(null))
				->set('claimed_by_worker_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
				->set('claimed_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
				->set('claim_expires_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CLAIMED)))
				->andWhere($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$released += $qb->executeStatement();
		}
		return $released;
	}

	/**
	 * Put terminal rows back in the queue with the operator's force bit set.
	 *
	 * The worker has always honoured `force` (it returns before every skip
	 * decision); this is the missing other half — a way for the app to SET it.
	 * Without this, a row that was skipped as `skip_text_native`,
	 * `skip_ocred_by_suspected_foreign` or `skip_ocred_by_ocrflow` offered no
	 * action anywhere in the UI, and the only escape was editing the table.
	 *
	 * The row is reused, never re-inserted: `UNIQUE (file_id, etag)` means one
	 * row per file version, and re-queueing that row is exactly what "rescan
	 * these bytes" means. Only terminal states are touched — a queued or
	 * claimed row is somebody else's work in flight.
	 *
	 * @param int[] $ids
	 * @return array{requeued: int[], refused: int[]}
	 * @throws Exception
	 */
	public function forceRequeue(array $ids): array
	{
		$requeued = [];
		$refused = [];
		foreach ($ids as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::JOBS)
				->set('status', $qb->createNamedParameter(self::STATUS_QUEUED))
				->set('force', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->set('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->set('error', $qb->createNamedParameter(null))
				->set('reason', $qb->createNamedParameter(null))
				->set('reason_code', $qb->createNamedParameter(null))
				->set('retryable', $qb->createNamedParameter(null))
				->set('finished_at', $qb->createNamedParameter(null))
				->set('claimed_at', $qb->createNamedParameter(null))
				->set('claim_token', $qb->createNamedParameter(null))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
					[self::STATUS_DONE, self::STATUS_SKIPPED, self::STATUS_ERROR],
					IQueryBuilder::PARAM_STR_ARRAY,
				)));
			if ($qb->executeStatement() === 1) {
				$requeued[] = $id;
			} else {
				$refused[] = $id;
			}
		}
		return ['requeued' => $requeued, 'refused' => $refused];
	}

	/**
	 * Delete the TERMINAL job rows of one file — the escape hatch's other
	 * half. `jobForVersion()` refuses an enqueue for any existing row of that
	 * version, so a terminal row retention has not purged yet must go too, or
	 * the cleared verdict alone changes nothing. Same rows retention would
	 * delete on schedule; the stage rows they own are swept as orphans by the
	 * next retention tick, exactly as after a purge.
	 *
	 * @throws Exception
	 */
	public function deleteTerminalForFile(int $fileId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::JOBS)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[self::STATUS_DONE, self::STATUS_SKIPPED, self::STATUS_ERROR],
				IQueryBuilder::PARAM_STR_ARRAY,
			)));
		return $qb->executeStatement();
	}

	/**
	 * Drop the queued rows for a file that no longer exists.
	 *
	 * The reconciler only sees files; a file that is gone is invisible to it, so
	 * its queued row would sit in the queue until a worker claimed it, fetched
	 * nothing and wrote an error about a file the owner had already deleted.
	 * Deleting the row at the source keeps the queue about files that exist.
	 *
	 * Only `queued` rows go: a `claimed` row belongs to a worker mid-fetch, and
	 * that worker reports its own outcome (FileGone, which now reads as "the
	 * file was deleted or moved"). History — done, skipped, error — stays.
	 */
	public function deleteQueuedForFile(int $fileId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::JOBS)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_QUEUED)));
		return $qb->executeStatement();
	}

	/**
	 * Ids of terminal rows whose reason code is $code, oldest first.
	 *
	 * The bulk door of `forceRequeue()`: an operator selects a population
	 * ("everything skipped as a foreign text layer"), not a list of ids they
	 * would have to build by hand.
	 *
	 * @return int[]
	 * @throws Exception
	 */
	public function idsWithReasonCode(string $code, int $limit): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::JOBS)
			->where($qb->expr()->eq('reason_code', $qb->createNamedParameter($code)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[self::STATUS_DONE, self::STATUS_SKIPPED, self::STATUS_ERROR],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn (array $row): int => (int) $row['id'], $rows);
	}





	public function counts(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')
			->selectAlias($qb->func()->count('*'), 'n')
			->from(self::JOBS)
			->groupBy('status');
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[(string) $row['status']] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Bounded retention for the job tables.
	 *
	 * Delete TERMINAL job rows (`done`, `skipped`, `error` — statuses after
	 * which no further work happens for that row) older than the cutoff,
	 * together with their `ocrflow_stage_events` rows, then sweep orphan
	 * stage rows (their job already gone) past the same cutoff. At most
	 * `$cap` jobs go per call, in id-ordered chunks, so one tick can never
	 * lock the tables for an unbounded time; the remainder waits for the next
	 * tick.
	 *
	 * Stage rows die first, the job row second: a crash in between leaves a
	 * job that the next run selects and deletes again — never an orphan.
	 *
	 * Retention must NEVER delete from `ocrflow_files`: that table is the
	 * permanent per-file ledger (`UNIQUE (file_id)`, `(etag, status,
	 * stamp_version)`) that dedupe reads via {@see isDoneForVersion()} at
	 * enqueue time; deleting from it re-opens the corpus for paid re-OCR.
	 * This method, and every private delete below it, names only
	 * `ocrflow_jobs` and `ocrflow_stage_events`.
	 *
	 * @param int $cutoff epoch seconds; rows finished before this go
	 * @param int $cap max job rows deleted in this call
	 * @return array{jobs: int, stage_events: int}
	 * @throws Exception
	 */
	public function purgeTerminal(int $cutoff, int $cap): array
	{
		$deleted = ['jobs' => 0, 'stage_events' => 0];

		// The id-ordered, capped select keeps every DELETE bounded: each
		// chunk of ids produces one `IN (...)` delete on each table.
		while ($deleted['jobs'] < $cap) {
			$limit = min($cap - $deleted['jobs'], self::DELETE_CHUNK);
			$ids = $this->selectExpiredTerminalIds($cutoff, $limit);
			if ($ids === []) {
				break;
			}
			$stageIds = $this->selectStageIdsForJobs($ids);
			$deleted['stage_events'] += $this->deleteStageRowsById($stageIds);
			$n = $this->deleteJobsById($ids);
			$deleted['jobs'] += $n;
			if ($n === 0) {
				// Rows vanished under us (a concurrent sweep won them): the
				// next select will not return them, but guard against a
				// pathological "selected yet undeletable" loop anyway.
				break;
			}
		}

		// Orphan pass: stage rows whose job is already gone (a crash between
		// the two deletes of some earlier run, or manual surgery) are swept by
		// their own age under the same cutoff, capped the same way.
		$orphanBudget = $cap;
		while ($orphanBudget > 0) {
			$limit = min($orphanBudget, self::DELETE_CHUNK);
			$ids = $this->selectOrphanStageIds($cutoff, $limit);
			if ($ids === []) {
				break;
			}
			$n = $this->deleteStageRowsById($ids);
			$deleted['stage_events'] += $n;
			$orphanBudget -= max($n, count($ids));
		}

		return $deleted;
	}

	/**
	 * Ids of terminal jobs finished before the cutoff, oldest ids first —
	 * EXCEPT the rows whose reason code has the `needs_fix` verdict.
	 *
	 * Those rows are the operator's worklist (see the Attention service):
	 * purging them would delete the work instead of doing it. Old spellings
	 * read through the alias map, so a pre-0.1.5 row named `broken_pdf` is
	 * kept exactly like a row named `error_file_broken`.
	 *
	 * A row with NO code (or a code nobody knows) is still purged: the
	 * worklist only holds rows the vocabulary can name, and silence is not
	 * a work item. Everything else about the sweep is unchanged, and
	 * `ocrflow_files` is never touched.
	 *
	 * @return list<int>
	 * @throws Exception
	 */
	protected function selectExpiredTerminalIds(int $cutoff, int $limit): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::JOBS)
			->where($qb->expr()->in('status', $qb->createNamedParameter(
				[self::STATUS_DONE, self::STATUS_SKIPPED, self::STATUS_ERROR],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($qb->expr()->isNotNull('finished_at'))
			->andWhere($qb->expr()->lt('finished_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
			->andWhere($this->purgeablePredicate($qb))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return array_map(intval(...), array_column($qb->executeQuery()->fetchAll(), 'id'));
	}

	/**
	 * True for rows retention may delete: the row's code (reason_code, or
	 * reason when reason_code is empty — the pre-0.1.5 shape) does NOT
	 * carry the needs_fix verdict.
	 *
	 * The empty/NULL cases are spelled out because SQL three-valued logic
	 * turns `NOT IN` on a NULL into "unknown", and a NULL must read as
	 * "no verdict", not as "needs_fix".
	 */
	protected function purgeablePredicate(IQueryBuilder $qb): ICompositeExpression
	{
		$spellings = $this->retryPolicy->spellingsForVerdict(RetryPolicy::VERDICT_NEEDS_FIX);
		$empty = $qb->expr()->orX(
			$qb->expr()->isNull('reason_code'),
			$qb->expr()->eq('reason_code', $qb->createNamedParameter('')),
		);
		return $qb->expr()->orX(
			// A code is recorded and it is not a needs_fix spelling.
			$qb->expr()->andX(
				$qb->expr()->isNotNull('reason_code'),
				$qb->expr()->neq('reason_code', $qb->createNamedParameter('')),
				$qb->expr()->notIn('reason_code', $qb->createNamedParameter($spellings, IQueryBuilder::PARAM_STR_ARRAY)),
			),
			// No code recorded: fall back to reason (old rows), and a NULL
			// reason is no verdict either — both keep today's behaviour.
			$qb->expr()->andX(
				$empty,
				$qb->expr()->orX(
					$qb->expr()->isNull('reason'),
					$qb->expr()->notIn('reason', $qb->createNamedParameter($spellings, IQueryBuilder::PARAM_STR_ARRAY)),
				),
			),
		);
	}

	/**
	 * The job for this exact content version, if one was ever queued.
	 *
	 * @throws Exception
	 */
	public function jobForVersion(int $fileId, ?string $etag): ?Job
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::JOBS)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($this->etagPredicate($qb, $etag))
			->setMaxResults(1);
		$rows = $qb->executeQuery()->fetchAll();
		return $rows === [] ? null : Job::fromRow($rows[0]);
	}

	/**
	 * True when the per-file row already records `done` for this version. A row
	 * with a different etag is an older version of the file, not an answer.
	 *
	 * @throws Exception
	 */
	public function isDoneForVersion(int $fileId, ?string $etag): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::FILES)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_DONE)))
			->andWhere($this->etagPredicate($qb, $etag))
			->setMaxResults(1);
		return $qb->executeQuery()->fetch() !== false;
	}

	/**
	 * Has a scan already enqueued THIS file version once (the ledger count
	 * says so, and the etag still names the same bytes)?
	 *
	 * The count survives the retention purge because it lives in the ledger;
	 * `Attention::retryFile()` is the one reset, so an operator's requeue
	 * always works once.
	 */
	public function alreadyEnqueuedForVersion(int $fileId, ?string $etag): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('etag', 'times_enqueued')->from(self::FILES)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return false;
		}
		$counted = isset($row['times_enqueued']) ? (int) $row['times_enqueued'] : 0;
		return $counted >= 1 && (string) ($row['etag'] ?? '') === (string) $etag;
	}

	/**
	 * @throws Exception
	 */
	public function findByPath(string $path): ?Job
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::JOBS)
			->where($qb->expr()->eq('path', $qb->createNamedParameter($path)))
			->setMaxResults(1);
		$rows = $qb->executeQuery()->fetchAll();
		return $rows === [] ? null : Job::fromRow($rows[0]);
	}

	/**
	 * Record the hash of the bytes `file()` served the worker (the ladder's
	 * second rung compares it with the file's content at write time).
	 *
	 * Not claim-guarded: it is written while the claim is live, before the
	 * worker has the bytes, and it never changes the job's status.
	 */
	public function recordSourceHash(int $id, string $sha256): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::JOBS)
			->set('source_sha256', $qb->createNamedParameter($sha256))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Re-arm the etag of our own successful write, so the ladder recognises
	 * a retried upload of the same result instead of stashing it.
	 */
	public function armWrittenEtag(int $id, string $etag): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::JOBS)
			->set('written_etag', $qb->createNamedParameter($etag))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Reset the loop-breaker count for one file — the operator override's
	 * other half, so a requeue buys exactly one fresh scan enqueue.
	 */
	public function resetTimesEnqueued(int $fileId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::FILES)
			->set('times_enqueued', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * Count one scan-initiated enqueue in the never-purged ledger.
	 *
	 * A new etag resets the budget (different bytes are different work);
	 * the same etag increments it. Status and reason are left alone: the
	 * verdict is the finish path's to write.
	 */
	public function countScanEnqueue(int $fileId, string $path, ?int $size, ?string $etag): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('etag', 'times_enqueued')->from(self::FILES)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();

		$w = $this->db->getQueryBuilder();
		if ($row !== false) {
			$next = (string) ($row['etag'] ?? '') === (string) $etag
				? (isset($row['times_enqueued']) ? (int) $row['times_enqueued'] : 0) + 1
				: 1;
			$w->update(self::FILES)
				->set('etag', $w->createNamedParameter($etag))
				->set('times_enqueued', $w->createNamedParameter($next, IQueryBuilder::PARAM_INT))
				->set('updated_at', $w->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($w->expr()->eq('file_id', $w->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		} else {
			$w->insert(self::FILES)
				->values([
					'file_id' => $w->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
					'path' => $w->createNamedParameter($path),
					'size' => $w->createNamedParameter($size, IQueryBuilder::PARAM_INT),
					'etag' => $w->createNamedParameter($etag),
					'times_enqueued' => $w->createNamedParameter(1, IQueryBuilder::PARAM_INT),
					'updated_at' => $w->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				]);
		}
		$w->executeStatement();
	}

	/**
	 * @param list<int> $ids
	 * @throws Exception
	 */
	protected function deleteJobsById(array $ids): int
	{
		if ($ids === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::JOBS)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $qb->executeStatement();
	}

	public function liveQueueFor(array $fileIds): array
	{
		$out = [];
		foreach (array_chunk($fileIds, self::IN_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id', 'status')
				->from(self::JOBS)
				->where($qb->expr()->in('status', $qb->createNamedParameter(
					[self::STATUS_QUEUED, self::STATUS_CLAIMED],
					IQueryBuilder::PARAM_STR_ARRAY,
				)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($qb->executeQuery()->fetchAll() as $row) {
				$fileId = (int) $row['file_id'];
				// A claim outranks a queued row for the same file.
				if (($out[$fileId] ?? '') !== self::STATUS_CLAIMED) {
					$out[$fileId] = (string) $row['status'];
				}
			}
		}
		return $out;
	}

	public function terminalJobIdsFor(array $fileIds): array
	{
		$out = [];
		foreach (array_chunk($fileIds, self::IN_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id')
				->selectAlias($qb->func()->max('id'), 'job_id')
				->from(self::JOBS)
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
					[self::STATUS_DONE, self::STATUS_SKIPPED, self::STATUS_ERROR],
					IQueryBuilder::PARAM_STR_ARRAY,
				)))
				->groupBy('file_id');
			foreach ($qb->executeQuery()->fetchAll() as $row) {
				$out[(int) $row['file_id']] = (int) $row['job_id'];
			}
		}
		return $out;
	}
}
