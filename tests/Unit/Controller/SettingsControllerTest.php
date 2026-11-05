<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Controller\SettingsController;
use OCA\OcrFlow\Service\AccessGate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The admin surface: who may read it, and what a save writes.
 *
 * The services are `final`, and mocked anyway they would only prove that a mock
 * was called. They are built for real over a mocked config store here, so these
 * cases exercise the code that runs.
 */
class SettingsControllerTest extends TestCase
{
    /** A config store that answers like a real one. */
    private function config(array $appValues = [], array $userValues = [], ?callable $onWrite = null): IConfig
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $appValues[$key] ?? $default,
        );
        $config->method('getUserValue')->willReturnCallback(
            static fn (string $uid, string $app, string $key, string $default = ''): string => $userValues[$key] ?? $default,
        );
        if ($onWrite !== null) {
            $config->method('setAppValue')->willReturnCallback($onWrite);
        }

        return $config;
    }

    private function controller(
        ?IUser $user,
        bool $isAdmin,
        IConfig $config,
        array $groupsInSystem = [],
    ): SettingsController {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);
        $groupManager->method('search')->willReturn(array_map(function (string $gid) {
            $group = $this->createMock(IGroup::class);
            $group->method('getGID')->willReturn($gid);

            return $group;
        }, $groupsInSystem));

        return new SettingsController(
            'ocrflow',
            $this->createMock(IRequest::class),
            $session,
            $groupManager,
            new EnginePolicy($config),
            new AccessGate($config, $groupManager),
        );
    }

    private function user(string $uid = 'admin'): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    public function testNoSessionIsForbidden(): void
    {
        $this->expectException(OCSForbiddenException::class);
        $this->controller(null, false, $this->config())->index();
    }

    public function testANonAdminIsForbidden(): void
    {
        $this->expectException(OCSForbiddenException::class);
        $this->controller($this->user('alice'), false, $this->config())->index();
    }

    public function testANonAdminCannotChangeThePolicy(): void
    {
        $writes = 0;
        $config = $this->config([], [], function () use (&$writes): void {
            $writes++;
        });

        $this->expectException(OCSForbiddenException::class);
        try {
            $this->controller($this->user('alice'), false, $config)->update();
        } finally {
            $this->assertSame(0, $writes, 'a refused request must not write anything');
        }
    }

    public function testAnAdminSeesThePolicyAndTheChoices(): void
    {
        $controller = $this->controller(
            $this->user(),
            true,
            $this->config(['engine' => 'tesseract', 'enabled_groups' => '["staff"]']),
            ['staff', 'everyone'],
        );

        $data = $controller->index()->getData();
        $this->assertSame('tesseract', $data['engine']);
        $this->assertTrue($data['engine_configured']);
        $this->assertSame(['staff'], $data['enabled_groups']);
        $this->assertSame(['everyone', 'staff'], $data['available_groups']);
        $this->assertContains('none', $data['engines'], 'the page must offer "none"');
    }

    public function testAnUnconfiguredInstanceReportsNoneAndNotConfigured(): void
    {
        $data = $this->controller($this->user(), true, $this->config())->index()->getData();
        $this->assertSame('none', $data['engine']);
        $this->assertFalse($data['engine_configured']);
    }

    public function testAnUnknownEngineIsRefusedBeforeItIsStored(): void
    {
        $writes = [];
        $config = $this->config([], [], function (string $app, string $key, string $value) use (&$writes): void {
            $writes[$key] = $value;
        });

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key): mixed => $key === 'engine' ? 'ocrmypdf' : null,
        );

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($this->user());
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);

        $controller = new SettingsController(
            'ocrflow',
            $request,
            $session,
            $groupManager,
            new EnginePolicy($config),
            new AccessGate($config, $groupManager),
        );

        $response = $controller->update();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertArrayNotHasKey('engine', $writes, 'a typo must never reach the config');
    }

    public function testAValidEngineIsStoredLowercased(): void
    {
        $writes = [];
        $config = $this->config([], [], function (string $app, string $key, string $value) use (&$writes): void {
            $writes[$key] = $value;
        });

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key): mixed => $key === 'engine' ? '  TESSERACT ' : null,
        );

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($this->user());
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);

        $controller = new SettingsController(
            'ocrflow',
            $request,
            $session,
            $groupManager,
            new EnginePolicy($config),
            new AccessGate($config, $groupManager),
        );

        $controller->update();
        $this->assertSame('tesseract', $writes['engine'] ?? null);
        $this->assertSame(Application::APP_ID, 'ocrflow');
    }
}
