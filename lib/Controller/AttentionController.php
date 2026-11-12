<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\Service\Attention;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The admin plane of the attention worklist.
 *
 * Every row here is a needs_fix: a deployment or worker problem a human
 * must resolve. The paths and error prose belong to other people's
 * documents, so nothing on this controller is readable by a non-admin —
 * the refusal is an exception, not a filter, and applies to GET and POST
 * alike.
 */
class AttentionController extends OCSController
{
	private const MAX_LIMIT = 200;

	public function __construct(
		string $appName,
		IRequest $request,
		private Attention $attention,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private RetryPolicy $retryPolicy,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The worklist: rows whose code verdict is needs_fix, newest first.
	 *
	 * @throws OCSForbiddenException
	 */
	public function index(): DataResponse
	{
		$this->requireAdmin();

		$limit = (int) ($this->request->getParam('limit') ?? 50);
		$limit = max(1, min($limit, self::MAX_LIMIT));
		$offset = max(0, (int) ($this->request->getParam('offset') ?? 0));

		$code = $this->request->getParam('code');
		if (is_string($code) && $code !== '' && !$this->retryPolicy->isKnown($code)) {
			return new DataResponse(['message' => 'unknown code'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->attention->list($limit, $offset, is_string($code) && $code !== '' ? $code : null);
		$result['codes'] = $this->attention->codes();
		return new DataResponse($result);
	}

	/**
	 * Retry worklist rows: one by id, or all (capped).
	 *
	 * Retrying spends engine money on paid engines, so this is admin-only
	 * AND CSRF-checked — an attacker's img tag must not be able to queue
	 * half the archive.
	 *
	 * @throws OCSForbiddenException
	 */
	public function retry(): DataResponse
	{
		$this->requireAdmin();

		if (!$this->request->passesCSRFCheck()) {
			return new DataResponse(['message' => 'CSRF check failed'], Http::STATUS_PRECONDITION_FAILED);
		}

		$all = $this->request->getParam('all');
		if ($all === true || $all === '1' || $all === 'true') {
			return new DataResponse([
				'retried' => $this->attention->retryAll(),
				'cap' => $this->attention->cap(),
			]);
		}

		$id = $this->request->getParam('id');
		if ($id === null || $id === '') {
			return new DataResponse(['message' => 'id or all is required'], Http::STATUS_BAD_REQUEST);
		}

		// A row that is already gone answers the same as one that was
		// just retried: the end state ("queued again next scan") already
		// holds, so a double click or a replay does no second work.
		$retried = $this->attention->retryById((int) $id);
		return new DataResponse(['retried' => $retried ? 1 : 0, 'id' => (int) $id]);
	}

	/**
	 * @throws OCSForbiddenException
	 */
	private function requireAdmin(): void
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('no session');
		}
		// The framework's own admin check, not membership in a group
		// literally named "admin": Nextcloud can have several admin groups,
		// and a renamed one would lock out exactly the people this page
		// is for.
		if (!$this->groupManager->isAdmin($user->getUID())) {
			throw new OCSForbiddenException('admin only');
		}
	}
}
