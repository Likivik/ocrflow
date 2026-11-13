<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\Service\Outcomes;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The admin plane of the five outcome tabs.
 *
 * Every row here names a user's document and carries error prose from
 * other people's systems, so nothing on this controller answers a
 * non-admin — the refusal is an exception, not a filter, and it applies to
 * GET and POST alike. The POSTs spend engine money on paid engines
 * (re-OCR) or overwrite a file's content (Apply), so each is CSRF-checked
 * as well: an attacker's img tag must not be able to re-bill the archive.
 *
 * The rows come from the ONE outcomes reader — the same service the occ
 * command and the panel read — so the doors cannot disagree.
 */
class OutcomesController extends OCSController
{
	private const MAX_LIMIT = 200;

	public function __construct(
		string $appName,
		IRequest $request,
		private Outcomes $outcomes,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private RetryPolicy $retryPolicy,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * One tab's rows: `verdict` is one of needs_fix, redo, wait, settled,
	 * dropped. The response carries the tab badge counts too, so the panel
	 * cannot show a badge that disagrees with the tab it just loaded.
	 *
	 * @throws OCSForbiddenException
	 */
	public function index(): DataResponse
	{
		$this->requireAdmin();

		$verdict = $this->request->getParam('verdict');
		if (!is_string($verdict) || !in_array($verdict, Outcomes::TABS, true)) {
			return new DataResponse(['message' => 'unknown verdict'], Http::STATUS_BAD_REQUEST);
		}

		$limit = (int) ($this->request->getParam('limit') ?? 50);
		$limit = max(1, min($limit, self::MAX_LIMIT));
		$offset = max(0, (int) ($this->request->getParam('offset') ?? 0));

		$code = $this->request->getParam('code');
		if (is_string($code) && $code !== '' && !$this->retryPolicy->isKnown($code)) {
			return new DataResponse(['message' => 'unknown code'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->outcomes->list($verdict, $limit, $offset, is_string($code) && $code !== '' ? $code : null);
		return new DataResponse([
			'rows' => $result['rows'],
			'total' => $result['total'],
			'cap' => $result['cap'],
			'summary' => $this->outcomes->summary(),
			'codes' => $this->outcomes->codesFor($verdict),
			'pricing' => $this->outcomes->pricing(),
		]);
	}

	/**
	 * Re-OCR a selection of Decide rows: `{ids: [...]}` (file ids) or
	 * `{code: ...}`. Capped by the same cap the attention retry-all uses;
	 * the answer carries the estimate of what it just spent. An empty
	 * selection spends nothing.
	 *
	 * @throws OCSForbiddenException
	 */
	public function redo(): DataResponse
	{
		$this->requireAdmin();

		if (!$this->request->passesCSRFCheck()) {
			return new DataResponse(['message' => 'CSRF check failed'], Http::STATUS_PRECONDITION_FAILED);
		}

		$ids = $this->request->getParam('ids');
		$fileIds = [];
		if (is_array($ids)) {
			foreach ($ids as $id) {
				if (is_numeric($id)) {
					$fileIds[] = (int) $id;
				}
			}
		}
		$code = $this->request->getParam('code');
		$codeFilter = is_string($code) && $code !== '' ? $code : null;

		// Neither a selection nor a code: nothing to spend, and no way to
		// guess one. An explicitly EMPTY selection is a no-op, not an
		// error: a double click on a page whose rows were just decided
		// must not turn into a client error.
		if (!is_array($ids) && $codeFilter === null) {
			return new DataResponse(['message' => 'ids or code is required'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->outcomes->redo($fileIds, $codeFilter);
		return new DataResponse($result);
	}

	/**
	 * Apply a stashed pending result to its file. Refuses — with the
	 * warning — when versioning is unavailable, so the previous content of
	 * the file stays recoverable or nothing is overwritten at all.
	 *
	 * @throws OCSForbiddenException
	 */
	public function apply(): DataResponse
	{
		$this->requireAdmin();

		if (!$this->request->passesCSRFCheck()) {
			return new DataResponse(['message' => 'CSRF check failed'], Http::STATUS_PRECONDITION_FAILED);
		}

		$id = $this->request->getParam('id');
		if ($id === null || !is_numeric($id)) {
			return new DataResponse(['message' => 'id is required'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->outcomes->apply((int) $id);
		return new DataResponse($result, $result['status'] === 'applied' ? Http::STATUS_OK : Http::STATUS_CONFLICT);
	}

	/**
	 * Discard a stashed pending result: bytes deleted, row kept as the
	 * audit trail.
	 *
	 * @throws OCSForbiddenException
	 */
	public function discard(): DataResponse
	{
		$this->requireAdmin();

		if (!$this->request->passesCSRFCheck()) {
			return new DataResponse(['message' => 'CSRF check failed'], Http::STATUS_PRECONDITION_FAILED);
		}

		$id = $this->request->getParam('id');
		if ($id === null || !is_numeric($id)) {
			return new DataResponse(['message' => 'id is required'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->outcomes->discard((int) $id);
		return new DataResponse($result, $result['status'] === 'discarded' ? Http::STATUS_OK : Http::STATUS_CONFLICT);
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
