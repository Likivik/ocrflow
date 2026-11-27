<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Storage;

use OCA\OcrFlow\Service\EnginePolicy;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
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
		// Only used by the identity ladder's hash tier. Last argument on
		// purpose: positional callers keep working.
		private ?\OCA\OcrFlow\Service\FileExchange $exchange = null,
		// Durable observations. Optional only for tests and exceptional
		// callers without a file id; every normal event path has it.
		private ?PendingFileStore $pendingFiles = null,
		// Current-node lookup for a deferred observation. Optional so
		// worker-plane and unit callers can keep refusing to invent paths.
		private ?IRootFolder $rootFolder = null,
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
		if ($fileId !== null && $this->pendingFiles !== null) {
			if (!$fromScan) {
				// Commit desired work before any decision. A crash between
				// here and the insert leaves a durable observation for
				// Reconcile, not a lost upload.
				$this->pendingFiles->observe($fileId, $path, $etag, $size);
				return $this->drainPending($fileId, $engine);
			}
			return $this->enqueueKnown($path, $fileId, $size, $etag, $engine, true);
		}

		return $this->enqueueKnown($path, $fileId, $size, $etag, $engine, $fromScan);
	}

	/**
	 * Make one enqueue decision for the latest observation of a file.
	 *
	 * The snapshot revision is a compare-and-swap fence on the final delete:
	 * an observation that arrives while this drain is working survives for
	 * the next drain.
	 */
	public function drainPending(int $fileId, ?string $engine = null): ?int
	{
		if ($this->pendingFiles === null) {
			return null;
		}
		$pending = $this->pendingFiles->get($fileId);
		if ($pending === null) {
			return null;
		}

		if ($this->rootFolder !== null) {
			try {
				$nodes = $this->rootFolder->getById($fileId);
			} catch (\Throwable $e) {
				$this->logger?->warning('ocrflow: pending file ' . $fileId . ' could not be resolved; keeping the observation', ['exception' => $e]);
				return null;
			}
			$node = $nodes[0] ?? null;
			if ($node === null) {
				// The file was deleted while its observation waited. Clear only
				// the revision that was read; a newer row survives.
				$this->pendingFiles->deleteIfRevision($fileId, $pending['revision']);
				return null;
			}
			$pending['path'] = $node->getPath();
			$pending['etag'] = $node->getEtag();
			$pending['size'] = $node->getSize();
		}

		return $this->enqueueKnown(
			$pending['path'],
			$fileId,
			$pending['size'],
			$pending['etag'],
			$engine,
			false,
			$pending['revision'],
		);
	}

	private function enqueueKnown(
		string $path,
		?int $fileId,
		?int $size,
		?string $etag,
		?string $engine,
		bool $fromScan,
		?int $pendingRevision = null,
	): ?int {
		if ($fileId !== null && $this->jobs->claimedForFile($fileId)) {
			// Work owns the file. Remember the latest observation, including
			// a zero-byte one; it is judged after the claim releases. A
			// pending drain already committed its revision, so only a scan
			// observation needs recording here.
			if ($pendingRevision === null) {
				$this->pendingFiles?->observe($fileId, $path, $etag, $size);
			}
			return null;
		}

		// A zero-byte source is a data problem, not work: the worker can only fetch
		// an empty stream and fail after transport — the QueueProtocolError class
		// that died four times in production. This must run BEFORE the dedupe
		// guards below: `jobForVersion()` returns any row for this version,
		// including the `error` row written the last time this file was queued,
		// so the correction would otherwise never be recorded.
		if ($size !== null && $size <= 0) {
			$this->settlement->recordEmptySource($path, $fileId, $size, $etag);
			$this->clearPending($fileId, $pendingRevision);
			return null;
		}

		if ($fileId !== null) {
			if ($this->jobs->jobForVersion($fileId, $etag) !== null) {
				$this->clearPending($fileId, $pendingRevision);
				return null;
			}
			if ($this->jobs->isDoneForVersion($fileId, $etag)) {
				$this->clearPending($fileId, $pendingRevision);
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
				$this->clearPending($fileId, $pendingRevision);
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
				$this->clearPending($fileId, $pendingRevision);
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
				$this->clearPending($fileId, $pendingRevision);
				return null;
			}
		} elseif ($this->jobs->findByPath($path) !== null) {
			// No file id (a path from outside the cache): the path is all we
			// have to go on, so keep the old, weaker rule.
			return null;
		}

		// Tier 3: the etag moved but the bytes may not have. A lock bump, a
		// metadata-only rewrite, a re-upload of identical bytes — all of them
		// leave `settled_sha256` equal to what is on disk. Comparing content
		// is the only answer that survives an etag nobody controls.
		if ($fileId !== null && $this->exchange !== null) {
			$identity = $this->ledger->identityFor($fileId);
			if ($identity !== null && is_string($identity['settled_sha256']) && $identity['settled_sha256'] !== '') {
				$owner = \OCA\OcrFlow\Service\UserScope::ownerOf($path);
				$current = null;
				if ($owner !== null) {
					$marker = $owner . '/files/';
					$pos = strpos($path, $marker);
					if ($pos !== false) {
						$current = $this->exchange->contentSha256($owner, substr($path, $pos + strlen($marker)));
					}
				}
				if ($current !== null && hash_equals($identity['settled_sha256'], $current)) {
					$this->logger?->info('ocrflow: loop_guard_tripped', [
						'file_id' => $fileId,
						'tier' => $identity['settled_under_lock'] === true ? 2 : 3,
					]);
					$this->clearPending($fileId, $pendingRevision);
					return null;
				}
			}
		}

		// The baseline the write ladder compares against: the hash of the bytes
		// as they are *now*, recorded with the version that names them. Hashing
		// later — at claim, or when the worker downloads — measures whatever is
		// on disk at that moment, which is not the version this job is about.
		$sourceHash = null;
		if ($fileId !== null && $this->exchange !== null) {
			$owner = \OCA\OcrFlow\Service\UserScope::ownerOf($path);
			if ($owner !== null) {
				$marker = $owner . '/files/';
				$pos = strpos($path, $marker);
				if ($pos !== false) {
					$sourceHash = $this->exchange->contentSha256($owner, substr($path, $pos + strlen($marker)));
				}
			}
		}

		if ($fileId !== null) {
			// The latest observation wins over work nobody has claimed. If a
			// worker claims first, this delete affects zero rows and the
			// pending observation stays for the next drain.
			$this->jobs->supersedeQueued($fileId);
			if ($this->jobs->claimedForFile($fileId)) {
				$this->pendingFiles?->observe($fileId, $path, $etag, $size);
				return null;
			}
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
				'source_sha256' => $qb->createNamedParameter($sourceHash),
			]);
		try {
			$qb->executeStatement();
		} catch (Exception $e) {
			// A concurrent insert won the unique(path) race. Same answer as
			// "already queued": nothing for this caller to do.
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$this->clearPending($fileId, $pendingRevision);
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

		$this->clearPending($fileId, $pendingRevision);

		// `IDBConnection::getLastInsertId()` was removed in Nextcloud 34; the
		// query builder owns it now. Calling it on the connection threw
		// "Call to undefined method ConnectionAdapter::getLastInsertId()" on every
		// upload — the row landed, the listener logged a failure and returned no
		// id, so nothing downstream could tell the job it had just created.
		return $qb->getLastInsertId();
	}

	/**
	 * Drain a bounded snapshot of durable observations. Reconcile calls this;
	 * immediate upload paths drain their own file directly.
	 *
	 * @return array{drained: int, deferred: int, refused: int}
	 */
	public function drainPendingBatch(int $limit = 500, ?string $engine = null): array
	{
		if ($this->pendingFiles === null) {
			return ['drained' => 0, 'deferred' => 0, 'refused' => 0];
		}

		$out = ['drained' => 0, 'deferred' => 0, 'refused' => 0];
		foreach ($this->pendingFiles->ids($limit) as $fileId) {
			if ($this->drainPending($fileId, $engine) !== null) {
				$out['drained']++;
			} elseif ($this->pendingFiles->get($fileId) !== null) {
				$out['deferred']++;
			} else {
				$out['refused']++;
			}
		}
		return $out;
	}

	private function clearPending(?int $fileId, ?int $pendingRevision): void
	{
		if ($fileId !== null && $pendingRevision !== null) {
			$this->pendingFiles?->deleteIfRevision($fileId, $pendingRevision);
		}
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
