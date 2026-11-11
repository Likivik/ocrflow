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

    public function testThePerUserSwitchIsReversible(): void
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

        $gate->setAutoOcrEnabled('alice', false);
        $this->assertFalse($gate->isAutoOcrEnabled('alice'));
        $this->assertTrue($gate->isAutoOcrEnabled('bob'), 'one user switching off must not affect another');

        $gate->setAutoOcrEnabled('alice', true);
        $this->assertTrue($gate->isAutoOcrEnabled('alice'));
    }

    public function testTheKeyIsStoredAsAStringAndInvertedNowhereElse(): void
    {
        $writes = [];
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('1');
        $config->expects($this->once())
            ->method('setUserValue')
            ->willReturnCallback(static function (string $uid, string $app, string $key, string $value) use (&$writes): void {
                $writes[] = [$key, $value];
            });

        $gate = new AccessGate($config, $this->createMock(IGroupManager::class));
        $gate->setAutoOcrEnabled('alice', false);

        // '1'/'0' strings, exactly one key: the inversion belongs to the
        // migration, not to every reader of the row.
        $this->assertSame([['auto_ocr_enabled', '0']], $writes);
    }

    public function testTheAppIdIsTheOneTheRestOfTheAppUses(): void
    {
        $this->assertSame('ocrflow', Application::APP_ID);
    }
}
