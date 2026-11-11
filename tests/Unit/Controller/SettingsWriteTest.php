<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\Controller\MeController;
use OCA\OcrFlow\Service\AccessGate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The user's own switch.
 *
 * The line these cases hold: a user may change their own opt-out and nothing
 * else. The value lives in the user's config, never in app config, and a
 * non-boolean is refused instead of being read as truthy.
 */
class SettingsWriteTest extends TestCase
{
    private function user(string $uid = 'alice'): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    private function sessionFor(IUser $user): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        return $session;
    }

    private function request(mixed $autoOcr): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key): mixed => $key === 'auto_ocr_enabled' ? $autoOcr : null,
        );

        return $request;
    }

    private function controller(IUser $user, IRequest $request, IConfig $config): MeController
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(true);

        return new MeController(
            'ocrflow',
            $request,
            $this->sessionFor($user),
            new AccessGate($config, $groupManager),
            new EnginePolicy($config),
        );
    }

    public function testTheSwitchIsWrittenForTheSessionUserOnly(): void
    {
        $writes = [];
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('1');
        $config->method('getAppValue')->willReturn('');
        $config->expects($this->once())
            ->method('setUserValue')
            ->willReturnCallback(function (string $uid, string $app, string $key, string $value) use (&$writes): void {
                $writes[] = [$uid, $key, $value];
            });

        $data = $this->controller($this->user('alice'), $this->request(true), $config)->update()->getData();

        $this->assertTrue($data['auto_ocr_enabled']);
        $this->assertSame([['alice', 'auto_ocr_enabled', '1']], $writes);
    }

    public function testTurningItOffWritesZero(): void
    {
        $writes = [];
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('0');
        $config->method('getAppValue')->willReturn('');
        $config->expects($this->once())
            ->method('setUserValue')
            ->willReturnCallback(function (string $uid, string $app, string $key, string $value) use (&$writes): void {
                $writes[] = $value;
            });

        $data = $this->controller($this->user('alice'), $this->request(false), $config)->update()->getData();

        $this->assertFalse($data['auto_ocr_enabled']);
        $this->assertSame(['0'], $writes);
    }

    public function testANonBooleanIsRefusedAndNothingIsWritten(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');
        $config->expects($this->never())->method('setUserValue');

        $response = $this->controller($this->user('alice'), $this->request('maybe'), $config)->update();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testThePageIsToldWhenOcrIsNotConfigured(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');   // no engine chosen
        $config->method('getUserValue')->willReturn('1'); // auto OCR on

        $data = $this->controller($this->user('alice'), $this->request(null), $config)->index()->getData();

        $this->assertSame('none', $data['engine']);
        $this->assertFalse(
            $data['engine_configured'],
            'the page must be able to say "not configured" instead of offering a dead toggle',
        );
        $this->assertTrue($data['auto_ocr_enabled']);
        $this->assertTrue($data['enabled']);
    }

    public function testThePageReportsTheSwitchItRead(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('tesseract');
        $config->method('getUserValue')->willReturn('0');

        $data = $this->controller($this->user('alice'), $this->request(null), $config)->index()->getData();

        $this->assertFalse($data['auto_ocr_enabled']);
        $this->assertSame('tesseract', $data['engine']);
        $this->assertTrue($data['engine_configured']);
    }
}
