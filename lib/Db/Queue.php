<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Db;

use OCA\OcrFlow\Service\StageEvents;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
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

	public function __construct(
		IDBConnection $db,
		private StageEvents $stageEvents,
		private ?LoggerInterface $logger = null,
	) {
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
		$existing = $fileId === null ? $this->findByPath($path) : $this->jobForVersion($fileId, $etag);
		if ($existing !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::JOBS)
				->set('status', $qb->createNamedParameter('skipped'))
				->set('reason', $qb->createNamedParameter('empty_source'))
				->set('reason_code', $qb->createNamedParameter('empty_source'))
				->set('retryable', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
				->set('error', $qb->createNamedParameter(null))
				->set('claimed_by', $qb->createNamedParameter(null))
				->set('finished_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $existing->getId(), IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			$this->recordDecision((int) $existing->getId(), 'empty_source');
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::JOBS)
			->values([
				'path' => $qb->createNamedParameter($path),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'status' => $qb->createNamedParameter('skipped'),
				'reason' => $qb->createNamedParameter('empty_source'),
				'reason_code' => $qb->createNamedParameter('empty_source'),
				'retryable' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'finished_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
				'etag' => $qb->createNamedParameter($etag),
			]);
		$qb->executeStatement();
		$this->recordDecision((int) $qb->getLastInsertId(), 'empty_source');
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
	public function claim(string $worker): ?Job
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

			$up = $this->db->getQueryBuilder();
			$up->update(self::JOBS)
				->set('status', $up->createNamedParameter(self::STATUS_CLAIMED))
				->set('claimed_by', $up->createNamedParameter($worker))
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
	 *              engine?: ?string, pages?: ?int,
	 *              chars?: ?int, stamp_version?: ?string, error?: ?string,
	 *              stage_events?: mixed} $result
	 * @throws Exception
	 */
	public function finish(int $id, array $result, string $worker): bool
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
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			// Only the worker holding the claim may finish it. A stale claim
			// older than the recovery window is requeued and can be re-claimed
			// by another worker while the first is still processing (a very
			// large PDF); the late worker's result must then be discarded,
			// not written over the new claimant's row.
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CLAIMED)))
			->andWhere($qb->expr()->eq('claimed_by', $qb->createNamedParameter($worker)));
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
					->set('claimed_by', $retry->createNamedParameter(null))
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

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::FILES)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($job->getFileId(), IQueryBuilder::PARAM_INT)))
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
			'reason' => $w->createNamedParameter($result['reason'] ?? null),
			'engine' => $w->createNamedParameter($result['engine'] ?? null),
			'stamp_version' => $w->createNamedParameter($result['stamp_version'] ?? null),
			'path' => $w->createNamedParameter($job->getPath()),
			'size' => $w->createNamedParameter($job->getSize(), IQueryBuilder::PARAM_INT),
			'etag' => $w->createNamedParameter($job->getEtag()),
			'updated_at' => $w->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		];
		if ($exists) {
			$w->update(self::FILES);
			foreach ($values as $column => $param) {
				$w->set($column, $param);
			}
			$w->where($w->expr()->eq('file_id', $w->createNamedParameter($job->getFileId(), IQueryBuilder::PARAM_INT)));
		} else {
			$w->insert(self::FILES)
				->values($values + [
					'file_id' => $w->createNamedParameter($job->getFileId(), IQueryBuilder::PARAM_INT),
				]);
		}
		$w->executeStatement();
	}

	/**
	 * Put a claimed job back in the queue — used when a worker dies mid-job and
	 * its claim goes stale.
	 *
	 * @throws Exception
	 */
	public function requeueStale(int $olderThanSeconds): int
	{
		$cutoff = time() - $olderThanSeconds;
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::JOBS)
			->set('status', $qb->createNamedParameter(self::STATUS_QUEUED))
			->set('claimed_by', $qb->createNamedParameter(null))
			->set('claimed_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CLAIMED)))
			->andWhere($qb->expr()->lt('claimed_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
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
}
