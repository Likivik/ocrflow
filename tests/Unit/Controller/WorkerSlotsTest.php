<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Controller\QueueController;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\SettlementService;
use OCA\OcrFlow\Db\WorkerRegistry;
use OCA\OcrFlow\Service\FileExchange;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\SlotsPolicy;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The worker reads its slot count from the app, not from a rebuild.
 *
 * This endpoint is the whole reason a resize needs no deploy: the process that
 * acts on the number is the one that cannot hold a browser session. Two rules:
 * it is worker-plane (the shared secret, nothing else), and it answers the
 * stored value rather than echoing the request.
 */
class WorkerSlotsTest extends TestCase
{
	/** @var array<string, string> */
	private array $headers = [];

	private function controller(string $stored = '3'): QueueController
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($stored): string {
				if ($key === 'worker_secret') {
					return 's3cret';
				}
				if ($key === SlotsPolicy::KEY) {
					return $stored;
				}

				return $default;
			},
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $this->headers[$name] ?? '',
		);

		return new QueueController(
			Application::APP_ID,
			$request,
			$this->createMock(JobStore::class),
			$this->createMock(SettlementService::class),
			$config,
			$this->createMock(LoggerInterface::class),
			$this->createMock(FileExchange::class),
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
			null,
			new SlotsPolicy($config),
		);
	}

	public function testWithoutTheWorkerSecretItIsUnauthorized(): void
	{
		$response = $this->controller()->workerSlots();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testAWrongSecretIsUnauthorized(): void
	{
		$this->headers[Application::WORKER_SECRET_HEADER] = 'guess';

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->workerSlots()->getStatus());
	}

	public function testTheWorkerGetsTheStoredValue(): void
	{
		$this->headers[Application::WORKER_SECRET_HEADER] = 's3cret';

		$response = $this->controller('7')->workerSlots();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['slots' => 7], $response->getData());
	}

	public function testAnUnsetValueAnswersTheDefault(): void
	{
		$this->headers[Application::WORKER_SECRET_HEADER] = 's3cret';

		$response = $this->controller('')->workerSlots();

		$this->assertSame(['slots' => SlotsPolicy::DEFAULT_SLOTS], $response->getData());
	}
}
