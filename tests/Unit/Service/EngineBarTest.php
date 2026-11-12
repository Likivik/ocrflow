<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Service\EnginePolicy;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The engine bar: which (engine, engine_version) pairs are good enough that a
 * file we OCR'd with them needs nothing more. Version comparison must be
 * numeric, not string: 3.15.0 is newer than 3.9.0, and "3.15.0" < "3.9.0" as
 * strings is the bug this test exists to prevent.
 */
class EngineBarTest extends TestCase
{
	private function policy(?string $stored): EnginePolicy
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($stored): string {
					if ($app === Application::APP_ID && $key === 'ocr_accepted_engines') {
						return $stored ?? $default;
					}
					return $default;
				},
			);
		return new EnginePolicy($config);
	}

	public function testTheDefaultBarIsGoogle3150(): void
	{
		$this->assertSame(['google' => '3.15.0'], $this->policy(null)->acceptedEngines());
	}

	public function testVersionComparisonIsNumericNotLexicographic(): void
	{
		$policy = $this->policy(null);
		$this->assertTrue($policy->meetsBar('google', '3.15.0'), 'at the bar');
		$this->assertTrue($policy->meetsBar('google', '3.16.1'), 'above the bar');
		// The string compare would say "3.9.0" > "3.15.0"; the numbers say otherwise.
		$this->assertFalse($policy->meetsBar('google', '3.9.0'), '3.9.0 is below 3.15.0');
		$this->assertFalse($policy->meetsBar('google', '3.14.9'), 'just below the bar');
	}

	public function testAMissingVersionIsBelowTheBar(): void
	{
		$policy = $this->policy(null);
		$this->assertFalse($policy->meetsBar('google', null), 'no version recorded: cannot prove it is good');
		$this->assertFalse($policy->meetsBar('google', ''), 'an empty version is a missing version');
		$this->assertFalse($policy->meetsBar(null, '3.15.0'), 'no engine recorded');
	}

	public function testAnEngineAbsentFromTheMapIsNeverAcceptable(): void
	{
		$this->assertFalse($this->policy(null)->meetsBar('tesseract', '99.0.0'), 'unmeasured engines are never good enough');
	}

	public function testAConfiguredBarIsHonoured(): void
	{
		$policy = $this->policy('{"tesseract": "5.3.0", "google": "3.15.0"}');
		$this->assertTrue($policy->meetsBar('tesseract', '5.3.0'));
		$this->assertFalse($policy->meetsBar('tesseract', '5.2.9'));
	}

	public function testAGarbageConfigFallsBackToTheDefaultBar(): void
	{
		$this->assertSame(['google' => '3.15.0'], $this->policy('not json')->acceptedEngines());
		$this->assertSame(['google' => '3.15.0'], $this->policy('[]')->acceptedEngines());
		$this->assertSame(['google' => '3.15.0'], $this->policy('{"google": 3}')->acceptedEngines());
	}

	public function testTheStampVerdictDecidesNothingToDoVersusRedo(): void
	{
		$policy = $this->policy(null);
		$this->assertSame('nothing_to_do', $policy->stampVerdict('google', '3.15.0'));
		$this->assertSame('nothing_to_do', $policy->stampVerdict('google', '4.0.0'));
		$this->assertSame('redo', $policy->stampVerdict('google', '3.9.0'), 'below the bar is a redo candidate');
		$this->assertSame('redo', $policy->stampVerdict('tesseract', '5.3.0'), 'engine not in the map is a redo candidate');
		$this->assertSame('redo', $policy->stampVerdict('google', null), 'unknown version cannot be trusted as done');
	}
}
