<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\Service\Mine;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The user plane of the attention worklist: your own rows, and nobody else's.
 *
 * Deliberately NOT a parameterised version of AttentionController: there is
 * no `uid` request parameter anywhere on this controller, because a client
 * that can name whose rows it wants can name someone else's. The scope is
 * the authenticated session's uid, resolved on every request, and the rows
 * are then filtered by their stored path.
 *
 * The POST is CSRF-checked: a retry re-bills a paid engine, so an attacker's
 * img tag must not be able to queue a user's files. It is also capped by the
 * same `attention_retry_cap` the admin retry-all uses.
 */
class MineController extends OCSController
{
	private const MAX_LIMIT = 200;

	public function __construct(
		string $appName,
		IRequest $request,
		private Mine $mine,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The caller's own needs_fix and redo rows.
	 *
	 * @throws OCSForbiddenException
	 */
	#[NoAdminRequired]
	public function index(): DataResponse
	{
		$uid = $this->callerUid();

		$limit = (int) ($this->request->getParam('limit') ?? 50);
		$limit = max(1, min($limit, self::MAX_LIMIT));
		$offset = max(0, (int) ($this->request->getParam('offset') ?? 0));

		return new DataResponse($this->mine->list($uid, $limit, $offset));
	}

	/**
	 * Retry the caller's own rows: `{ids: [...]}`. Ids that are not theirs
	 * are refused and counted — the response says how many, so a refusal is
	 * never mistaken for a no-op.
	 *
	 * @throws OCSForbiddenException
	 */
	#[NoAdminRequired]
	public function retry(): DataResponse
	{
		$uid = $this->callerUid();

		if (!$this->request->passesCSRFCheck()) {
			return new DataResponse(['message' => 'CSRF check failed'], Http::STATUS_PRECONDITION_FAILED);
		}

		$ids = $this->request->getParam('ids');
		if (!is_array($ids)) {
			return new DataResponse(['message' => 'ids is required'], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->mine->retry($uid, array_values($ids)));
	}

	/**
	 * @throws OCSForbiddenException
	 */
	private function callerUid(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('no session');
		}
		// The ONLY source of the scope. Not a request parameter, not a
		// header, not a body field: the session's uid, every time.
		return $user->getUID();
	}
}
