<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Service\SlotsPolicy;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * How many slots, and what happens when the stored value is nonsense.
 *
 * The rule that matters: a broken value falls back to the default, never to
 * zero. A worker told to run zero slots sits idle while the queue grows, which
 * looks exactly like a broken deployment.
 */
class SlotsPolicyTest extends TestCase
{
	private function config(array $appValues = [], ?callable $onWrite = null): IConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $appValues[$key] ?? $default,
		);
		if ($onWrite !== null) {
			$config->method('setAppValue')->willReturnCallback($onWrite);
		}

		return $config;
	}

	public function testUnsetMeansTwo(): void
	{
		$policy = new SlotsPolicy($this->config());

		$this->assertSame(2, $policy->current());
		$this->assertFalse($policy->isSet(), 'the default is not something the operator chose');
	}

	public function testTheStoredValueIsUsed(): void
	{
		$policy = new SlotsPolicy($this->config(['worker_slots' => '5']));

		$this->assertSame(5, $policy->current());
		$this->assertTrue($policy->isSet());
	}

	/**
	 * @dataProvider nonsense
	 */
	public function testNonsenseFallsBackToTheDefault(string $stored): void
	{
		$policy = new SlotsPolicy($this->config(['worker_slots' => $stored]));

		$this->assertSame(2, $policy->current(), 'zero or junk must never mean "run nothing"');
	}

	public static function nonsense(): array
	{
		return [['0'], ['-3'], ['many'], ['2.5'], [' ']];
	}

	public function testSetWritesTheValue(): void
	{
		$written = [];
		$config = $this->config([], function (string $app, string $key, string $value) use (&$written): void {
			$written[] = [$app, $key, $value];
		});

		$this->assertTrue((new SlotsPolicy($config))->set(4));
		$this->assertSame([[Application::APP_ID, 'worker_slots', '4']], $written);
	}

	public function testSetRefusesZero(): void
	{
		$writes = 0;
		$config = $this->config([], function () use (&$writes): void {
			$writes++;
		});

		$this->assertFalse((new SlotsPolicy($config))->set(0));
		$this->assertSame(0, $writes, 'a refused value must not reach the store');
	}
}
