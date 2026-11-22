<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Db;

use OCA\OcrFlow\Service\EnginePolicy;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\SettlementService;
use OCA\OcrFlow\Storage\Ledger;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
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
	public const STATUS_QUEUED = JobStore::STATUS_QUEUED;

	/** Attempts after which a retryable failure stays failed. */
	private const MAX_ATTEMPTS = 3;
	public const STATUS_CLAIMED = JobStore::STATUS_CLAIMED;
	public const STATUS_DONE = JobStore::STATUS_DONE;
	public const STATUS_SKIPPED = JobStore::STATUS_SKIPPED;
	public const STATUS_ERROR = JobStore::STATUS_ERROR;

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

	/**
	 * How long a claim is valid without a renewal.
	 *
	 * Long enough that a slow page does not lose its job between renewals,
	 * short enough that an orphaned claim (worker killed, report lost to a 502)
	 * is back in the queue while the person is still looking at the panel.
	 */

	/**
	 * How many ids one `IN (...)` may carry. Nextcloud's query builder refuses
	 * more than 1000 expressions for Oracle's sake — and it refuses them on every
	 * driver, so a 2 000-wide chunk is a 500, not a wide query.
	 */
	private const IN_CHUNK = 900;
	public const CLAIM_LEASE_SECONDS = JobStore::CLAIM_LEASE_SECONDS;

	/**
	 * Absolute ceiling for one claim, renewals included.
	 *
	 * A worker that renews forever on a job that will never finish is
	 * indistinguishable from one making progress, so the lease alone cannot
	 * catch it. This cap can — and it is the only rule allowed to interrupt a
	 * legitimately slow file.
	 */
	public const CLAIM_HARD_CAP_SECONDS = JobStore::CLAIM_HARD_CAP_SECONDS;

	/** Ids per chunked retention DELETE; small enough to keep locks short. */
	private const DELETE_CHUNK = 500;

	private readonly RetryPolicy $retryPolicy;

	private ?Ledger $ledgerStore = null;

	private ?JobStore $jobStore = null;

	private ?SettlementService $settlementService = null;

	private StageEvents $stageEvents;

	private ?EnginePolicy $enginePolicy;

	public function __construct(
		IDBConnection $db,
		StageEvents $stageEvents,
		private ?LoggerInterface $logger = null,
		?RetryPolicy $retryPolicy = null,
		?EnginePolicy $enginePolicy = null,
		private ?IConfig $config = null,
	) {
		$this->stageEvents = $stageEvents;
		// The retry table already exists and has one owner; enqueue only asks
		// it a second question (is this verdict permanent?), so it is the
		// same policy object rather than a second vocabulary.
		$this->retryPolicy = $retryPolicy ?? new RetryPolicy();
		// The engine bar, for the settled check below. Optional only so the
		// unit harnesses that construct Queue directly keep working; the
		// container always injects one, and without it the settled rows
		// cannot be judged (there is no bar to compare against).
		$this->enginePolicy = $enginePolicy;
		parent::__construct($db, self::JOBS, Job::class);
	}

	/**
	 * The ledger, built on first use. A lazy accessor rather than a constructor
	 * argument because the ledger needs only the connection and the retry
	 * policy — both already here — and because Queue's own constructor is
	 * frozen by four test harnesses until Phase 2c deletes it.
	 */
	private function settlement(): SettlementService
	{
		return $this->settlementService ??= new SettlementService($this->db, $this->jobs(), $this->ledger(), $this->retryPolicy, $this->logger, $this->stageEvents);
	}

	private function jobs(): JobStore
	{
		return $this->jobStore ??= new JobStore($this->db, $this->retryPolicy);
	}

	private function ledger(): Ledger
	{
		return $this->ledgerStore ??= new Ledger($this->db, $this->retryPolicy);
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
		bool $fromScan = false,
	): ?int {
		// A zero-byte source is a data problem, not work: the worker can only fetch
		// an empty stream and fail after transport — the QueueProtocolError class
		// that died four times in production. This must run BEFORE the dedupe
		// guards below: `jobForVersion()` returns any row for this version,
		// including the `error` row written the last time this file was queued,
		// so the correction would otherwise never be recorded.
		if ($size !== null && $size <= 0) {
			$this->settlement()->recordEmptySource($path, $fileId, $size, $etag);
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
			// Settled, not permanent: these bytes were already written by us.
			// The ledger row carries the FACTS (engine, engine_version as
			// observed at write time); the verdict is derived HERE, against
			// the bar that is current now — never frozen into the row. See
			// EnginePolicy::stampVerdict(); the comparison is not re-implemented.
			$settled = $this->settledForVersion($fileId, $etag);
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
			if ($fromScan && $this->jobs()->alreadyEnqueuedForVersion($fileId, $etag)) {
				$this->settlement()->recordQueuedTwice($path, $fileId, $size, $etag);
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

		// The loop breaker's count: only scan-initiated enqueues touch it,
		// so an upload-event enqueue (a genuinely new user action) is never
		// held against the file. The ledger's status/verdict columns are
		// deliberately NOT touched — the verdict is written when the job
		// finishes, not when it is queued.
		if ($fromScan && $fileId !== null) {
			$this->jobs()->countScanEnqueue($fileId, $path, $size, $etag);
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
		$this->settlement()->recordEmptySource($path, $fileId, $size, $etag);
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
		$this->settlement()->recordDecision($jobId, $reasonCode);
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
		$this->settlement()->insertStageRow($jobId, $row);
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
		return $this->settlement()->hasStageRow($jobId, $stage, $reasonCode);
	}

	public function findByPath(string $path): ?Job
	{
		return $this->jobs()->findByPath($path);
	}

	public function jobForVersion(int $fileId, ?string $etag): ?Job
	{
		return $this->jobs()->jobForVersion($fileId, $etag);
	}

	public function isDoneForVersion(int $fileId, ?string $etag): bool
	{
		return $this->jobs()->isDoneForVersion($fileId, $etag);
	}

	public function permanentFailureForVersion(int $fileId, ?string $etag): ?string
	{
		return $this->ledger()->permanentFailureForVersion($fileId, $etag);
	}

	public function settledForVersion(int $fileId, ?string $etag): ?array
	{
		return $this->ledger()->settledForVersion($fileId, $etag);
	}

	private function alreadyEnqueuedForVersion(int $fileId, ?string $etag): bool
	{
		return $this->jobs()->alreadyEnqueuedForVersion($fileId, $etag);
	}

	private function countScanEnqueue(int $fileId, string $path, ?int $size, ?string $etag): void
	{
		$this->jobs()->countScanEnqueue($fileId, $path, $size, $etag);
	}

	public function resetTimesEnqueued(int $fileId): int
	{
		return $this->jobs()->resetTimesEnqueued($fileId);
	}

	/**
	 * Record the verdict where the bytes are written: the produced file is
	 * already done by us, with the engine facts as observed at write time.
	 *
	 * Called by the write-back endpoints (`putFile`, `resultFile`) right
	 * after a successful write, so the ledger answers "is this ours, and
	 * with what" without opening the PDF — the exact round trip job 8135
	 * wasted. Facts only: no bar comparison happens here; `enqueue()`
	 * derives the verdict against the bar that is current THEN.
	 */
	public function settleProduced(
		int $fileId,
		string $path,
		?int $size,
		?string $etag,
		?string $engine,
		?string $engineVersion,
	): void {
		$this->settlement()->settleProduced($fileId, $path, $size, $etag, $engine, $engineVersion);
	}

	/**
	 * The loop breaker firing: the scan tried to queue these exact bytes a
	 * second time. Same shape as `recordEmptySource()` — the verdict goes to
	 * the ledger (survives the purge) and a terminal error row goes to the
	 * jobs table (the needs_fix worklist reads it; `occ ocrflow:requeue`
	 * deletes it and resets the counter).
	 *
	 * @throws Exception
	 */
	private function recordQueuedTwice(string $path, int $fileId, ?int $size, ?string $etag): void
	{
		$this->settlement()->recordQueuedTwice($path, $fileId, $size, $etag);
	}

	public function findFileByPath(string $path): ?array
	{
		return $this->ledger()->findFileByPath($path);
	}

	public function clearPermanentVerdict(int $fileId): int
	{
		return $this->ledger()->clearPermanentVerdict($fileId);
	}

	public function deleteTerminalForFile(int $fileId): int
	{
		return $this->jobs()->deleteTerminalForFile($fileId);
	}

	private function etagPredicate(IQueryBuilder $qb, ?string $etag): string
	{
		return $etag === null
			? $qb->expr()->isNull('etag')
			: $qb->expr()->eq('etag', $qb->createNamedParameter($etag));
	}

	public function deleteQueuedForFile(int $fileId): int
	{
		return $this->jobs()->deleteQueuedForFile($fileId);
	}

	public function idsWithReasonCode(string $code, int $limit): array
	{
		return $this->jobs()->idsWithReasonCode($code, $limit);
	}

	public function forceRequeue(array $ids): array
	{
		return $this->jobs()->forceRequeue($ids);
	}

	public function claim(string $worker, ?int $workerId = null): ?Job
	{
		return $this->jobs()->claim($worker, $workerId);
	}

	public function renewClaim(int $id, string $claimToken): bool
	{
		return $this->jobs()->renewClaim($id, $claimToken);
	}

	public function release(int $id, string $claimToken): bool
	{
		return $this->jobs()->release($id, $claimToken);
	}

	public function getById(int $id): ?Job
	{
		return $this->jobs()->getById($id);
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
		return $this->settlement()->finish($id, $result, $claimToken);
	}

	public function recordSourceHash(int $id, string $sha256): void
	{
		$this->jobs()->recordSourceHash($id, $sha256);
	}

	public function armWrittenEtag(int $id, string $etag): void
	{
		$this->jobs()->armWrittenEtag($id, $etag);
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
		$this->settlement()->recordStageEvents($jobId, $raw);
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
		$this->settlement()->insertStageRows($jobId, $rows);
	}

	/**
	 * @param array<string, mixed> $result
	 * @throws Exception
	 */
	private function recordFile(Job $job, string $status, array $result): void
	{
		$this->ledger()->recordFile($job, $status, $result);
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
		bool $settle = false,
	): void {
		$this->ledger()->writeLedger($fileId, $path, $size, $etag, $status, $reason, $engine, $engineVersion, $stampVersion, $settle);
	}

	public function requeueStale(int $heartbeatStaleSeconds = self::CLAIM_LEASE_SECONDS): int
	{
		return $this->jobs()->requeueStale($heartbeatStaleSeconds);
	}

	/**
	 * @return array<string, int>
	 * @throws Exception
	 */
	public function provenanceCounts(): array
	{
		return $this->ledger()->provenanceCounts();
	}

	public function skippedByReason(): array
	{
		return $this->ledger()->skippedByReason();
	}

	public function errorReasons(): array
	{
		return $this->ledger()->errorReasons();
	}

	private function reasonsForStatus(string $status): array
	{
		return $this->ledger()->reasonsForStatus($status);
	}

	/**
	 * The extensions the pipeline can actually read.
	 *
	 * Must match the worker's `PROCESSABLE_EXTS` (`webhook_server.py:126`), or
	 * the report promises work that the worker will refuse.
	 */
	public const READABLE_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];



	/**
	 * The files a "process them" click would work on, and the ones it must not
	 * touch yet.
	 *
	 * Candidates are the panel's own definition of "not processed": in our
	 * readable extensions, not empty, owned by this user, no live row, and
	 * either no ledger row at all or a `error_worker_file_gone` one (the file
	 * came back — D13). `ready` excludes files modified inside the quiescence
	 * window, the same rule a folder scan applies, so a file still being
	 * uploaded is not queued twice with two different etags.
	 *
	 * `jobId` is the terminal job row for that file when one survived retention:
	 * it is the only handle `forceRequeue()` accepts, and the only way back into
	 * the queue for a file whose etag already has a row.
	 *
	 * @return array{ready: list<array{fileId: int, jobId: ?int, path: string, size: int, etag: string}>, readyTotal: int, young: int}
	 */
	public function neverProcessedFor(string $uid, int $limit): array
	{
		$limit = max(1, $limit);
		$ids = array_keys($this->diskFileIds($uid));
		if ($ids === []) {
			return ['ready' => [], 'readyTotal' => 0, 'young' => 0];
		}

		$ledger = $this->ledgerRowsFor($ids);
		$live = $this->liveQueueFor($ids);

		$ghosts = [];
		$plain = [];
		foreach ($ids as $fileId) {
			if (isset($live[$fileId])) {
				continue;
			}
			if (!isset($ledger[$fileId])) {
				$plain[$fileId] = true;
				continue;
			}
			// A ledger row means settled — except `file_gone`, which means the
			// file was not there to read and is here now.
			if (($ledger[$fileId]['reason'] ?? '') === 'error_worker_file_gone') {
				$ghosts[$fileId] = true;
			}
		}

		$candidates = array_keys($plain + $ghosts);
		if ($candidates === []) {
			return ['ready' => [], 'readyTotal' => 0, 'young' => 0];
		}

		$rows = $this->fileRowsFor($candidates);
		$cutoff = time() - $this->scanMinAgeSeconds();

		$ready = [];
		$young = 0;
		foreach ($rows as $row) {
			if ((int) $row['mtime'] > $cutoff) {
				$young++;
				continue;
			}
			$ready[] = $row;
		}
		usort($ready, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

		$readyTotal = count($ready);
		$ready = array_slice($ready, 0, $limit);

		$jobIds = [];
		$ghostIds = array_values(array_intersect(array_column($ready, 'fileId'), array_keys($ghosts)));
		if ($ghostIds !== []) {
			$jobIds = $this->terminalJobIdsFor($ghostIds);
		}

		$out = [];
		foreach ($ready as $row) {
			$fileId = (int) $row['fileId'];
			$out[] = [
				'fileId' => $fileId,
				'jobId' => $jobIds[$fileId] ?? null,
				'path' => (string) $row['path'],
				'size' => (int) $row['size'],
				'etag' => (string) $row['etag'],
			];
		}

		return ['ready' => $out, 'readyTotal' => $readyTotal, 'young' => $young];
	}



	/**
	 * What this user's own history says about the cost of another batch.
	 *
	 * Pages are the only unit the provider bills, so an estimate built on this
	 * account's own rows beats a constant. Both figures are omitted when there
	 * is too little to average: a made-up number next to a paid button is worse
	 * than an honest blank.
	 *
	 * @return array{avg_pages: ?float, samples: int, per_minute: ?float}
	 */
	public function pageStatsFor(string $uid, int $days = 30): array
	{
		$prefix = $uid . '/files/%';

		$qb = $this->db->getQueryBuilder();
		// No AVG in Nextcloud's function builder, and no reason to add one:
		// SUM over COUNT is the same number and both are portable.
		$qb->selectAlias($qb->func()->count('*'), 'n')
			->selectAlias($qb->func()->count('pages'), 'n_pages')
			->selectAlias($qb->func()->sum('pages'), 'sum_pages')
			->from(self::JOBS)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_DONE)))
			->andWhere($qb->expr()->isNotNull('pages'))
			->andWhere($qb->expr()->like('path', $qb->createNamedParameter($prefix)))
			->andWhere($qb->expr()->gt('finished_at', $qb->createNamedParameter(time() - $days * 86400, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		$samples = (int) ($row['n'] ?? 0);
		$paged = (int) ($row['n_pages'] ?? 0);
		$avg = $paged > 0 ? (float) $row['sum_pages'] / $paged : null;

		$qb2 = $this->db->getQueryBuilder();
		$qb2->selectAlias($qb2->func()->count('*'), 'n')
			->from(self::JOBS)
			->where($qb2->expr()->eq('status', $qb2->createNamedParameter(self::STATUS_DONE)))
			->andWhere($qb2->expr()->like('path', $qb2->createNamedParameter($prefix)))
			->andWhere($qb2->expr()->gt('finished_at', $qb2->createNamedParameter(time() - 3600, IQueryBuilder::PARAM_INT)));
		$recent = (int) ($qb2->executeQuery()->fetchOne() ?? 0);

		return [
			'avg_pages' => $samples >= 20 && $avg !== null ? round((float) $avg, 1) : null,
			'samples' => $samples,
			'per_minute' => $recent >= 3 ? round($recent / 60, 1) : null,
		];
	}

	/**
	 * Queue the user's own unprocessed files, up to `$limit`.
	 *
	 * `enqueue()` first: it is the door that honours the zero-byte guard, the
	 * permanent-verdict guard and the `(file_id, etag)` unique index. Only when
	 * it refuses a file that still has a terminal job row — the file that
	 * vanished mid-run and came back — does the force door open, because that
	 * row is what `forceRequeue()` needs and the only thing blocking the retry.
	 *
	 * @return array{queued: int, refused: int, remaining: int, young: int}
	 */
	public function enqueueNeverProcessed(string $uid, int $limit, ?string $engine): array
	{
		$candidates = $this->neverProcessedFor($uid, $limit);
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
			$result = $this->forceRequeue($force);
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

	/**
	 * Path, size, etag and mtime for a set of file ids, in one read.
	 *
	 * @param list<int> $fileIds
	 * @return list<array{fileId: int, path: string, size: int, etag: string, mtime: int}>
	 */
	protected function fileRowsFor(array $fileIds): array
	{
		$out = [];
		foreach (array_chunk($fileIds, self::IN_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid', 'path', 'size', 'etag', 'mtime')
				->from('filecache')
				->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($qb->executeQuery()->fetchAll() as $row) {
				$out[] = [
					'fileId' => (int) $row['fileid'],
					'path' => (string) $row['path'],
					'size' => (int) $row['size'],
					'etag' => (string) $row['etag'],
					'mtime' => (int) $row['mtime'],
				];
			}
		}
		return $out;
	}

	/**
	 * The newest terminal job row per file — the id `forceRequeue()` needs.
	 *
	 * @param list<int> $fileIds
	 * @return array<int, int> file id => job id
	 */
	protected function terminalJobIdsFor(array $fileIds): array
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

	/**
	 * How long a file must sit untouched before a scan may queue it.
	 *
	 * Same setting `ScanJob` reads, and the same fallback: a value that is not a
	 * positive integer means "unset", never "zero".
	 */
	protected function scanMinAgeSeconds(): int
	{
		if ($this->config === null) {
			return 120;
		}
		$raw = trim((string) $this->config->getAppValue('ocrflow', 'scan_min_age_seconds', '120'));
		return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : 120;
	}

	/**
	 * Files with a live worker task right now.
	 *
	 * @param list<int> $fileIds
	 * @return array<int, string> file id => 'queued'|'claimed'
	 */
	protected function liveQueueFor(array $fileIds): array
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

	protected function ledgerRowsFor(array $fileIds): array
	{
		return $this->ledger()->ledgerRowsFor($fileIds);
	}


	public function ledgerDrift(int $limit = 500): array
	{
		return $this->ledger()->ledgerDrift($limit);
	}

	/**
	 * Processable files on disk for this user: fileid => size.
	 *
	 * @return array<int, int>
	 */
	protected function diskFileIds(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.fileid')
			->from('filecache', 'f')
			->innerJoin('f', 'storages', 's', $qb->expr()->eq('s.numeric_id', 'f.storage'))
			->where($qb->expr()->eq('s.id', $qb->createNamedParameter('home::' . $uid)))
			->andWhere($qb->expr()->like('f.path', $qb->createNamedParameter('files/%')))
			->andWhere($qb->expr()->gt('f.size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(...$this->extPredicates($qb)));
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[(int) $row['fileid']] = 1;
		}
		return $out;
	}



	/**
	 * @return list<\OCP\DB\QueryBuilder\ICompositeExpression|string>
	 */
	private function extPredicates(IQueryBuilder $qb): array
	{
		$out = [];
		foreach (self::READABLE_EXTS as $ext) {
			$out[] = $qb->expr()->like($qb->func()->lower('f.name'), $qb->createNamedParameter('%.' . $ext));
		}
		return $out;
	}
	public function counts(): array
	{
		return $this->jobs()->counts();
	}

	public function purgeTerminal(int $cutoff, int $cap): array
	{
		return $this->jobs()->purgeTerminal($cutoff, $cap);
	}

	protected function selectExpiredTerminalIds(int $cutoff, int $limit): array
	{
		return $this->jobs()->selectExpiredTerminalIds($cutoff, $limit);
	}

	private function purgeablePredicate(IQueryBuilder $qb): ICompositeExpression
	{
		return $this->jobs()->purgeablePredicate($qb);
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

	protected function deleteJobsById(array $ids): int
	{
		return $this->jobs()->deleteJobsById($ids);
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
