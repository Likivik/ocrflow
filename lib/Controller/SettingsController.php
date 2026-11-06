<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\Service\AccessGate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Instance settings: the OCR engine and who may use it.
 *
 * Administrators only. The values are the same ones `occ ocrflow:engine` and
 * `occ config:app:set ocrflow enabled_groups` set — this endpoint is the
 * settings page, not a second source of truth.
 */
class SettingsController extends OCSController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private EnginePolicy $engine,
		private AccessGate $access,
	) {
		parent::__construct($appName, $request);
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
		// The framework's own admin check, not membership in a group literally
		// named "admin": Nextcloud can have several admin groups, and a renamed
		// or additional one would lock out exactly the people this page is for.
		if (!$this->groupManager->isAdmin($user->getUID())) {
			throw new OCSForbiddenException('admin only');
		}
	}

	/**
	 * The current policy, plus the choices the page offers.
	 *
	 * @throws OCSForbiddenException
	 */
	public function index(): DataResponse
	{
		$this->requireAdmin();

		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = $group->getGID();
		}
		sort($groups);

		return new DataResponse([
			'engine' => $this->engine->current(),
			'engine_configured' => $this->engine->isConfigured(),
			'engines' => array_merge(EnginePolicy::ENGINES, [EnginePolicy::NONE]),
			'enabled_groups' => $this->access->enabledGroups(),
			'available_groups' => $groups,
		]);
	}

	/**
	 * Change the policy. Both fields are optional; an absent field is left
	 * alone, so the page can save one control without clobbering the other.
	 *
	 * @throws OCSForbiddenException
	 */
	public function update(): DataResponse
	{
		$this->requireAdmin();

		$engine = $this->request->getParam('engine');
		if (is_string($engine) && $engine !== '') {
			$engine = strtolower(trim($engine));
			if ($engine !== EnginePolicy::NONE && !in_array($engine, EnginePolicy::ENGINES, true)) {
				return new DataResponse([
					'message' => 'Unknown engine',
				], Http::STATUS_BAD_REQUEST);
			}
			$this->engine->set($engine);
		}

		$groups = $this->request->getParam('enabled_groups');
		if (is_array($groups)) {
			$this->access->setEnabledGroups($groups);
		}

		// Read the state back from the services, not from the request: the answer
		// must be what the instance now does, not what was asked for.
		return new DataResponse([
			'engine' => $this->engine->current(),
			'engine_configured' => $this->engine->isConfigured(),
			'enabled_groups' => $this->access->enabledGroups(),
		]);
	}
}
