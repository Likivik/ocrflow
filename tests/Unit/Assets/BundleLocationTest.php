<?php

declare(strict_types=1);

namespace OCA\OcrFlow\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The panel's assets must live where Nextcloud looks for them.
 *
 * `Util::addScript('ocrflow', 'ocrflow-panel')` resolves to
 * `/apps/ocrflow/js/ocrflow-panel.js`, and `Util::addStyle('ocrflow',
 * 'ocrflow-panel')` to `/apps/ocrflow/css/ocrflow-panel.css`. A build that
 * emits the stylesheet into `js/` still ships it, still builds, still passes
 * every other check — and the browser gets a 404 for the css, so the panel
 * renders unstyled with its labels running together. Nothing else in the suite
 * would notice, because nothing else knows about that path contract.
 */
class BundleLocationTest extends TestCase
{
	private const APP_ROOT = __DIR__ . '/../../..';

	public function testThePanelScriptIsWhereAddScriptLooksForIt(): void
	{
		$this->assertFileExists(
			self::APP_ROOT . '/js/ocrflow-panel.js',
			'addScript("ocrflow","ocrflow-panel") resolves to /apps/ocrflow/js/ocrflow-panel.js',
		);
	}

	public function testNoStylesheetFileIsShipped(): void
	{
		$this->assertFileDoesNotExist(
			self::APP_ROOT . '/css/ocrflow-panel.css',
			'style-loader inlines the CSS into the bundle; a standalone stylesheet is a leftover '
			. 'from the Vite build and is never requested while addStyle is not called',
		);
	}

	public function testTheStylesAreInTheBundle(): void
	{
		$js = (string) file_get_contents(self::APP_ROOT . '/js/ocrflow-panel.js');

		$this->assertStringContainsString(
			'ocrflow-panel__counts',
			$js,
			'the panel markup classes must appear in the bundle CSS, otherwise the stylesheet was '
			. 'dropped silently and the panel renders unstyled',
		);
	}

	public function testTheSettingsPagesDoNotRegisterAStylesheet(): void
	{
		foreach (['lib/Settings/Admin.php', 'lib/Settings/Personal.php'] as $file) {
			$php = (string) file_get_contents(self::APP_ROOT . '/' . $file);

			$this->assertStringNotContainsString(
				'addStyle',
				$php,
				$file . ' must not call Util::addStyle: the official webpack build emits no .css '
				. 'file, so the request would 404 while every other check kept passing',
			);
		}
	}

	public function testTheShippedBundleHasNoBareProcessReference(): void
	{
		$js = (string) file_get_contents(self::APP_ROOT . '/js/ocrflow-panel.js');

		$this->assertStringNotContainsString(
			'process.env.NODE_ENV',
			$js,
			'lib mode does not replace process.env.NODE_ENV; without define: the bundle dies with '
			. '"process is not defined" before mount() and the noscript fallback is the whole panel',
		);
	}
}
