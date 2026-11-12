<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Db;

use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The queue, and the only place that knows what a job row means.
 *
 * Claiming is deliberately portable: no `SKIP LOCKED`, no `RETURNING`. MySQL
 * has neither, and the app has to install on all three supported databases. A
 * claim is a conditional UPDATE keyed on the row still being `queued`; if
 * another worker got there first the update touches zero rows and we try the
 * next candidate. That is enough for a worker pool of one or a few.
 */
class Queue extends QBMapper
{
	public const STATUS_QUEUED = 'queued';

	/** Attempts after which a retryable failure stays failed. */
	private const MAX_ATTEMPTS = 3;
	public const STATUS_CLAIMED = 'claimed';
	public const STATUS_DONE = 'done';
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_ERROR = 'error';

	private const JOBS = 'ocrflow_jobs';
	private const FILES = 'ocrflow_files';
	private const STAGE_EVENTS = 'ocrflow_stage_events';

	/**
	 * Maximum stage rows carried by ONE multi-row INSERT statement.
	 *
	 * The stage events used to be written one round-trip per row: at flood
	 * scale (20k jobs x up to 7 stages) that is ~140k round-trips a night
	 * for what is ~20k jobs' worth of data. 500 rows per statement is the
	 * conventional safe bound — it keeps the generated SQL and its
	 * placeholder count well inside driver and server limits on all
	 * three supported databases. StageEvents caps a job at 200 rows, so
	 * one statement per job is the normal case; the chunk exists so a
	 * future cap raise cannot produce a single unbounded statement.
	 */
	private const STAGE_INSERT_CHUNK = 500;

	private const MAX_CLAIM_TRIES = 8;

	/** Ids per chunked retention DELETE; small enough to keep locks short. */
	private const DELETE_CHUNK = 500;

	private readonly RetryPolicy $retryPolicy;

	private StageEvents $stageEvents;

	public function __construct(
		IDBConnection $db,
		StageEvents $stageEvents,
		private ?LoggerInterface $logger = null,
		?RetryPolicy $retryPolicy = null,
	) {
		$this->stageEvents = $stageEvents;
		// The retry table already exists and has one owner; enqueue only asks
		// it a second question (is this verdict permanent?), so it is the
		// same policy object rather than a second vocabulary.
		$this->retryPolicy = $retryPolicy ?? new RetryPolicy();
		parent::__construct($db, self::JOBS, Job::class);
	}

	/**
	 * Queue one path. Returns null when this content version is already queued
	 * or already done — the caller treats null as "nothing to do", not as an
	 * error.
	 *
	 * Identity is `(file_id, etag)`, not the path. A rename or a move changes
	 * the path and nothing else, and Nextcloud can deliver the same event
	 * twice; neither must cost a second OCR run. A file whose content changed
	 * has a new `etag` and is queued again. See `docs/adr/0001`.
	 *
	 * @throws Exception
	 */
	public function enqueue(
		string $path,
		?int $fileId,
		?int $size,
		?string $etag,
		?string $engine = null,
	): ?int {
		// A zero-byte source is a data problem, not work: the worker can only fetch
		// an empty stream and fail after transport — the QueueProtocolError class
		// that died four times in production. This must run BEFORE the dedupe
		// guards below: `jobForVersion()` returns any row for this version,
		// including the `error` row written the last time this file was queued,
		// so the correction would otherwise never be recorded.
		if ($size !== null && $size <= 0) {
			$this->recordEmptySource($path, $fileId, $size, $etag);
			return null;
		}

		if ($fileId !== null) {
			if ($this->jobForVersion($fileId, $etag) !== null) {
				return null;
			}
			if ($this->isDoneForVersion($fileId, $etag)) {
				return null;
			}
			// The verdict that must outlive the purge. Retention (#90) deletes
			// terminal job rows — including `error` — after the retention
			// window, and once that row is gone the guards above say nothing.
			// The per-file ledger is never purged, so the verdict lives there:
			// a PERMANENT failure for THIS etag means the same bytes can
			// never succeed, and re-queueing them just re-bills the engine
			// once per retention window, forever. New bytes (a new etag)
			// pass: a permanent verdict is about the bytes, not the path.
			$permanentReason = $this->permanentFailureForVersion($fileId, $etag);
			if ($permanentReason !== null) {
				// One line, not silence: an operator must be able to see why
				// a scan keeps skipping a file, and how to override it.
				$this->logger?->info(
					'ocrflow: file skipped: permanent failure (' . $permanentReason . ')'
					. ' for ' . $path . '; occ ocrflow:requeue "' . $path . '" to retry anyway',
				);
				return null;
			}
		} elseif ($this->findByPath($path) !== null) {
			// No file id (a path from outside the cache): the path is all we
			// have to go on, so keep the old, weaker rule.
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::JOBS)
			->values([
				'path' => $qb->createNamedParameter($path),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'status' => $qb->createNamedParameter(self::STATUS_QUEUED),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
				'etag' => $qb->createNamedParameter($etag),
				// Chosen by the instance, not the worker: it decides whether page
				// images leave the server. Stamped here so a policy change does not
				// alter a job that is already queued.
				'engine' => $qb->createNamedParameter($engine),
			]);
		try {
			$qb->executeStatement();
		} catch (Exception $e) {
			// A concurrent insert won the unique(path) race. Same answer as
			// "already queued": nothing for this caller to do.
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return null;
			}
			throw $e;
		}

		// `IDBConnection::getLastInsertId()` was removed in Nextcloud 34; the
		// query builder owns it now. Calling it on the connection threw
		// "Call to undefined method ConnectionAdapter::getLastInsertId()" on every
		// upload — the row landed, the listener logged a failure and returned no
		// id, so nothing downstream could tell the job it had just created.
		return $qb->getLastInsertId();
	}

	/**
	 * Record (or correct) a zero-byte source as a terminal skip.
	 *
	 * Correcting matters: these files were queued before the size check existed
	 * and their rows say `error`, which a retry policy reads as work to do. A
	 * rescan must be able to fix the classification, not leave it frozen.
	 *
	 * @throws Exception
	 */
	private function recordEmptySource(string $path, ?int $fileId, ?int $size, ?string $etag): void
	{
		// The zero-byte verdict goes to the ledger too, not only to the job
		// row: retention will purge the job row, and the file is still zero
		// bytes — the verdict must survive so the next scan does not have to
		// rediscover it (and so the block stays explainable).
		if ($fileId !== null) {
			$this->writeLedger($fileId, $path, $size, $etag, self::STATUS_SKIPPED, 'error_source_file_zero_bytes', null, null, null);
		}

		$existing = $fileId === null ? $this->findByPath($path) : $this->jobForVersion($fileId, $etag);
		if ($existing !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::JOBS)
				->set('status', $qb->createNamedParameter('skipped'))
				->set('reason', $qb->createNamedParameter('error_source_file_zero_bytes'))
				->set('reason_code', $qb->createNamedParameter('error_source_file_zero_bytes'))
				->set('retryable', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
				->set('error', $qb->createNamedParameter(null))
				->set('claim_token', $qb->createNamedParameter(null))
				->set('claimed_by_worker_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
				->set('finished_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $existing->getId(), IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			$this->recordDecision((int) $existing->getId(), 'error_source_file_zero_bytes');
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::JOBS)
			->values([
				'path' => $qb->createNamedParameter($path),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'status' => $qb->createNamedParameter('skipped'),
				'reason' => $qb->createNamedParameter('error_source_file_zero_bytes'),
				'reason_code' => $qb->createNamedParameter('error_source_file_zero_bytes'),
				'retryable' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'finished_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
				'etag' => $qb->createNamedParameter($etag),
			]);
		$qb->executeStatement();
		$this->recordDecision((int) $qb->getLastInsertId(), 'error_source_file_zero_bytes');
	}

	/**
	 * Record the app's own decision in the one place decisions are recorded.
	 *
	 * The worker never learns the file existed, and nobody else writes a
	 * reason code for this decision — so if it is not written here, it is not
	 * written at all. One row per stage per job: a rescan that reaches the
	 * same verdict must not pile up rows for one job.
	 *
	 * @throws Exception
	 */
	private function recordDecision(int $jobId, string $reasonCode): void
	{
		if ($this->hasStageRow($jobId, 'intake', $reasonCode)) {
			return;
		}
		$row = $this->stageEvents->appDecision('intake', $reasonCode, 0, 0);
		if ($row === null) {
			return;
		}
		$this->insertStageRow($jobId, $row);
	}

	/**
	 * Write one stage row.
	 *
	 * One insert path for both callers: the worker's report and the app's
	 * own decision land in the table the same way, or the table answers
	 * "why" two different ways.
	 *
	 * @param array{stage: string, reason_code: ?string, duration_ms: ?int, cost_pages: ?int} $row
	 * @throws Exception
	 */
	private function insertStageRow(int $jobId, array $row): void
	{
		// Parameters belong to the builder that created them, so every
		// placeholder below is made by this builder and bound by it.
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::STAGE_EVENTS)
			->values([
				'job_id' => $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT),
				'stage' => $qb->createNamedParameter($row['stage']),
				'reason_code' => $qb->createNamedParameter($row['reason_code']),
				'duration_ms' => $qb->createNamedParameter($row['duration_ms'], IQueryBuilder::PARAM_INT),
				'cost_pages' => $qb->createNamedParameter($row['cost_pages'], IQueryBuilder::PARAM_INT),
				// A DATETIME column: the driver wants the object, not a
				// formatted string, or the value lands as "0000-00-00".
				'created_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE),
			]);
		$qb->executeStatement();
	}

	/**
	 * Has this job already a row for that stage with that code?
	 *
	 * Keyed on the code as well as the stage: the worker's `intake` row (the
	 * download) and the app's `intake` decision (this source needs no work)
	 * share a stage name, and one must not suppress the other. A repeat of
	 * the same decision, on the other hand, must not pile up rows.
	 *
	 * @throws Exception
	 */
	private function hasStageRow(int $jobId, string $stage, string $reasonCode): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::STAGE_EVENTS)
			->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('stage', $qb->createNamedParameter($stage)))
			->andWhere($qb->expr()->eq('reason_code', $qb->createNamedParameter($reasonCode)))
			->setMaxResults(1);
		return $qb->executeQuery()->fetch() !== false;
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
	 * The permanent reason recorded in the ledger for THIS etag, or null.
	 *
	 * This is the half of the dedupe that survives retention: the job row is
	 * history and gets purged, the per-file ledger is the verdict. The row
	 * only blocks when its status is a terminal failure AND its reason is in
	 * the permanent set AND the etag still names the failed bytes — anything
	 * else (a retryable code, an unknown code, a missing row, new bytes)
	 * fails open toward retrying.
	 *
	 * @throws Exception
	 */
	public function permanentFailureForVersion(int $fileId, ?string $etag): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('reason')->from(self::FILES)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[self::STATUS_ERROR, self::STATUS_SKIPPED],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($this->etagPredicate($qb, $etag))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		$reason = $row['reason'] ?? null;
		return is_string($reason) && $this->retryPolicy->isPermanent($reason) ? $reason : null;
	}

	/**
	 * The ledger row for a path, or null when this app has never seen it.
	 *
	 * @return array{file_id: int, path: string, status: string, reason: ?string, etag: ?string}|null
	 * @throws Exception
	 */
	public function findFileByPath(string $path): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'path', 'status', 'reason', 'etag')->from(self::FILES)
			->where($qb->expr()->eq('path', $qb->createNamedParameter($path)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		return [
			'file_id' => (int) $row['file_id'],
			'path' => (string) $row['path'],
			'status' => (string) $row['status'],
			'reason' => isset($row['reason']) && is_string($row['reason']) ? $row['reason'] : null,
			'etag' => isset($row['etag']) && is_string($row['etag']) ? $row['etag'] : null,
		];
	}

	/**
	 * The operator override (`occ ocrflow:requeue`): forget the permanent
	 * verdict for one file. The ledger keeps its status history; only the
	 * reason is cleared, which is the only column the block reads.
	 *
	 * @throws Exception
	 */
	public function clearPermanentVerdict(int $fileId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::FILES)
			->set('reason', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
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
	 * `etag = ?` or `etag IS NULL`. An unknown version never matches a known one,
	 * in either direction: "we do not know which version this is" is not "this
	 * version is already done".
	 */
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
	 * Finish a job and record what the file now is. One write to the job row and
	 * one to the per-file row, so the Files sidebar answer does not depend on the
	 * history table being complete.
	 *
	 * @param array{status: string, reason?: ?string, reason_code?: ?string, retryable?: ?bool,
	 *              engine?: ?string, engine_version?: ?string, pages?: ?int,
	 *              chars?: ?int, stamp_version?: ?string, error?: ?string,
	 *              stage_events?: mixed} $result
	 * @throws Exception
	 */
	public function finish(int $id, array $result, string $claimToken): bool
	{
		$job = $this->getById($id);
		if ($job === null) {
			return false;
		}

		$status = $result['status'];
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::JOBS)
			->set('status', $qb->createNamedParameter($status))
			->set('reason', $qb->createNamedParameter($result['reason'] ?? null))
			->set('reason_code', $qb->createNamedParameter($result['reason_code'] ?? null))
			->set('retryable', ($result['retryable'] ?? null) === null
				? $qb->createNamedParameter(null)
				: $qb->createNamedParameter((bool) $result['retryable'], IQueryBuilder::PARAM_BOOL))
			->set('engine', $qb->createNamedParameter($result['engine'] ?? null))
			->set('pages', $qb->createNamedParameter($result['pages'] ?? null, IQueryBuilder::PARAM_INT))
			->set('chars', $qb->createNamedParameter($result['chars'] ?? null, IQueryBuilder::PARAM_INT))
			->set('error', $qb->createNamedParameter($result['error'] ?? null))
			->set('finished_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->set('claim_token', $qb->createNamedParameter(null))
			->set('claimed_by_worker_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			// Only the worker holding the claim may finish it, and the claim
			// is the token minted at claim time. A stale claim is requeued
			// and can be re-claimed by another worker while the first is
			// still processing (a very large PDF); the late worker's result
			// must then be discarded, not written over the new claimant's
			// row. The caller has already refused a non-matching token with
			// a logged 409; this guard is the database's own say.
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CLAIMED)))
			->andWhere($qb->expr()->eq('claim_token', $qb->createNamedParameter($claimToken)));
		$updated = $qb->executeStatement() === 1;

		if ($updated) {
			$this->recordFile($job, $status, $result);
			// The stage events are part of the same outcome as the job row:
			// they are written only when the guarded UPDATE actually
			// happened, so a worker whose claim was recovered cannot leave
			// stage rows on a job that now belongs to somebody else.
			$this->recordStageEvents((int) $job->getId(), $result['stage_events'] ?? null);
		}

		// Retry is the app's decision, taken here because this is the only place
		// that knows both the policy verdict and the attempt count. The error
		// text is kept on the requeued row: the next failure must not erase the
		// evidence of the first.
		if ($updated && $status === self::STATUS_ERROR && ($result['retryable'] ?? false) === true) {
			$attempts = (int) $job->getAttempts();
			if ($attempts + 1 < self::MAX_ATTEMPTS) {
				$retry = $this->db->getQueryBuilder();
				$retry->update(self::JOBS)
					->set('status', $retry->createNamedParameter(self::STATUS_QUEUED))
					->set('attempts', $retry->createNamedParameter($attempts + 1, IQueryBuilder::PARAM_INT))
					->set('claim_token', $retry->createNamedParameter(null))
					->set('claimed_by_worker_id', $retry->createNamedParameter(null, IQueryBuilder::PARAM_INT))
					->set('claimed_at', $retry->createNamedParameter(null))
					->set('finished_at', $retry->createNamedParameter(null))
					->where($retry->expr()->eq('id', $retry->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$retry->executeStatement();
				// The per-file row is what the UI and the status endpoint read.
				// Leaving it on `error` while the job is queued again is two
				// answers to one question, so the row follows the job.
				$this->recordFile($job, self::STATUS_QUEUED, $result);
			}
		}

		return $updated;
	}

	/**
	 * Write the per-stage events the worker reported.
	 *
	 * The events arrive as the decoded `stage_events` payload. Everything
	 * inadmissible is dropped by {@see StageEvents::normalize()} before it
	 * gets here: an unknown stage name never reaches the table, and a reason
	 * code we do not know is recorded as `unknown`.
	 *
	 * @throws Exception
	 */
	private function recordStageEvents(int $jobId, mixed $raw): void
	{
		$rows = $this->stageEvents->normalize($raw);
		if ($rows === []) {
			return;
		}

		$this->insertStageRows($jobId, $rows);
	}

	/**
	 * Write the stage rows with ONE multi-row INSERT per chunk.
	 *
	 * The QueryBuilder cannot express a multi-row INSERT (`values()` maps
	 * columns of a single row), so the statement is built by hand and run
	 * on the connection, which replaces the `*PREFIX*` placeholder the
	 * same way the builder does. Everything else is unchanged from the
	 * per-row path: the same columns in the same order, one `created_at`
	 * per row, and the write still happens after — and only after — the
	 * guarded UPDATE on the job row that {@see Queue::finish()} performs,
	 * so a worker whose claim was recovered cannot leave stage rows on a
	 * job that now belongs to somebody else.
	 *
	 * Failure isolation: if the batched statement fails (a constraint a
	 * future schema adds, a transient error), the chunk is retried one row
	 * per statement and only a row that fails on its own is dropped, with
	 * a warning. The per-row loop could not lose a whole chunk to one bad
	 * row, and neither may the batch.
	 *
	 * @param list<array{stage: string, reason_code: ?string, duration_ms: ?int, cost_pages: ?int}> $rows
	 * @throws Exception
	 */
	private function insertStageRows(int $jobId, array $rows): void
	{
		$columns = ['job_id', 'stage', 'reason_code', 'duration_ms', 'cost_pages', 'created_at'];
		$tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

		foreach (array_chunk($rows, self::STAGE_INSERT_CHUNK) as $chunk) {
			$sql = 'INSERT INTO *PREFIX*' . self::STAGE_EVENTS
				. ' (' . implode(', ', $columns) . ') VALUES '
				. implode(', ', array_fill(0, count($chunk), $tuple));

			$params = [];
			$types = [];
			foreach ($chunk as $row) {
				$params[] = $jobId;
				$types[] = IQueryBuilder::PARAM_INT;
				$params[] = $row['stage'];
				$types[] = IQueryBuilder::PARAM_STR;
				$params[] = $row['reason_code'];
				$types[] = IQueryBuilder::PARAM_STR;
				$params[] = $row['duration_ms'];
				$types[] = IQueryBuilder::PARAM_INT;
				$params[] = $row['cost_pages'];
				$types[] = IQueryBuilder::PARAM_INT;
				// A DATETIME column: the driver wants the object, not a
				// formatted string, or the value lands as "0000-00-00".
				$params[] = new \DateTime();
				$types[] = IQueryBuilder::PARAM_DATETIME_MUTABLE;
			}

			try {
				$this->db->executeStatement($sql, $params, $types);
			} catch (\Exception $batchFailure) {
				$this->logger?->warning(
					'ocrflow: the batched stage insert for job ' . $jobId . ' failed ('
					. $batchFailure->getMessage() . '); retrying the rows one by one',
				);
				foreach ($chunk as $row) {
					try {
						$this->insertStageRow($jobId, $row);
					} catch (\Exception $rowFailure) {
						$this->logger?->warning(
							'ocrflow: stage row for job ' . $jobId . ', stage ' . $row['stage']
							. ', could not be written (' . $rowFailure->getMessage() . ')',
						);
					}
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $result
	 * @throws Exception
	 */
	private function recordFile(Job $job, string $status, array $result): void
	{
		if ($job->getFileId() === null) {
			return;
		}

		// The ledger `reason` carries the vocabulary CODE, not the worker's
		// free text: the code is the machine verdict enqueue matches against
		// after the job row is purged, and free text cannot be matched. The
		// free text stays on the job row, where the status endpoint reads it.
		// Codes are written in the NEW vocabulary: an old-spelling code from
		// a pre-0.1.5 worker is canonicalised on the way in, so the ledger
		// never grows another old name.
		$reason = $result['reason_code'] ?? null;
		if (is_string($reason)) {
			$reason = $this->retryPolicy->canonical($reason);
		} elseif ($reason === null && isset($result['reason']) && is_string($result['reason'])) {
			$reason = $result['reason'];
		}
		$this->writeLedger(
			$job->getFileId(),
			$job->getPath(),
			$job->getSize(),
			$job->getEtag(),
			$status,
			$reason,
			$result['engine'] ?? null,
			$result['engine_version'] ?? null,
			$result['stamp_version'] ?? null,
		);
	}

	/**
	 * Write (or correct) the per-file ledger row. Retention never deletes
	 * from this table: the row is the permanent verdict, so every path that
	 * reaches a terminal decision for a file must land here.
	 *
	 * @throws Exception
	 */
	private function writeLedger(
		int $fileId,
		string $path,
		?int $size,
		?string $etag,
		string $status,
		?string $reason,
		?string $engine,
		?string $engineVersion,
		?string $stampVersion,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::FILES)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$exists = $qb->executeQuery()->fetch() !== false;

		// Parameters belong to the builder that created them. Binding them on the
		// SELECT builder and executing the INSERT/UPDATE on another raises
		// "Named parameter dcValue2 does not have a bound value" at run time —
		// which made every finished job answer 500, on the success path as well
		// as the failure path. Caught by the integration smoke; no unit test can
		// see it, because it is a property of the query builder, not of our data.
		$w = $this->db->getQueryBuilder();
		$values = [
			'status' => $w->createNamedParameter($status),
			'reason' => $w->createNamedParameter($reason),
			'engine' => $w->createNamedParameter($engine),
			// What the engine bar is compared against: a file OCR'd below
			// the bar is a redo candidate, not a frozen verdict.
			'engine_version' => $w->createNamedParameter($engineVersion),
			'stamp_version' => $w->createNamedParameter($stampVersion),
			'path' => $w->createNamedParameter($path),
			'size' => $w->createNamedParameter($size, IQueryBuilder::PARAM_INT),
			'etag' => $w->createNamedParameter($etag),
			'updated_at' => $w->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		];
		if ($exists) {
			$w->update(self::FILES);
			foreach ($values as $column => $param) {
				$w->set($column, $param);
			}
			$w->where($w->expr()->eq('file_id', $w->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		} else {
			$w->insert(self::FILES)
				->values($values + [
					'file_id' => $w->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				]);
		}
		$w->executeStatement();
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
	public function requeueStale(int $heartbeatStaleSeconds): int
	{
		$cutoff = new \DateTime('@' . (time() - $heartbeatStaleSeconds));
		$select = $this->db->getQueryBuilder();
		$select->select('j.id')
			->from(self::JOBS, 'j')
			->leftJoin('j', 'ocrflow_workers', 'w', $select->expr()->eq('w.id', 'j.claimed_by_worker_id'))
			->where($select->expr()->eq('j.status', $select->createNamedParameter(self::STATUS_CLAIMED)))
			->andWhere($select->expr()->orX(
				$select->expr()->isNull('j.claimed_by_worker_id'),
				$select->expr()->isNull('w.id'),
				// The type parameter is not decoration: without it Doctrine
				// binds the DateTime as a string and the whole reconcile
				// tick dies with "Object of class DateTime could not be
				// converted to string" — caught by the integration smoke,
				// not by any unit test.
				$select->expr()->lt('w.last_seen_at', $select->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			));
		$ids = [];
		foreach ($select->executeQuery()->fetchAll() as $row) {
			$ids[] = (int) $row['id'];
		}
		if ($ids === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::JOBS)
			->set('status', $qb->createNamedParameter(self::STATUS_QUEUED))
			->set('claim_token', $qb->createNamedParameter(null))
			->set('claimed_by_worker_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
			->set('claimed_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CLAIMED)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $qb->executeStatement();
	}

	/**
	 * @return array<string, int>
	 * @throws Exception
	 */
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
	private function purgeablePredicate(IQueryBuilder $qb): ICompositeExpression
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
	 * Ids of stage rows whose job is gone and that predate the cutoff.
	 *
	 * @return list<int>
	 * @throws Exception
	 */
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

	/**
	 * @param list<int> $jobIds
	 * @return list<int>
	 * @throws Exception
	 */
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

	/**
	 * @param list<int> $ids
	 * @throws Exception
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
}
