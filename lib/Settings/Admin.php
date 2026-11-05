<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Settings;

use OCA\OcrFlow\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The admin form: engine and group list, rendered as the same Vue bundle the
 * personal panel uses.
 */
class Admin implements ISettings
{
	/**
	 * Must match AdminSection::getID().
	 */
	public function getSection(): string
	{
		return 'ocrflow-admin';
	}

	public function getPriority(): int
	{
		return 10;
	}

	public function getForm(): TemplateResponse
	{
		Util::addScript(Application::APP_ID, 'ocrflow-panel');
		Util::addStyle(Application::APP_ID, 'ocrflow-panel');

		return new TemplateResponse(
			Application::APP_ID,
			'settings/admin',
			[],
			TemplateResponse::RENDER_AS_BLANK,
		);
	}
}
