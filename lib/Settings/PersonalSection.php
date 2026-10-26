<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The personal-settings section. It exists because the panel used to be a page
 * the worker served on port 8095 — a second UI with its own auth story, which
 * is exactly what this rewrite removes.
 */
class PersonalSection implements IIconSection
{
	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string
	{
		return 'ocrflow';
	}

	public function getName(): string
	{
		return $this->l->t('OCR Flow');
	}

	public function getPriority(): int
	{
		// After Files and Personal info, before the security section.
		return 60;
	}

	public function getIcon(): string
	{
		return $this->url->imagePath('ocrflow', 'app-dark.svg');
	}
}
