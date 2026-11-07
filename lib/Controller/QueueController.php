<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Db\Queue;
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
		if ($job === null) {
			return new DataResponse(['message' => 'no such job'], Http::STATUS_NOT_FOUND);
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
		if ($job === null) {
			return new DataResponse(['message' => 'no such job'], Http::STATUS_NOT_FOUND);
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
			return new DataResponse(['message' => 'file no longer exists'], Http::STATUS_NOT_FOUND);
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
		}

		return new DataResponse([
			'id' => $id,
			'size' => $written['size'],
			'etag' => $written['etag'],
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
		if ($job === null) {
			return new DataResponse(['message' => 'no such job'], Http::STATUS_NOT_FOUND);
		}

		$split = self::splitPath((string) $job->getPath());
		if ($split === null) {
			return new DataResponse(['message' => 'job path is not <user>/files/<relative>'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		[$owner, $relative] = $split;

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
			return new DataResponse(['message' => 'target folder no longer exists'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $exc) {
			return new DataResponse(['message' => $exc->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
		}

		return new DataResponse([
			'id' => $id,
			'name' => $stored['name'],
			'path' => $stored['path'],
			'file_id' => $stored['file_id'],
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

	#[PublicPage]
	public function claim(): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
		}

		$job = $this->queue->claim($this->workerName());
		if ($job === null) {
			return new DataResponse(null, Http::STATUS_NO_CONTENT);
		}
		return new DataResponse($job->toApi());
	}

	#[PublicPage]
	public function result(int $id): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
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
			'pages' => $this->nullableInt('pages'),
			'chars' => $this->nullableInt('chars'),
			'stamp_version' => $this->nullableString('stamp_version'),
		], $this->workerName());
		// ``applied: false`` means this worker no longer holds the claim (its
		// claim was recovered and re-claimed elsewhere). Not an error: the job
		// is someone else's now, and the result was correctly discarded.
		return new DataResponse(['id' => $id, 'status' => $status, 'applied' => $applied]);
	}

	#[PublicPage]
	public function failure(int $id): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
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
		], $this->workerName());
		return new DataResponse([
			'id' => $id,
			'status' => Queue::STATUS_ERROR,
			'applied' => $applied,
		]);
	}

	/**
	 * The worker may only report codes from the closed vocabulary. Anything
	 * else is recorded as `unknown` and logged, so the table cannot fill with
	 * one-off strings that no policy knows how to treat.
	 */
	private function reasonCodeParam(): ?string
	{
		$code = $this->nullableString('reason_code');
		if ($code === null) {
			return null;
		}
		if (!$this->retryPolicy->isKnown($code)) {
			$this->logger->warning('ocrflow: worker reported unknown reason_code "' . $code . '"; recording unknown');
			return RetryPolicy::CODE_UNKNOWN;
		}
		return $code;
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
		$worker = (string) $this->request->getHeader('X-OcrFlow-Worker');
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
}
