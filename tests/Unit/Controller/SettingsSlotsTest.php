<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\Controller\SettingsController;
use OCA\OcrFlow\Service\AccessGate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCA\OcrFlow\Service\SlotsPolicy;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The slots number on the admin settings page.
 *
 * It is the same value `occ ocrflow:slots` writes — one number, two doors —
 * and the worker reads it between jobs, so saving here resizes the pool
 * without a restart. The page must not accept a value that would leave the
 * worker unable to run.
 */
class SettingsSlotsTest extends TestCase
{
	/** @var array<string, mixed> */
	private array $params = [];

	/** @var array<string, string> */
	private array $stored = [];

	private function controller(?string $stored = null): SettingsController
	{
		if ($stored !== null) {
			$this->stored[SlotsPolicy::KEY] = $stored;
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default,
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->stored[$key] = $value;
			},
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$groups->method('search')->willReturn([
			$this->createMock(IGroup::class),
		]);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default,
		);

		return new SettingsController(
			'ocrflow',
			$request,
			$session,
			$groups,
			new EnginePolicy($config),
			new AccessGate($config, $groups),
			new SlotsPolicy($config),
		);
	}

	public function testIndexReportsTheSlotsNumber(): void
	{
		$data = $this->controller('4')->index()->getData();

		$this->assertArrayHasKey('worker_slots', $data);
		$this->assertSame(4, $data['worker_slots']);
	}

	public function testIndexReportsTheDefaultWhenUnset(): void
	{
		$this->assertSame(SlotsPolicy::DEFAULT_SLOTS, $this->controller()->index()->getData()['worker_slots']);
	}

	public function testSavingTheNumberWritesIt(): void
	{
		$this->params['worker_slots'] = '5';

		$response = $this->controller()->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('5', $this->stored[SlotsPolicy::KEY]);
		$this->assertSame(5, $response->getData()['worker_slots'], 'the answer is the stored value');
	}

	public function testZeroIsRefusedAndNothingIsWritten(): void
	{
		$this->params['worker_slots'] = '0';

		$response = $this->controller()->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayNotHasKey(SlotsPolicy::KEY, $this->stored, 'a refused value must not be stored');
	}

	public function testAbsentFieldLeavesTheValueAlone(): void
	{
		$response = $this->controller('3')->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('3', $this->stored[SlotsPolicy::KEY]);
	}
}
