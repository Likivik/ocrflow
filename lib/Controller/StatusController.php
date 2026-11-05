<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\BackgroundJob\ScanJob;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\AccessGate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The user plane: what the Files sidebar and the admin settings talk to.
 *
 * Every method runs as the logged-in user, so Nextcloud's own filesystem
 * permissions decide what can be scanned — the app never enumerates another
 * user's tree.
 */
class StatusController extends OCSController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private Queue $queue,
		private IJobList $jobs,
		private IUserSession $userSession,
		private AccessGate $access,
		private EnginePolicy $engine,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse
	{
		return new DataResponse([
			'queue' => $this->queue->counts(),
			// Whether the instance will do OCR at all. The sidebar can say
			// "not configured" instead of offering a button that queues nothing.
			'engine' => $this->engine->current(),
			'engine_configured' => $this->engine->isConfigured(),
		]);
	}

	#[NoAdminRequired]
	public function job(int $id): DataResponse
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('no session');
		}
		$job = $this->queue->getById($id);
		// "Does not exist" and "not yours" must be the same answer: a guessed
		// id must not tell an attacker which is which.
		if ($job === null || !$this->ownsPath($user, $job->getPath())) {
			return new DataResponse(['message' => 'no such job'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($job->toApi());
	}

	/**
	 * Queue a whole folder. Returns immediately with the background job's name:
	 * a folder scan of a real archive is thousands of nodes and cannot live in an
	 * HTTP request.
	 *
	 * @throws OCSForbiddenException
	 */
	#[NoAdminRequired]
	public function scan(): DataResponse
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('no session');
		}
		// Group list + per-user opt-out. Empty group list means everyone, so
		// this is a no-op for an instance that never configured it.
		if (!$this->access->canProcess($user)) {
			throw new OCSForbiddenException('OCR is not enabled for your account');
		}

		// Not configured is not forbidden: 409 says "ask your administrator",
		// which is true, and keeps the distinction visible to the client.
		if (!$this->engine->isConfigured()) {
			return new DataResponse([
				'message' => 'No OCR engine is configured',
				'engine' => EnginePolicy::NONE,
			], Http::STATUS_CONFLICT);
		}

		$path = (string) $this->request->getParam('path', '/');
		$this->jobs->add(ScanJob::class, ['user' => $user->getUID(), 'path' => $path]);

		return new DataResponse(['queued' => true, 'path' => $path]);
	}

	/**
	 * Whether this path belongs to this user.
	 *
	 * Every queued path is `<uid>/files/<rel>` — the shape
	 * QueueController::splitPath() defines. Job metadata (its path, its error
	 * text, its page count) belongs to the file's owner.
	 */
	private function ownsPath(IUser $user, string $path): bool
	{
		$parts = QueueController::splitPath($path);
		return $parts !== null && $parts[0] === $user->getUID();
	}

	/**
	 * Per-file answer for the sidebar. `status` is this app's opinion of the
	 * file, not the file's own metadata — the sidebar must not have to download
	 * a 40 MB scan to render a label.
	 */
	#[NoAdminRequired]
	public function file(): DataResponse
	{
		$path = (string) $this->request->getParam('path', '');
		if ($path === '') {
			return new DataResponse(['message' => 'path is required'], Http::STATUS_BAD_REQUEST);
		}
		$user = $this->userSession->getUser();
		// Not yours → the same answer as "never queued": probing foreign paths
		// must learn nothing about other users' documents.
		if ($user === null || !$this->ownsPath($user, $path)) {
			return new DataResponse(['path' => $path, 'status' => 'unknown']);
		}
		$job = $this->queue->findByPath($path);
		if ($job === null) {
			return new DataResponse(['path' => $path, 'status' => 'unknown']);
		}
		return new DataResponse([
			'path' => $path,
			'status' => $job->getStatus(),
			'reason' => $job->getReason(),
			'engine' => $job->getEngine(),
			'pages' => $job->getPages(),
			'chars' => $job->getChars(),
		]);
	}
}
