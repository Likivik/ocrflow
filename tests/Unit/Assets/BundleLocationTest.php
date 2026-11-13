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

	public function testThePanelStylesheetIsWhereAddStyleLooksForIt(): void
	{
		$this->assertFileExists(
			self::APP_ROOT . '/css/ocrflow-panel.css',
			'addStyle("ocrflow","ocrflow-panel") resolves to /apps/ocrflow/css/ocrflow-panel.css — '
			. 'a stylesheet built into js/ is never requested',
		);
	}

	public function testTheStylesheetIsNotLeftBehindInTheScriptDirectory(): void
	{
		$this->assertFileDoesNotExist(
			self::APP_ROOT . '/js/ocrflow-panel.css',
			'a css file in js/ is dead weight that hides the fact that css/ is empty',
		);
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
