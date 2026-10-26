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
 * The queue panel, rendered as a Vue 3 app mounted into
 * `templates/settings/personal.php` and talking to this app's own OCS
 * endpoints. It is deliberately *not* a second web server: the user plane runs
 * as the logged-in user, so Nextcloud's permissions decide what can be scanned.
 */
class Personal implements ISettings
{
	/**
	 * Must match PersonalSection::getID(), or the section exists and the form
	 * has nowhere to render — NC shows an empty page rather than an error.
	 */
	public function getSection(): string
	{
		return 'ocrflow';
	}

	public function getPriority(): int
	{
		return 10;
	}

	public function getForm(): TemplateResponse
	{
		Util::addScript(Application::APP_ID, 'ocrflow-panel');
		Util::addStyle(Application::APP_ID, 'ocrflow-panel');

		// Blank: this is a section inside the Settings page, not a page of its
		// own. RENDER_AS_USER here would nest a whole layout inside a section.
		return new TemplateResponse(
			Application::APP_ID,
			'settings/personal',
			[],
			TemplateResponse::RENDER_AS_BLANK,
		);
	}
}
