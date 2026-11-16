<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\Db\Queue;
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

	/**
	 * One rescan call may queue this many rows. Each row costs one engine run
	 * per page, so a fat-fingered "select all" must not start thousands.
	 */
	private const MAX_RESCAN = 500;

	public function __construct(
		string $appName,
		IRequest $request,
		private Attention $attention,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private RetryPolicy $retryPolicy,
		private Queue $queue,
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
	 * Rescan anyway: re-queue rows the pipeline decided about, with the
	 * operator's force bit set.
	 *
	 * The worker honours `force` by returning before every skip decision —
	 * born-digital, stamped by us, someone else's OCR layer — so this is the
	 * only way to redo a file the classifier settled months ago. It spends
	 * engine money per page, hence admin-only, CSRF-checked, and capped.
	 *
	 * @throws OCSForbiddenException
	 */
	public function rescan(): DataResponse
	{
		$this->requireAdmin();

		if (!$this->request->passesCSRFCheck()) {
			return new DataResponse(['message' => 'CSRF check failed'], Http::STATUS_PRECONDITION_FAILED);
		}

		$ids = $this->request->getParam('ids');
		if (!is_array($ids)) {
			$one = $this->request->getParam('id');
			$ids = $one === null || $one === '' ? [] : [$one];
		}

		$clean = [];
		foreach ($ids as $value) {
			if (is_int($value) || (is_string($value) && ctype_digit($value))) {
				$clean[] = (int) $value;
			}
		}
		$clean = array_values(array_unique($clean));
		if ($clean === []) {
			return new DataResponse(['message' => 'id or ids is required'], Http::STATUS_BAD_REQUEST);
		}
		if (count($clean) > self::MAX_RESCAN) {
			return new DataResponse(
				['message' => 'too many ids in one call', 'max' => self::MAX_RESCAN],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$result = $this->queue->forceRequeue($clean);
		return new DataResponse([
			'requeued' => count($result['requeued']),
			'refused' => count($result['refused']),
			'refused_ids' => $result['refused'],
		]);
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
