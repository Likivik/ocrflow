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
use OCP\Files\IRootFolder;
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
class QueueController extends OCSController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private Queue $queue,
		private IConfig $config,
		private LoggerInterface $logger,
		private IRootFolder $rootFolder,
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
			$folder = $this->rootFolder->getUserFolder($owner);
			$node = $folder->get($relative);
		} catch (NotFoundException) {
			// The file was moved or deleted after the job was queued. Report it
			// as gone so the worker stops retrying and the row can be closed.
			return new DataResponse(['message' => 'file no longer exists'], Http::STATUS_NOT_FOUND);
		}

		$handle = $node->fopen('rb');
		if ($handle === false) {
			return new DataResponse(['message' => 'file is not readable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = new StreamResponse($node->getName(), $handle, $node->getMimetype() ?: 'application/octet-stream');
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

		try {
			$folder = $this->rootFolder->getUserFolder($owner);
			$node = $folder->get($relative);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'file no longer exists'], Http::STATUS_NOT_FOUND);
		}

		// php://input, not a framework parameter: an OCR'd scan can be tens of
		// megabytes and buffering it into a PHP string doubles that in memory.
		$body = fopen('php://input', 'rb');
		if ($body === false) {
			return new DataResponse(['message' => 'cannot read the request body'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$node->putContent($body);
		if (is_resource($body)) {
			fclose($body);
		}

		return new DataResponse([
			'id' => $id,
			'size' => $node->getSize(),
			'etag' => $node->getEtag(),
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

		$worker = (string) $this->request->getHeader('X-OcrFlow-Worker');
		$job = $this->queue->claim($worker === '' ? 'worker' : substr($worker, 0, 64));
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

		$this->queue->finish($id, [
			'status' => $status,
			'reason' => $this->nullableString('reason'),
			'engine' => $this->nullableString('engine'),
			'pages' => $this->nullableInt('pages'),
			'chars' => $this->nullableInt('chars'),
			'stamp_version' => $this->nullableString('stamp_version'),
		]);
		return new DataResponse(['id' => $id, 'status' => $status]);
	}

	#[PublicPage]
	public function failure(int $id): DataResponse
	{
		if (($denied = $this->denyIfNotWorker()) !== null) {
			return $denied;
		}
		$error = (string) $this->request->getParam('error', '');
		$this->logger->warning('ocrflow: job ' . $id . ' failed: ' . $error);
		$this->queue->finish($id, [
			'status' => Queue::STATUS_ERROR,
			'reason' => $this->nullableString('reason'),
			'error' => $error === '' ? null : $error,
		]);
		return new DataResponse(['id' => $id, 'status' => Queue::STATUS_ERROR]);
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
