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
 * The engine decides whether page images of users' documents leave the server.
 *
 * The defect: it defaulted to `google` inside the worker, so an instance nobody
 * configured uploaded scans. Now an instance must choose, and "unconfigured" is a
 * state the app can report instead of a silent default.
 */
class EnginePolicyTest extends TestCase
{
    private function policy(string $stored): EnginePolicy
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->with(Application::APP_ID, 'engine', '')
            ->willReturn($stored);

        return new EnginePolicy($config);
    }

    public function testNothingChosenIsNone(): void
    {
        $policy = $this->policy('');
        $this->assertSame(EnginePolicy::NONE, $policy->current());
        $this->assertFalse($policy->isConfigured());
        $this->assertNull($policy->engineForJob(), 'no engine means nothing is stamped on a job');
    }

    public function testARealEngineIsReportedAndStamped(): void
    {
        $policy = $this->policy('tesseract');
        $this->assertSame('tesseract', $policy->current());
        $this->assertTrue($policy->isConfigured());
        $this->assertSame('tesseract', $policy->engineForJob());
    }

    public function testAnUnknownValueIsNotPassedThrough(): void
    {
        // A typo must not become a job that fails inside the worker after the
        // file has already been claimed.
        $policy = $this->policy('ocrmypdf');
        $this->assertSame(EnginePolicy::NONE, $policy->current());
        $this->assertFalse($policy->isConfigured());
    }

    public function testCaseAndWhitespaceAreForgiven(): void
    {
        $this->assertSame('minimax', $this->policy("  MiniMax \n")->current());
    }

    public function testExplicitNoneStopsQueueing(): void
    {
        $policy = $this->policy('none');
        $this->assertFalse($policy->isConfigured());
        $this->assertNull($policy->engineForJob());
    }

    public function testSetStoresTheLowercasedValue(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())
            ->method('setAppValue')
            ->with(Application::APP_ID, 'engine', 'tesseract');

        (new EnginePolicy($config))->set('  TESSERACT ');
    }
}
