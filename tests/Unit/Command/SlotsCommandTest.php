<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Command;

use OCA\OcrFlow\Command\Slots;
use OCA\OcrFlow\Service\SlotsPolicy;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ ocrflow:slots` — the per-batch door.
 *
 * A batch wants three slots; the evening wants two. The command writes the
 * same value the settings page writes, so there is one number and one place
 * it lives. Refusals matter as much as writes: a typo must not become a
 * worker that cannot run.
 */
class SlotsCommandTest extends TestCase
{
	/** @var array<string, string> */
	private array $stored = [];

	private function tester(): CommandTester
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default,
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->stored[$key] = $value;
			},
		);

		$command = new Slots(new SlotsPolicy($config));
		$application = new Application();
		$application->add($command);
		$application->setAutoExit(false);

		return new CommandTester($application->find('ocrflow:slots'));
	}

	public function testItPrintsTheCurrentNumber(): void
	{
		$tester = $this->tester();
		$tester->execute([]);

		$this->assertSame(Command::SUCCESS, $tester->getStatusCode());
		$this->assertStringContainsString('slots: 2', $tester->getDisplay());
		$this->assertStringContainsString('default', $tester->getDisplay());
	}

	public function testItWritesTheNumber(): void
	{
		$tester = $this->tester();
		$tester->execute(['slots' => '4']);

		$this->assertSame(Command::SUCCESS, $tester->getStatusCode());
		$this->assertSame('4', $this->stored[SlotsPolicy::KEY]);
		$this->assertStringContainsString('slots: 4', $tester->getDisplay());
	}

	public function testAnExistingValueIsShownAsSet(): void
	{
		$this->stored[SlotsPolicy::KEY] = '3';

		$tester = $this->tester();
		$tester->execute([]);

		$this->assertStringContainsString('slots: 3', $tester->getDisplay());
		$this->assertStringNotContainsString('default', $tester->getDisplay());
	}

	public function testAWordIsRefused(): void
	{
		$tester = $this->tester();
		$tester->execute(['slots' => 'many']);

		$this->assertSame(Command::FAILURE, $tester->getStatusCode());
		$this->assertArrayNotHasKey(SlotsPolicy::KEY, $this->stored, 'a typo must not be stored');
	}

	public function testZeroIsRefused(): void
	{
		$tester = $this->tester();
		$tester->execute(['slots' => '0']);

		$this->assertSame(Command::FAILURE, $tester->getStatusCode());
		$this->assertArrayNotHasKey(SlotsPolicy::KEY, $this->stored);
	}
}
