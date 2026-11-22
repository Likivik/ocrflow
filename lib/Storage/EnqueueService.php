<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Storage;

use OCA\OcrFlow\Service\EnginePolicy;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The write policy: may this file become a job, and under which engine.
 *
 * Six refusals live here, and every one is a decision rather than an error: no
 * path, no file id, still live, already done at this version, a verdict that
 * says do not try again, or a unique-key race another worker won. In one place
 * because a refusal that differs between the scan door and the panel door is
 * the bug this class prevents.
 */
class EnqueueService
{
	private const JOBS = 'ocrflow_jobs';
	private const STATUS_QUEUED = JobStore::STATUS_QUEUED;

	public function __construct(
		private IDBConnection $db,
		private JobStore $jobs,
		private Ledger $ledger,
		private SettlementService $settlement,
		private ?EnginePolicy $enginePolicy = null,
		private ?Batch $batchService = null,
		private ?LoggerInterface $logger = null,
	) {
	}

	private function batch(): Batch
	{
		return $this->batchService ??= new Batch(new DiskFiles($this->db), $this->ledger, $this->jobs);
	}

	public function enqueue(
		string $path,
		?int $fileId,
		?int $size,
		?string $etag,
		?string $engine = null,
		bool $fromScan = false,
	): ?int {
		// A zero-byte source is a data problem, not work: the worker can only fetch
		// an empty stream and fail after transport — the QueueProtocolError class
		// that died four times in production. This must run BEFORE the dedupe
		// guards below: `jobForVersion()` returns any row for this version,
		// including the `error` row written the last time this file was queued,
		// so the correction would otherwise never be recorded.
		if ($size !== null && $size <= 0) {
			$this->settlement->recordEmptySource($path, $fileId, $size, $etag);
			return null;
		}

		if ($fileId !== null) {
			if ($this->jobs->jobForVersion($fileId, $etag) !== null) {
				return null;
			}
			if ($this->jobs->isDoneForVersion($fileId, $etag)) {
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
			$permanentReason = $this->ledger->permanentFailureForVersion($fileId, $etag);
			if ($permanentReason !== null) {
				// One line, not silence: an operator must be able to see why
				// a scan keeps skipping a file, and how to override it.
				$this->logger?->info(
					'ocrflow: file skipped: permanent failure (' . $permanentReason . ')'
					. ' for ' . $path . '; occ ocrflow:requeue "' . $path . '" to retry anyway',
				);
				return null;
			}
			// Settled, not permanent: these bytes were already written by us.
			// The ledger row carries the FACTS (engine, engine_version as
			// observed at write time); the verdict is derived HERE, against
			// the bar that is current now — never frozen into the row. See
			// EnginePolicy::stampVerdict(); the comparison is not re-implemented.
			$settled = $this->ledger->settledForVersion($fileId, $etag);
			if ($settled !== null && $this->enginePolicy !== null) {
				$verdict = $this->enginePolicy->stampVerdict($settled['engine'], $settled['engine_version']);
				if ($verdict === 'nothing_to_do') {
					$this->logger?->info(
						'ocrflow: file skipped: already OCR\'d by ' . $settled['engine'] . ' ' . $settled['engine_version']
						. ' (at or above the bar) for ' . $path,
					);
				} else {
					// Below the bar: a redo candidate the owner picks from,
					// never an automatic re-OCR. No row is written: the bar
					// that judged this row may move again tomorrow.
					$this->logger?->info(
						'ocrflow: file skipped: OCR\'d below the bar by ' . $settled['engine'] . ' ' . $settled['engine_version']
						. ' for ' . $path . '; redo candidate, never automatic; occ ocrflow:requeue "' . $path . '" to OCR it anyway',
					);
				}
				return null;
			}
			// The per-file loop breaker, scan-initiated enqueues only. Job
			// rows are UNIQUE (file_id, etag) and retention purges them, so
			// they cannot count; the count lives in the never-purged ledger.
			// Past the FIRST scan enqueue for identical bytes the file is
			// forced to needs_fix with error_queued_twice_for_same_bytes —
			// a loop is a defect to read about, not a bill to pay. The
			// operator override resets the counter and stays exempt.
			if ($fromScan && $this->jobs->alreadyEnqueuedForVersion($fileId, $etag)) {
				$this->settlement->recordQueuedTwice($path, $fileId, $size, $etag);
				return null;
			}
		} elseif ($this->jobs->findByPath($path) !== null) {
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

		// The loop breaker's count: only scan-initiated enqueues touch it,
		// so an upload-event enqueue (a genuinely new user action) is never
		// held against the file. The ledger's status/verdict columns are
		// deliberately NOT touched — the verdict is written when the job
		// finishes, not when it is queued.
		if ($fromScan && $fileId !== null) {
			$this->jobs->countScanEnqueue($fileId, $path, $size, $etag);
		}

		// `IDBConnection::getLastInsertId()` was removed in Nextcloud 34; the
		// query builder owns it now. Calling it on the connection threw
		// "Call to undefined method ConnectionAdapter::getLastInsertId()" on every
		// upload — the row landed, the listener logged a failure and returned no
		// id, so nothing downstream could tell the job it had just created.
		return $qb->getLastInsertId();
	}

	public function enqueueNeverProcessed(string $uid, int $limit, ?string $engine): array
	{
		$candidates = $this->batch()->neverProcessedFor($uid, $limit);
		$queued = 0;
		$refused = 0;
		$force = [];

		foreach ($candidates['ready'] as $row) {
			$id = $this->enqueue($row['path'], $row['fileId'], $row['size'], $row['etag'], $engine);
			if ($id !== null) {
				$queued++;
				continue;
			}
			if ($row['jobId'] !== null) {
				$force[] = $row['jobId'];
				continue;
			}
			$refused++;
		}

		if ($force !== []) {
			$result = $this->jobs->forceRequeue($force);
			$queued += count($result['requeued']);
			$refused += count($result['refused']);
		}

		return [
			'queued' => $queued,
			'refused' => $refused,
			// Exact for this run: the files just queued now hold a live row and
			// are no longer candidates.
			'remaining' => max(0, $candidates['readyTotal'] - $queued),
			'young' => $candidates['young'],
		];
	}
}
