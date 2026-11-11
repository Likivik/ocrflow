<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Who may use OCR.
 *
 * The knob is `enabled_groups`, a JSON list of group ids in app config, set with:
 *
 *   occ config:app:set ocrflow enabled_groups --value='["staff","scanners"]'
 *
 * An **empty list means everyone**, which is the behaviour before this class
 * existed: an app that suddenly stopped working after an upgrade because a new
 * switch defaulted to "nobody" is worse than one that is too permissive by
 * default. Administrators who want to limit it say so.
 *
 * A malformed value is treated as "everyone" for the same reason, and logged by
 * the caller — refusing all work because a config string did not parse is a
 * self-inflicted outage.
 */
final class AccessGate
{
	/** @var list<string>|null */
	private ?array $groups = null;

	public function __construct(
		private IConfig $config,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * The configured group ids. Empty means "no restriction".
	 *
	 * @return list<string>
	 */
	public function enabledGroups(): array
	{
		if ($this->groups !== null) {
			return $this->groups;
		}

		$raw = trim((string) $this->config->getAppValue(Application::APP_ID, 'enabled_groups', ''));
		if ($raw === '') {
			return $this->groups = [];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return $this->groups = [];
		}

		$groups = [];
		foreach ($decoded as $group) {
			if (is_string($group) && trim($group) !== '') {
				$groups[] = trim($group);
			}
		}

		return $this->groups = $groups;
	}

	/**
	 * Whether this user's files may be processed.
	 *
	 * A null user is the worker plane's normal case (no session); that path is
	 * authenticated by the shared secret instead, so it is never gated here.
	 */
	public function isEnabledFor(?IUser $user): bool
	{
		$groups = $this->enabledGroups();
		if ($groups === []) {
			return true;
		}
		if ($user === null) {
			return true;
		}

		foreach ($groups as $group) {
			if ($this->groupManager->isInGroup($user->getUID(), $group)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Per-user auto-OCR switch, stored in `oc_preferences` as '1'/'0'.
	 *
	 * Positive on purpose: the default is "on", and a setting must not be
	 * named after its exception. The old `auto_ocr_disabled` key is moved
	 * (and inverted once) by the 0.1.3 migration.
	 *
	 * Separate from the group gate because losing auto-OCR for one folder-heavy
	 * user should not mean editing the group list for everyone.
	 */
	public function isAutoOcrEnabled(string $userId): bool
	{
		$value = (string) $this->config->getUserValue($userId, Application::APP_ID, 'auto_ocr_enabled', '1');
		return $value === '1';
	}

	/**
	 * Replace the group list.
	 *
	 * An empty list means "everyone", so an administrator who wants to restrict
	 * OCR must name the groups explicitly; there is no way to accidentally
	 * switch everybody off.
	 *
	 * @param list<string> $groups
	 */
	public function setEnabledGroups(array $groups): void
	{
		$clean = [];
		foreach ($groups as $group) {
			if (is_string($group) && trim($group) !== '') {
				$clean[] = trim($group);
			}
		}
		$clean = array_values(array_unique($clean));

		$this->config->setAppValue(
			Application::APP_ID,
			'enabled_groups',
			$clean === [] ? '' : json_encode($clean, JSON_THROW_ON_ERROR),
		);
		// Drop the cached list, or the change is invisible until the process
		// restarts — the classic "I saved it and nothing happened".
		$this->groups = null;
	}

	/**
	 * Turn automatic OCR on (or off) for one user.
	 */
	public function setAutoOcrEnabled(string $userId, bool $enabled): void
	{
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			'auto_ocr_enabled',
			$enabled ? '1' : '0',
		);
	}

	public function canProcess(?IUser $user): bool
	{
		if (!$this->isEnabledFor($user)) {
			return false;
		}
		if ($user === null) {
			return true;
		}

		return $this->isAutoOcrEnabled($user->getUID());
	}
}
