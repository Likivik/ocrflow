<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\Service\AccessGate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * What the signed-in user may see and change about their own OCR.
 *
 * The per-user auto-OCR switch belongs here rather than in the admin
 * endpoints: one person turning automatic OCR off for their own files must
 * not need an administrator, and must not be able to change anybody else's.
 */
class MeController extends OCSController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private AccessGate $access,
		private EnginePolicy $engine,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @throws OCSForbiddenException
	 */
	#[NoAdminRequired]
	public function index(): DataResponse
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('no session');
		}

		return new DataResponse([
			'auto_ocr_enabled' => $this->access->isAutoOcrEnabled($user->getUID()),
			// Shown so the page can say "not configured" instead of offering a
			// toggle that appears to do nothing.
			'engine' => $this->engine->current(),
			'engine_configured' => $this->engine->isConfigured(),
			// Whether this user may use OCR at all — a group gate can exclude
			// them, and the page should not pretend otherwise.
			'enabled' => $this->access->canProcess($user),
		]);
	}

	/**
	 * @throws OCSForbiddenException
	 */
	#[NoAdminRequired]
	public function update(): DataResponse
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('no session');
		}

		$enabled = $this->request->getParam('auto_ocr_enabled');
		if (!is_bool($enabled) && !in_array($enabled, ['0', '1', 0, 1, 'true', 'false'], true)) {
			return new DataResponse([
				'message' => 'auto_ocr_enabled must be a boolean',
			], Http::STATUS_BAD_REQUEST);
		}

		$autoOcr = in_array($enabled, [true, '1', 1, 'true'], true);
		$this->access->setAutoOcrEnabled($user->getUID(), $autoOcr);

		return new DataResponse([
			'auto_ocr_enabled' => $autoOcr,
		]);
	}
}
