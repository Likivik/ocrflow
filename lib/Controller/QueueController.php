<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Db\WorkerRegistry;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\OCSController;
use OCA\OcrFlow\Service\FileExchange;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The worker plane. Nothing here is reachable without the shared secret that
 * `occ ocrflow:worker:secret` prints for the operator to put in the container.
 *
 * Not `@NoAdminRequired`-style user auth: the worker is not a user, and giving
 * it a user's credentials would let a compromised container read every file that
 * user can read, which is more than it needs.
 */
use OCA\OcrFlow\Service\RetryPolicy;

class QueueController extends OCSController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private Queue $queue,
		private IConfig $config,
		private LoggerInterface $logger,
		private FileExchange $files,
		private RetryPolicy $retryPolicy,
		private WorkerRegistry $workers,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Hand a job's file to the worker, read as the user who owns it.
	 *
	 * This is the whole point of the endpoint: the worker never holds a
	 * Nextcloud identity. It presents the shared secret, the app opens the file
	 * in the owner's tree and streams the bytes back. A container therefore
	 * cannot read anything the queue did not hand it, and no account password
	 * exists to leak, rotate, or scope.
	 */
	#[PublicPage]
	public function file(int $id): Http\Response|DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
		}

		$job = $this->queue->getById($id);
		if (($refused = $this->claimGuard($id, $job)) !== null) {
			return $refused;
		}

		$split = self::splitPath((string) $job->getPath());
		if ($split === null) {
			return new DataResponse(['message' => 'job path is not <user>/files/<relative>'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		[$owner, $relative] = $split;

		try {
			$opened = $this->files->open($owner, $relative);
		} catch (NotFoundException) {
			// The file was moved or deleted after the job was queued. Report it
			// as gone so the worker stops retrying and the row can be closed.
			return new DataResponse(['message' => 'file no longer exists'], Http::STATUS_NOT_FOUND);
		}

		$stat = fstat($opened['handle']);
		if (is_array($stat) && (int) ($stat['size'] ?? 0) === 0) {
			// The source is empty on disk. The worker refuses an empty stream by
			// design; naming the cause here turns a retryable-looking transport
			// fault into a terminal data problem the worker can classify as
			// empty_source.
			if (is_resource($opened['handle'])) {
				fclose($opened['handle']);
			}
			return new DataResponse(
				['message' => 'file is empty', 'reason_code' => 'empty_source'],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}

		// StreamResponse takes (pathOrHandle, int $status, array $headers). Passing
		// the handle second put a resource where an int belongs and every fetch
		// answered 500 "Argument #2 ($status) must be of type int, resource given".
		$response = new StreamResponse($opened['handle'], Http::STATUS_OK, ['Content-Type' => $opened['mime']]);
		$response->addHeader(
			'Content-Disposition',
			'attachment; filename="' . rawurlencode($opened['name']) . '"',
		);
		$response->cacheFor(0);
		return $response;
	}

	/**
	 * Take the processed file back from the worker and store it as the owner.
	 *
	 * The write side of `file()`. Same rule: the worker proves it is the worker,
	 * the app does the filesystem work in the owner's tree, so a container holds
	 * no credentials and can only write paths the queue handed it.
	 */
	#[PublicPage]
	public function putFile(int $id): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
		}

		$job = $this->queue->getById($id);
		if (($refused = $this->claimGuard($id, $job)) !== null) {
			return $refused;
		}

		$split = self::splitPath((string) $job->getPath());
		if ($split === null) {
			return new DataResponse(['message' => 'job path is not <user>/files/<relative>'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		[$owner, $relative] = $split;

		// php://input, not a framework parameter: an OCR'd scan can be tens of
		// megabytes and buffering it into a PHP string doubles that in memory.
		$body = fopen('php://input', 'rb');
		if ($body === false) {
			return new DataResponse(['message' => 'cannot read the request body'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		try {
			$written = $this->files->replace($owner, $relative, $body);
		} catch (NotFoundException) {
			// Degraded result, by design: the file was moved or deleted while
			// the worker held the job. A 404 tells the worker the bytes have
			// nowhere to go, so it closes the row instead of retrying.
			return new DataResponse(['message' => 'file no longer exists'], Http::STATUS_NOT_FOUND);
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
		}

		// Verdict at birth: the bytes on disk were just written by us, so
		// the ledger records the produced file (same file_id, NEW etag) as
		// settled — status `skipped`, reason `skip_ocred_by_ocrflow`, engine
		// and engine_version as facts, no bar comparison here. Without
		// this, the next scan of the file re-queues work we just paid for
		// (the 8135 loop). A ledger failure must not fail the write-back
		// the user already has: log, continue.
		$fileId = $job->getFileId();
		if ($fileId !== null) {
			try {
				$this->queue->settleProduced(
					$fileId,
					(string) $job->getPath(),
					$written['size'],
					$written['etag'],
					$this->nullableString('engine') ?? $job->getEngine(),
					$this->nullableString('engine_version'),
				);
			} catch (\Throwable $e) {
				$this->logger->warning('ocrflow: job ' . $id . ' wrote back but the ledger settle failed: ' . $e->getMessage());
			}
		}

		return new DataResponse([
			'id' => $id,
			'size' => $written['size'],
			'etag' => $written['etag'],
			'file_id' => $written['file_id'],
		]);
	}

	/**
	 * Store a *new* file next to the job's file, named by the worker.
	 *
	 * The worker never supplies a path — only a name — because a path from a
	 * caller that holds nothing but a shared secret is exactly the bug class
	 * Nextcloud fixed in GHSA-273v-9h7x-p68v (``newFile()`` creating paths
	 * outside the user's own space). The directory comes from the job; the name
	 * is validated here; the collision rule is ours, not the caller's.
	 *
	 * Used by the image -> PDF branch: the original photo stays untouched and
	 * the searchable PDF appears beside it.
	 */
	#[PublicPage]
	public function resultFile(int $id): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
		}

		$job = $this->queue->getById($id);
		if (($refused = $this->claimGuard($id, $job)) !== null) {
			return $refused;
		}

		$split = self::splitPath((string) $job->getPath());
		if ($split === null) {
			return new DataResponse(['message' => 'job path is not <user>/files/<relative>'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		[$owner, $relative] = $split;

		// The source the job was queued for is not the source on disk
		// anymore: the user edited or replaced the file while OCR ran. The
		// worker's result is for an older version, so writing it beside the
		// new one — and later trashing the new one as the "converted
		// source" — would destroy the user's newer file. Terminal skip:
		// the new version is enqueued as its own job by the next scan.
		if ($this->sourceChanged($job, $owner, $relative)) {
			return $this->skipSourceChanged($id, (string) $this->request->getHeader(Application::CLAIM_HEADER));
		}

		$directory = dirname($relative);
		$directory = $directory === '.' ? '' : $directory . '/';

		$requested = (string) $this->request->getParam('name', '');
		if ($requested === '') {
			$requested = pathinfo($relative, PATHINFO_FILENAME) . '.pdf';
		}
		// A name may not select a directory: separators are how a caller would
		// walk out of the job's folder.
		if (str_contains($requested, '/') || str_contains($requested, '\\')) {
			return new DataResponse(['message' => 'name may not contain a path'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$body = fopen('php://input', 'rb');
		if ($body === false) {
			return new DataResponse(['message' => 'cannot read the request body'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		try {
			$stored = $this->files->createBeside($owner, $relative, $requested, $body);
		} catch (NotFoundException) {
			// Degraded result, by design: the target folder vanished while the
			// job ran. A 404 closes the job as "source gone" rather than retrying
			// a write that can never land.
			return new DataResponse(['message' => 'target folder no longer exists'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $exc) {
			// Degraded result, by design: the worker asked for a name we refuse
			// (a path, a traversal). The validation message is the answer, and
			// 422 tells the worker not to retry the same request.
			return new DataResponse(['message' => $exc->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
		}

		// Verdict at birth, image branch: the produced PDF is a file this
		// app just wrote, so its identity (file_id + etag) goes to the
		// ledger as settled before anyone can ask about it — the upload
		// listener and the next scan both find the answer already there,
		// instead of queueing the file we just produced (the 8135 loop).
		// Same failure rule as `putFile`: the write-back already succeeded,
		// so a ledger failure is logged, not thrown at the worker.
		try {
			$this->queue->settleProduced(
				(int) $stored['file_id'],
				(string) $stored['path'],
				$stored['size'],
				$stored['etag'],
				$this->nullableString('engine') ?? $job->getEngine(),
				$this->nullableString('engine_version'),
			);
		} catch (\Throwable $e) {
			$this->logger->warning('ocrflow: job ' . $id . ' stored a result file but the ledger settle failed: ' . $e->getMessage());
		}

		return new DataResponse([
			'id' => $id,
			'name' => $stored['name'],
			'path' => $stored['path'],
			'file_id' => $stored['file_id'],
			'etag' => $stored['etag'],
		]);
	}

	/**
	 * `/<user>/files/<relative>` — the form Nextcloud itself stores.
	 *
	 * Public and static on purpose: the parsing rules are the one part of the
	 * bytes path that can be tested without a filesystem.
	 */
	public static function splitPath(string $path): ?array
	{
		$parts = explode('/', ltrim($path, '/'), 3);
		if (count($parts) !== 3 || $parts[0] === '' || $parts[1] !== 'files' || $parts[2] === '') {
			return null;
		}
		return [$parts[0], $parts[2]];
	}

	/**
	 * The claim guard: only the worker that holds this job's claim token may
	 * act on it. Every endpoint that touches a claimed job (bytes in, bytes
	 * out, result, failure) calls this right after the 404 check.
	 *
	 * A refusal is a 409 with the machine code in the body and a log line —
	 * both are new. Until now a stale result was discarded *silently* (200,
	 * `applied: false`, nothing logged) and `/file` checked only the shared
	 * secret, so any secret holder could read any job's bytes by id.
	 */
	private function claimGuard(int $id, ?Job $job): ?DataResponse
	{
		$token = (string) $this->request->getHeader(Application::CLAIM_HEADER);
		if ($job === null) {
			return new DataResponse(['message' => 'no such job'], Http::STATUS_NOT_FOUND);
		}
		if (
			$job->getStatus() !== Queue::STATUS_CLAIMED
			|| $job->getClaimToken() === null
			|| $token === ''
			|| !hash_equals($job->getClaimToken(), $token)
		) {
			$this->logger->warning(
				'ocrflow: job ' . $id . ' refused: the claim reference is stale or wrong '
				. '(error_worker_job_reference_stale); nothing was written'
			);
			return new DataResponse([
				'message' => 'this claim is no longer yours to act on; stop work on this job and do not retry',
				'reason_code' => 'error_worker_job_reference_stale',
			], Http::STATUS_CONFLICT);
		}
		return null;
	}

	#[PublicPage]
	public function claim(): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
		}

		$job = $this->queue->claim($this->workerName(), $this->workerId());
		if ($job === null) {
			return new DataResponse(null, Http::STATUS_NO_CONTENT);
		}
		return new DataResponse($job->toApi());
	}

	/**
	 * A worker registers once at startup: its name and its own version, and
	 * back comes the registry row id it heartbeats with. Same secret gate as
	 * the rest of the worker plane — the registry is identity for claims,
	 * not authentication (a stolen secret can still register; see the plan).
	 */
	#[PublicPage]
	public function workerRegister(): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
		}
		$id = $this->workers->register($this->workerName(), $this->nullableString('version'));
		return new DataResponse(['id' => $id]);
	}

	/**
	 * One beat: `last_seen_at` = now. A 404 means the row was pruned (the
	 * worker was down past the staleness budget); the caller must register
	 * again — silently retrying the heartbeat against a gone row is the
	 * spin the false answer prevents.
	 */
	#[PublicPage]
	public function workerHeartbeat(): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
		}
		$id = $this->workerId();
		if ($id === null) {
			return new DataResponse(['message' => 'X-OcrFlow-Worker-Id is required; register first'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		if (!$this->workers->heartbeat($id)) {
			return new DataResponse(['message' => 'unknown worker id; register again'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['id' => $id, 'seen' => true]);
	}

	/**
	 * The registry row id, when this caller registered. Optional on `claim`:
	 * a claim without one is recovered by the first reconcile tick (its
	 * holder is unknown, therefore presumed dead).
	 */
	private function workerId(): ?int
	{
		$raw = (string) $this->request->getHeader(Application::WORKER_ID_HEADER);
		return $raw === '' || !ctype_digit($raw) ? null : (int) $raw;
	}

	#[PublicPage]
	public function result(int $id): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
		}

		// The finish and the trash below both act on the job's source; a
		// job must only ever act on the exact file version it was queued
		// for, so a source that changed mid-run is refused here before
		// either can run (see `sourceChanged`). The claim token gates all
		// of it: a worker without the token gets the 409 below and writes
		// nothing anywhere.
		$job = $this->queue->getById($id);
		if (($refused = $this->claimGuard($id, $job)) !== null) {
			return $refused;
		}
		$claimToken = (string) $this->request->getHeader(Application::CLAIM_HEADER);
		$split = self::splitPath((string) $job->getPath());
		if ($split !== null && $this->sourceChanged($job, $split[0], $split[1])) {
			return $this->skipSourceChanged($id, $claimToken);
		}

		// A worker may legitimately decide that a file needs no work: a
		// born-digital PDF, a photo that is not a document. That is not an error,
		// so it is not reported as one — but it is also not "done", because no
		// text layer was produced. One status per outcome, whitelisted here so a
		// worker cannot invent one that the UI has no words for.
		$status = $this->nullableString('status') ?? Queue::STATUS_DONE;
		if (!in_array($status, [Queue::STATUS_DONE, 'skipped'], true)) {
			$status = Queue::STATUS_DONE;
		}

		$reasonCode = $this->reasonCodeParam();
		if ($reasonCode === null) {
			// A worker that predates the verdict codes reports the classifier's
			// verdict as prose only. The app knows the mapping, so the row
			// still gets a code: a terminal decision with no code is
			// indistinguishable from "nobody decided".
			$reasonCode = $this->retryPolicy->verdictCode($this->nullableString('reason'));
		}
		$decision = $this->retryPolicy->decide($reasonCode, $this->retryableParam());
		if ($decision['agrees'] === false) {
			$this->logger->warning('ocrflow: job ' . $id . ' worker retryable hint disagreed with policy for "' . (string) $reasonCode . '"; policy wins');
		}
		$applied = $this->queue->finish($id, [
			'status' => $status,
			'reason' => $this->nullableString('reason'),
			'reason_code' => $reasonCode,
			'retryable' => $reasonCode === null ? null : $decision['retryable'],
			'engine' => $this->nullableString('engine'),
			'engine_version' => $this->nullableString('engine_version'),
			'pages' => $this->nullableInt('pages'),
			'chars' => $this->nullableInt('chars'),
			'stamp_version' => $this->nullableString('stamp_version'),
			'stage_events' => $this->stageEventsParam(),
		], $claimToken);
		// The source image of an image→PDF conversion is moved to the
		// user's trash bin here, not deleted by the worker over WebDAV: a
		// WebDAV DELETE is a permanent removal (measured: 23 conversions, 0
		// source images left, 0 trash rows). The app is the only party that
		// holds a Nextcloud identity, so the app does the move — through the
		// filesystem API, which is what the trashbin hooks catch.
		//
		// Only after the job is recorded as done, and only for a worker that
		// said it converted an image: a worker that predates this field sends
		// nothing, and nothing is trashed.
		if ($applied && $status === Queue::STATUS_DONE) {
			$this->trashConvertedSource($id);
		}
		// ``applied: false`` means this worker no longer holds the claim (its
		// claim was recovered and re-claimed elsewhere). Not an error: the job
		// is someone else's now, and the result was correctly discarded.
		return new DataResponse(['id' => $id, 'status' => $status, 'applied' => $applied]);
	}

	/**
	 * Move the source image of a finished image→PDF conversion to the trash.
	 *
	 * Failure policy: a leftover image is acceptable, a failed job is not.
	 * The PDF already exists and is valid, so a trash failure is logged and
	 * the job stays ``done``.
	 */
	private function trashConvertedSource(int $id): void
	{
		if (!$this->flagParam('source_converted')) {
			return;
		}

		$job = $this->queue->getById($id);
		if ($job === null) {
			$this->logger->warning('ocrflow: job ' . $id . ' reported a converted source but the job is gone; nothing to trash');
			return;
		}
		$jobPath = (string) $job->getPath();
		$split = self::splitPath($jobPath);
		if ($split === null) {
			$this->logger->warning('ocrflow: job ' . $id . ' has an unusable path; not trashing the source');
			return;
		}
		[$owner, $relative] = $split;

		// The path the worker reported must be the job's own source. A
		// worker is not allowed to name a file of its own choosing: that
		// would turn the shared secret into a delete-anything capability.
		$reported = $this->nullableString('source_path');
		if ($reported !== null && $reported !== $jobPath) {
			$this->logger->warning('ocrflow: job ' . $id . ' reported source "' . $reported . '" which is not its own path; not trashing');
			return;
		}

		// Never trash the file the conversion produced. If the source and
		// the result are the same path, trashing it would remove the PDF.
		$result = $this->nullableString('result_path');
		if ($result !== null && $result === $jobPath) {
			$this->logger->warning('ocrflow: job ' . $id . ' reported its result at the source path; not trashing');
			return;
		}

		try {
			$this->files->trashSource($owner, $relative);
		} catch (\Throwable $e) {
			$this->logger->warning('ocrflow: job ' . $id . ' succeeded but the source image could not be moved to the trash: ' . $e->getMessage());
		}
	}

	/**
	 * Has the job's source been changed since the job was enqueued?
	 *
	 * The job row carries the etag `Queue::enqueue()` recorded; the file on
	 * disk carries its current one. A job must only ever act on the exact
	 * file version it was queued for, and a mismatch means the user edited
	 * or replaced the file while OCR ran — the worker's result is for a
	 * version nobody asked about anymore.
	 *
	 * Nothing to compare is not a change: a legacy row with no etag, or a
	 * source that is gone (null current etag), keeps today's behaviour.
	 */
	private function sourceChanged(Job $job, string $owner, string $relative): bool
	{
		$jobEtag = $job->getEtag();
		if ($jobEtag === null || $jobEtag === '') {
			return false;
		}
		$current = $this->files->currentEtag($owner, $relative);
		return $current !== null && $current !== $jobEtag;
	}

	/**
	 * Record the refusal as a terminal skip, so the worker does not retry:
	 * the same etag mismatch would refuse again, and a retry of the old
	 * version's result is what this guard exists to prevent.
	 *
	 * Same response shape the other terminal skips use.
	 */
	private function skipSourceChanged(int $id, string $claimToken): DataResponse
	{
		$this->logger->warning('ocrflow: job ' . $id . ' skipped: the source file changed while the job ran (source_changed); the result was not written');
		$applied = $this->queue->finish($id, [
			'status' => Queue::STATUS_SKIPPED,
			'reason' => 'the source file changed while the job ran; the result was for an older version',
			'reason_code' => 'source_changed',
			'retryable' => false,
			'stage_events' => $this->stageEventsParam(),
		], $claimToken);
		return new DataResponse([
			'id' => $id,
			'status' => Queue::STATUS_SKIPPED,
			'applied' => $applied,
		]);
	}

	#[PublicPage]
	public function failure(int $id): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
		}

		$job = $this->queue->getById($id);
		if (($refused = $this->claimGuard($id, $job)) !== null) {
			return $refused;
		}

		$error = (string) $this->request->getParam('error', '');
		$this->logger->warning('ocrflow: job ' . $id . ' failed: ' . $error);
		$reasonCode = $this->reasonCodeParam();
		$decision = $this->retryPolicy->decide($reasonCode, $this->retryableParam());
		if ($decision['agrees'] === false) {
			$this->logger->warning('ocrflow: job ' . $id . ' worker retryable hint disagreed with policy for "' . (string) $reasonCode . '"; policy wins');
		}
		$applied = $this->queue->finish($id, [
			'status' => Queue::STATUS_ERROR,
			'reason' => $this->nullableString('reason'),
			'reason_code' => $reasonCode,
			'retryable' => $reasonCode === null ? null : $decision['retryable'],
			'error' => $error === '' ? null : $error,
			'stage_events' => $this->stageEventsParam(),
		], (string) $this->request->getHeader(Application::CLAIM_HEADER));
		return new DataResponse([
			'id' => $id,
			'status' => Queue::STATUS_ERROR,
			'applied' => $applied,
		]);
	}

	/**
	 * The worker may only report codes from the closed vocabulary. Anything
	 * else is recorded under the canonical name for that fact --
	 * `error_worker_sent_unknown_code` -- so the row's needs_fix verdict
	 * decides it. The legacy `unknown` spelling is a READ name for old rows:
	 * writing it here would hand the row the frozen legacy retry answer
	 * (`LEGACY_RETRYABLE['unknown'] === true`) and a version skew would be
	 * retried, which a second attempt cannot fix.
	 */
	private function reasonCodeParam(): ?string
	{
		$code = $this->nullableString('reason_code');
		if ($code === null) {
			return null;
		}
		if (!$this->retryPolicy->isKnown($code)) {
			$this->logger->warning('ocrflow: worker reported unknown reason_code "' . $code . '"; recording ' . RetryPolicy::CODE_WORKER_SENT_UNKNOWN);
			return RetryPolicy::CODE_WORKER_SENT_UNKNOWN;
		}
		return $code;
	}

	/**
	 * The per-stage events the worker reported, decoded.
	 *
	 * The worker sends them as one JSON string inside the flat finish
	 * payload -- a flat dict of strings is what the queue endpoint accepts,
	 * and a nested array in a form-encoded body is a protocol question
	 * nobody needs to answer.
	 *
	 * A worker that predates this feature sends no such key at all, and a
	 * worker that sends something unparseable gets no stage rows rather
	 * than a broken request.
	 *
	 * @return array<int, mixed>|null
	 */
	private function stageEventsParam(): ?array
	{
		$raw = $this->request->getParam('stage_events');
		if ($raw === null || $raw === '') {
			return null;
		}
		if (is_array($raw)) {
			return $raw;
		}
		$decoded = json_decode((string) $raw, true);
		if (!is_array($decoded)) {
			$this->logger->warning('ocrflow: worker sent stage_events that are not a JSON list; they are ignored');
			return null;
		}
		return $decoded;
	}

	/**
	 * The worker's retryable opinion, recorded for the disagreement log. It is
	 * never obeyed: policy has one owner.
	 */
	private function retryableParam(): ?bool
	{
		$raw = $this->request->getParam('retryable', null);
		if ($raw === null || $raw === '') {
			return null;
		}
		return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
	}

	/**
	 * The name this caller claims as. Claim and finish must agree, or a worker
	 * whose stale claim was recovered cannot finish the job it lost — which is
	 * the point of the guard in Queue::finish().
	 */
	private function workerName(): string
	{
		$worker = (string) $this->request->getHeader(Application::WORKER_HEADER);
		return $worker === '' ? 'worker' : substr($worker, 0, 64);
	}

	/**
	 * Constant-time comparison, because the alternative leaks the secret one byte
	 * at a time to anyone who can measure a response.
	 */
	private function denyIfNotWorker(): ?DataResponse
	{
		$expected = (string) $this->config->getAppValue(Application::APP_ID, 'worker_secret', '');
		$given = (string) $this->request->getHeader(Application::WORKER_SECRET_HEADER);

		if ($expected === '') {
			// No secret configured means the app is not set up yet. Refusing is
			// the safe answer: an empty expected value must never authenticate.
			return new DataResponse(['message' => 'worker secret not configured'], Http::STATUS_UNAUTHORIZED);
		}
		if (!hash_equals($expected, $given)) {
			return new DataResponse(['message' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}
		return null;
	}

	private function nullableString(string $key): ?string
	{
		$value = $this->request->getParam($key);
		return $value === null || $value === '' ? null : (string) $value;
	}

	private function nullableInt(string $key): ?int
	{
		$value = $this->request->getParam($key);
		return $value === null || $value === '' ? null : (int) $value;
	}

	/**
	 * A form-encoded boolean. The worker's payload is flat strings, so
	 * ``true`` arrives as ``"1"``, ``"true"`` or ``"yes"`` depending on
	 * which client sent it.
	 */
	private function flagParam(string $key): bool
	{
		$value = $this->request->getParam($key);
		if ($value === null || $value === '' || $value === false) {
			return false;
		}
		if (is_bool($value)) {
			return $value;
		}
		return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
	}
}
