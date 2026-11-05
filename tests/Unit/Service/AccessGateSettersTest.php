<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Service\AccessGate;
use OCP\IConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The gate used to be write-only through `occ`, which is fine for a script and
 * useless for a settings page. These cases pin the write path, including the
 * cache invalidation: a save that is not visible until the process restarts is
 * the classic "I saved it and nothing happened".
 */
class AccessGateSettersTest extends TestCase
{
    public function testSetEnabledGroupsStoresJsonAndIsVisibleImmediately(): void
    {
        $stored = '';
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$stored): string {
                return $stored;
            });
        $config->expects($this->once())
            ->method('setAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $value) use (&$stored): void {
                $stored = $value;
            });

        $gate = new AccessGate($config, $this->createMock(IGroupManager::class));
        $gate->setEnabledGroups(['staff', ' scanners ', '']);

        $this->assertSame(['staff', 'scanners'], $gate->enabledGroups());
    }

    public function testEmptyGroupListMeansEveryone(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');
        $config->method('setAppValue');

        $gate = new AccessGate($config, $this->createMock(IGroupManager::class));
        $gate->setEnabledGroups([]);

        $this->assertSame([], $gate->enabledGroups());
        $this->assertTrue($gate->isEnabledFor(null));
    }

    public function testOptOutIsPerUserAndReversible(): void
    {
        $values = [];
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')
            ->willReturnCallback(static function (string $uid, string $app, string $key, string $default = '') use (&$values): string {
                return $values[$uid] ?? $default;
            });
        $config->expects($this->exactly(2))
            ->method('setUserValue')
            ->willReturnCallback(static function (string $uid, string $app, string $key, string $value) use (&$values): void {
                $values[$uid] = $value;
            });

        $gate = new AccessGate($config, $this->createMock(IGroupManager::class));

        $gate->setOptedOut('alice', true);
        $this->assertTrue($gate->isOptedOut('alice'));
        $this->assertFalse($gate->isOptedOut('bob'), 'one user opting out must not affect another');

        $gate->setOptedOut('alice', false);
        $this->assertFalse($gate->isOptedOut('alice'));
    }

    public function testTheAppIdIsTheOneTheRestOfTheAppUses(): void
    {
        $this->assertSame('ocrflow', Application::APP_ID);
    }
}
