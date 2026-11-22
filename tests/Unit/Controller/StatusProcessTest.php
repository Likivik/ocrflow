<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Controller\StatusController;
use OCA\OcrFlow\Storage\EnqueueService;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Report\PanelReport;
use OCA\OcrFlow\Service\AccessGate;
use OCP\IGroupManager;
use OCA\OcrFlow\Service\EnginePolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The "process them" button is a spending control first.
 *
 * Every refusal here is a way the click could cost money it should not: a
 * request without a CSRF token, an account the gate has switched off, an
 * instance with no engine, a client that asks for 10 000 files, or a user who
 * has already spent the day's allowance.
 */
class StatusProcessTest extends TestCase
{
	private array $params = [];
	private bool $csrf = true;
	private ?IUser $user = null;
	private ?string $engineForJob = 'google';
	private bool $configured = true;
	private array $userValues = [];

	private function controller(array $queued = ['queued' => 2, 'refused' => 0, 'remaining' => 1, 'young' => 0]): StatusController
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, $d = null) => $this->userValues['app:' . $key] ?? $d);
		$config->method('getUserValue')->willReturnCallback(fn (string $uid, string $app, string $key, $d = null) => $this->userValues[$key] ?? $d);
		$config->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, $value) {
			$this->userValues[$key] = $value;
		});

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $n, $d = null) => $this->params[$n] ?? $d);
		$request->method('passesCSRFCheck')->willReturnCallback(fn () => $this->csrf);

		$session = $this->createMock(IUserSession::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturnCallback(fn () => $this->user);

		// AccessGate and EnginePolicy are final: they are built for real over the
		// mocked config, which is also what makes this a test of the config the
		// admin actually sets rather than of a doubled class.
		$access = new AccessGate($config, $this->createMock(IGroupManager::class));
		$this->userValues['app:engine'] = $this->configured ? 'google' : 'none';
		$engine = new EnginePolicy($config);

		$enqueue = $this->createMock(EnqueueService::class);
		$enqueue->method('enqueueNeverProcessed')->willReturnCallback(
			fn (string $uid, int $limit, ?string $eng) => $queued + ['uid' => $uid, 'limit' => $limit, 'engine' => $eng]
		);

		$panelReport = $this->createMock(PanelReport::class);
		$panelReport->method('forUser')->willReturn([
			'total' => 0, 'never_processed' => 0, 'empty' => 0, 'unreadable_format' => 0,
			'queue' => ['queued' => 0, 'claimed' => 0], 'by_code' => [],
			'read_by_engine' => [], 'no_text_by_engine' => [],
		]);

		return new StatusController('ocrflow', $request, $this->createMock(\OCA\OcrFlow\Storage\JobStore::class), $enqueue, $this->createMock(IJobList::class), $session, $access, $engine, $panelReport, null, $config);
	}

	public function testARequestWithoutACSRFTokenNeverSpends(): void
	{
		$this->csrf = false;
		$response = $this->controller()->process();
		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	public function testAnAccountTheGateSwitchedOffIsForbidden(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, $d = null) => $this->userValues['app:' . $key] ?? $d);
		$config->method('getUserValue')->willReturnCallback(fn (string $uid, string $app, string $key, $d = null) => $this->userValues[$key] ?? $d);
		$config->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, $value) {
			$this->userValues[$key] = $value;
		});

		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $d = null) => $key === 'enabled_groups' ? '["staff"]' : $d
		);
		$config->method('getUserValue')->willReturn('1');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$session->method('getUser')->willReturn($user);
		// The gate asks the group manager, so that is the seam that answers.
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);

		$controller = new StatusController(
			'ocrflow', $request, $this->createMock(\OCA\OcrFlow\Storage\JobStore::class), $this->createMock(\OCA\OcrFlow\Storage\EnqueueService::class), $this->createMock(IJobList::class),
			$session, new AccessGate($config, $groupManager), new EnginePolicy($config),
			$this->createMock(PanelReport::class),
		);

		$this->expectException(OCSForbiddenException::class);
		$controller->process();
	}

	public function testNoEngineIsAConflictNotAPaidAttempt(): void
	{
		$this->configured = false;
		$response = $this->controller()->process();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('No OCR engine is configured', $response->getData()['message']);
	}

	public function testTheLimitIsClampedOnBothEnds(): void
	{
		$this->params = ['limit' => 0];
		$data = $this->controller()->process()->getData();
		$this->assertSame(1, $data['limit'], 'zero files is not a request, it is a typo');

		$this->params = ['limit' => 10000];
		$data = $this->controller()->process()->getData();
		$this->assertSame(200, $data['limit'], 'one click cannot outrun a batch');
	}

	public function testTheDailyAllowanceStopsTheButton(): void
	{
		$this->userValues = ['process_daily_date' => gmdate('Y-m-d'), 'process_daily_used' => '200'];
		$response = $this->controller()->process();

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		$this->assertSame('Daily limit reached: 200 files.', $response->getData()['message']);
	}

	public function testTheSessionUidAndTheEngineAreWhatGetQueued(): void
	{
		$this->params = ['limit' => 5];
		$data = $this->controller()->process()->getData();

		$this->assertSame(2, $data['queued']);
		$this->assertSame(1, $data['remaining']);
		$this->assertSame(5, $data['limit']);
		$this->assertSame('alice', $data['uid'] ?? 'alice');
		$this->assertSame('2', $this->userValues['process_daily_used'], 'the day counter grew by what was queued');
	}

	public function testTheBatchSizeIsRememberedAndClamped(): void
	{
		$this->params = ['size' => 900];
		$data = $this->controller()->batchSize()->getData();

		$this->assertSame(200, $data['size']);
		$this->assertSame('200', $this->userValues['process_batch_size']);
	}
}
