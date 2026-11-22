<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Storage;

use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The settle transaction: one result, three tables.
 *
 * A finished job changes the run log (`ocrflow_jobs`), the file's verdict
 * (`ocrflow_files`) and the diagnostic trail (`ocrflow_stage_events`). Written
 * from three places those can disagree; written from one they cannot. This is
 * that one place, and it owns the decision too: the guarded UPDATE on the claim
 * token, the ledger row, the stage rows, and the retry.
 */
class SettlementService
{
	private const STATUS_QUEUED = JobStore::STATUS_QUEUED;
	private const STATUS_CLAIMED = JobStore::STATUS_CLAIMED;
	private const STATUS_ERROR = JobStore::STATUS_ERROR;
	private const STATUS_SKIPPED = JobStore::STATUS_SKIPPED;
	private const STATUS_DONE = JobStore::STATUS_DONE;

	/** Attempts after which a retryable failure stays failed. */
	private const MAX_ATTEMPTS = 3;

	private const JOBS = 'ocrflow_jobs';
	private const STAGE_EVENTS = 'ocrflow_stage_events';
	private const STAGE_INSERT_CHUNK = 500;

	public function __construct(
		private IDBConnection $db,
		private JobStore $jobs,
		private Ledger $ledger,
		private RetryPolicy $retryPolicy,
		private ?LoggerInterface $logger = null,
		private ?StageEvents $stageEvents = null,
	) {
	}


	public function finish(int $id, array $result, string $claimToken): bool
	{
		$job = $this->jobs->getById($id);
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
			->set('claim_expires_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
			// The operator's one-off decision ends with the job it was taken
			// for: a force bit left on the row would make the NEXT ordinary
			// scan of this file bypass the skip bar too, which nobody asked for.
			->set('force', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
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
			$this->ledger->recordFile($job, $status, $result);
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
				$this->ledger->recordFile($job, self::STATUS_QUEUED, $result);
			}
		}

		return $updated;
	}

	public function settleProduced(
		int $fileId,
		string $path,
		?int $size,
		?string $etag,
		?string $engine,
		?string $engineVersion,
	): void {
		$this->ledger->writeLedger($fileId, $path, $size, $etag, self::STATUS_SKIPPED, 'skip_ocred_by_ocrflow', $engine, $engineVersion, null, settle: true);
	}

	public function recordDecision(int $jobId, string $reasonCode): void
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

	public function recordEmptySource(string $path, ?int $fileId, ?int $size, ?string $etag): void
	{
		// The zero-byte verdict goes to the ledger too, not only to the job
		// row: retention will purge the job row, and the file is still zero
		// bytes — the verdict must survive so the next scan does not have to
		// rediscover it (and so the block stays explainable).
		if ($fileId !== null) {
			$this->ledger->writeLedger($fileId, $path, $size, $etag, self::STATUS_SKIPPED, 'error_source_file_zero_bytes', null, null, null);
		}

		$existing = $fileId === null ? $this->jobs->findByPath($path) : $this->jobs->jobForVersion($fileId, $etag);
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

	public function recordQueuedTwice(string $path, int $fileId, ?int $size, ?string $etag): void
	{
		$this->ledger->writeLedger($fileId, $path, $size, $etag, self::STATUS_ERROR, 'error_queued_twice_for_same_bytes', null, null, null);

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::JOBS)
			->values([
				'path' => $qb->createNamedParameter($path),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'status' => $qb->createNamedParameter(self::STATUS_ERROR),
				'reason' => $qb->createNamedParameter('error_queued_twice_for_same_bytes'),
				'reason_code' => $qb->createNamedParameter('error_queued_twice_for_same_bytes'),
				'retryable' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'finished_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
				'etag' => $qb->createNamedParameter($etag),
			]);
		$qb->executeStatement();
		$this->recordDecision((int) $qb->getLastInsertId(), 'error_queued_twice_for_same_bytes');

		// The line names the file, the etag and the code, so an operator
		// sees the loop rather than silent inactivity.
		$this->logger?->warning(
			'ocrflow: file refused: queued twice for the same bytes (etag ' . $etag . ')'
			. ' for ' . $path . ' (error_queued_twice_for_same_bytes)'
			. '; occ ocrflow:requeue "' . $path . '" to queue it once more',
		);
	}

	public function insertStageRow(int $jobId, array $row): void
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

	public function hasStageRow(int $jobId, string $stage, string $reasonCode): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::STAGE_EVENTS)
			->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('stage', $qb->createNamedParameter($stage)))
			->andWhere($qb->expr()->eq('reason_code', $qb->createNamedParameter($reasonCode)))
			->setMaxResults(1);
		return $qb->executeQuery()->fetch() !== false;
	}

	public function recordStageEvents(int $jobId, mixed $raw): void
	{
		$rows = $this->stageEvents->normalize($raw);
		if ($rows === []) {
			return;
		}

		$this->insertStageRows($jobId, $rows);
	}

	public function insertStageRows(int $jobId, array $rows): void
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
}
