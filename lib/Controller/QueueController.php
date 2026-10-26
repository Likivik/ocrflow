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
use OCP\AppFramework\OCSController;
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
	) {
		parent::__construct($appName, $request);
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
