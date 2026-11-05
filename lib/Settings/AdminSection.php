<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The admin section: instance policy, where an administrator chooses the OCR
 * engine and says who may use it.
 *
 * Until now both were only reachable through `occ`, which works but hides them:
 * the settings page is where an administrator looks.
 */
class AdminSection implements IIconSection
{
	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string
	{
		return 'ocrflow-admin';
	}

	public function getName(): string
	{
		return $this->l->t('OCR');
	}

	public function getPriority(): int
	{
		return 70;
	}

	public function getIcon(): string
	{
		return 'icon-settings';
	}
}
