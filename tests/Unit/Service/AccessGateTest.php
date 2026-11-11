<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Service\AccessGate;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Who may use OCR.
 *
 * The decision that matters here is the default: an empty (or broken) group list
 * means everyone, because an app that stops working after an upgrade because a
 * new switch defaulted to "nobody" is a self-inflicted outage. Every other case
 * is an administrator explicitly narrowing it.
 */
class AccessGateTest extends TestCase
{
    private function gate(string $rawGroups, array $membership = [], string $autoOcr = '1'): AccessGate
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->with(Application::APP_ID, 'enabled_groups', '')
            ->willReturn($rawGroups);
        $config->method('getUserValue')
            ->with('alice', Application::APP_ID, 'auto_ocr_enabled', '1')
            ->willReturn($autoOcr);

        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isInGroup')->willReturnCallback(
            static fn (string $uid, string $group): bool => in_array($group, $membership, true),
        );

        return new AccessGate($config, $groups);
    }

    private function user(string $uid = 'alice'): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    public function testEmptyListMeansEveryone(): void
    {
        $this->assertTrue($this->gate('')->canProcess($this->user()));
    }

    public function testMalformedListMeansEveryone(): void
    {
        // Not a JSON array: refusing every upload because a config string did not
        // parse is worse than being permissive, and the caller logs it.
        $this->assertTrue($this->gate('staff,scanners')->canProcess($this->user()));
        $this->assertTrue($this->gate('{"a":1}')->canProcess($this->user()));
    }

    public function testMemberOfListedGroupIsAllowed(): void
    {
        $gate = $this->gate('["staff","scanners"]', ['scanners']);
        $this->assertTrue($gate->canProcess($this->user()));
    }

    public function testNonMemberIsRefused(): void
    {
        $gate = $this->gate('["staff"]', ['sales']);
        $this->assertFalse($gate->canProcess($this->user()));
    }

    public function testOptOutWinsOverGroupMembership(): void
    {
        $gate = $this->gate('["staff"]', ['staff'], autoOcr: '0');
        $this->assertFalse($gate->canProcess($this->user()), 'a user who turned auto-OCR off stays off');
    }

    public function testAutoOcrIsEnabledWhenTheKeyIsUnset(): void
    {
        // The default is "on": a user who never touched the panel must not be
        // silently switched off by the rename (a fresh key that defaulted to
        // "off" would be exactly that).
        $gate = $this->gate('["staff"]', ['staff'], autoOcr: '1');
        $this->assertTrue($gate->canProcess($this->user()), 'unset key means enabled');
    }

    public function testWorkerPlaneIsNeverGatedByGroups(): void
    {
        // No session: the worker authenticates with the shared secret, and it must
        // still be able to claim a job for a user the operator has since excluded
        // (otherwise the queue deadlocks with no way to see why).
        $this->assertTrue($this->gate('["staff"]', [])->canProcess(null));
    }

    public function testBlankEntriesAreIgnored(): void
    {
        $gate = $this->gate('["  staff  ","","sales"]', ['staff']);
        $this->assertSame(['staff', 'sales'], $gate->enabledGroups());
    }
}
