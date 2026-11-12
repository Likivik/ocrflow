<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Controller\QueueController;
use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Db\WorkerRegistry;
use OCA\OcrFlow\Service\FileExchange;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The claim token: `file` / `result` / `failure` (and the byte endpoints)
 * must only act for the caller that holds the token minted at claim time.
 *
 * Today a stale result is discarded *silently* — the worker gets a 200 and
 * `applied: false`, nothing is logged — and `/file` checks only the shared
 * secret, so any secret holder can read any job's bytes by id. Both are
 * asserted against here: the refusal must be a logged 409 with the machine
 * code `error_worker_job_reference_stale` in the body.
 */
class QueueClaimTokenTest extends TestCase
{
	private Queue&MockObject $queue;

	private LoggerInterface&MockObject $logger;

	/** @var list<string> */
	private array $warnings = [];

	/** @var array<string, string> */
	private array $headers = [];

	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void
	{
		$this->queue = $this->createMock(Queue::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->warnings = [];
		$this->headers = [];
		$this->params = [];

		$this->logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});
	}

	private function controller(?WorkerRegistry $registry = null, ?FileExchange $files = null): QueueController
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('s3cret');

		$this->headers[Application::WORKER_SECRET_HEADER] = 's3cret';

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $this->headers[$name] ?? ''
		);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default
		);

		return new QueueController(
			Application::APP_ID,
			$request,
			$this->queue,
			$config,
			$this->logger,
			$files ?? $this->createMock(FileExchange::class),
			new RetryPolicy(),
			$registry ?? $this->createMock(WorkerRegistry::class),
		);
	}

	/**
	 * A claimed job row, as the queue would hand it back after `claim()`.
	 */
	private function claimedJob(string $token = 'tok-abc', string $status = Queue::STATUS_CLAIMED): Job
	{
		return Job::fromRow([
			'id' => 7,
			'file_id' => null,
			'path' => '/u/files/a.pdf',
			'status' => $status,
			'reason' => null,
			'reason_code' => null,
			'retryable' => null,
			'engine' => 'tesseract',
			'attempts' => 1,
			'size' => 100,
			'etag' => 'e1',
			'pages' => null,
			'chars' => null,
			'error' => null,
			'created_at' => 1700000000,
			'claimed_at' => 1700000001,
			'finished_at' => null,
			'claim_token' => $token,
			'claimed_by_worker_id' => 3,
		]);
	}

	public function testFileWithoutAClaimTokenIsRefusedWith409(): void
	{
		$this->queue->method('getById')->willReturn($this->claimedJob());

		$response = $this->controller()->file(7);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStaleRefusal($response);
	}

	public function testFileWithAWrongClaimTokenIsRefusedWith409(): void
	{
		$this->queue->method('getById')->willReturn($this->claimedJob('tok-abc'));
		$this->headers[Application::CLAIM_HEADER] = 'tok-xyz';

		$response = $this->controller()->file(7);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStaleRefusal($response);
	}

	public function testFileWithTheRightClaimTokenProceeds(): void
	{
		$this->queue->method('getById')->willReturn($this->claimedJob('tok-abc'));
		$this->headers[Application::CLAIM_HEADER] = 'tok-abc';

		$files = $this->createMock(FileExchange::class);
		$handle = fopen('php://memory', 'wb+');
		fwrite($handle, 'pdf-bytes');
		rewind($handle);
		$files->method('open')->willReturn([
			'handle' => $handle,
			'mime' => 'application/pdf',
			'name' => 'a.pdf',
		]);

		$response = $this->controller(null, $files)->file(7);

		// The claim guard passed: the bytes streamed back with 200.
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $this->warnings);
	}

	public function testResultWithAWrongTokenIsRefusedAndNothingIsWritten(): void
	{
		$this->queue->method('getById')->willReturn($this->claimedJob('tok-abc'));
		$this->headers[Application::CLAIM_HEADER] = 'tok-xyz';

		$this->queue->expects($this->never())->method('finish');

		$response = $this->controller()->result(7);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStaleRefusal($response);
	}

	public function testResultWithoutATokenIsRefusedWith409(): void
	{
		$this->queue->method('getById')->willReturn($this->claimedJob());

		$this->queue->expects($this->never())->method('finish');

		$response = $this->controller()->result(7);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	public function testATokenForAFinishedJobIsRefusedWith409(): void
	{
		$this->queue->method('getById')->willReturn($this->claimedJob('tok-abc', Queue::STATUS_DONE));
		$this->headers[Application::CLAIM_HEADER] = 'tok-abc';

		$this->queue->expects($this->never())->method('finish');

		$response = $this->controller()->result(7);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStaleRefusal($response);
	}

	public function testFailureWithAWrongTokenIsRefusedAndNothingIsWritten(): void
	{
		$this->queue->method('getById')->willReturn($this->claimedJob('tok-abc'));
		$this->headers[Application::CLAIM_HEADER] = 'nope';

		$this->queue->expects($this->never())->method('finish');

		$response = $this->controller()->failure(7);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStaleRefusal($response);
	}

	public function testResultPassesTheClaimTokenToFinish(): void
	{
		$this->queue->method('getById')->willReturn($this->claimedJob('tok-abc'));
		$this->headers[Application::CLAIM_HEADER] = 'tok-abc';

		$this->queue->expects($this->once())->method('finish')
			->with(7, $this->anything(), 'tok-abc')
			->willReturn(true);

		$response = $this->controller()->result(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testPutFileWithAWrongTokenIsRefusedWith409(): void
	{
		$this->queue->method('getById')->willReturn($this->claimedJob('tok-abc'));
		$this->headers[Application::CLAIM_HEADER] = 'tok-xyz';

		$response = $this->controller()->putFile(7);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStaleRefusal($response);
	}

	public function testResultFileWithAWrongTokenIsRefusedWith409(): void
	{
		$this->queue->method('getById')->willReturn($this->claimedJob('tok-abc'));
		$this->headers[Application::CLAIM_HEADER] = 'tok-xyz';

		$response = $this->controller()->resultFile(7);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	public function testClaimPassesTheRegisteredWorkerIdToTheQueue(): void
	{
		$this->queue->expects($this->once())->method('claim')
			->with('w-1', 11)
			->willReturn($this->claimedJob('tok-abc'));
		$this->headers[Application::WORKER_HEADER] = 'w-1';
		$this->headers[Application::WORKER_ID_HEADER] = '11';

		$response = $this->controller()->claim();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAWorkerRegistersOnceAndGetsItsId(): void
	{
		$registry = $this->createMock(WorkerRegistry::class);
		$registry->expects($this->once())->method('register')
			->with('w-1', '0.4.0')
			->willReturn(11);
		$this->headers[Application::WORKER_HEADER] = 'w-1';
		$this->params['version'] = '0.4.0';

		$response = $this->controller($registry)->workerRegister();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$property = new \ReflectionProperty(DataResponse::class, 'data');
		$property->setAccessible(true);
		$data = $property->getValue($response);
		$this->assertSame(11, $data['id'] ?? null);
	}

	public function testAHeartbeatRefreshesLastSeenAndAnswersOk(): void
	{
		$registry = $this->createMock(WorkerRegistry::class);
		$registry->expects($this->once())->method('heartbeat')->with(11)->willReturn(true);
		$this->headers[Application::WORKER_ID_HEADER] = '11';

		$response = $this->controller($registry)->workerHeartbeat();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAHeartbeatForAnUnknownWorkerIdSaysRegisterAgain(): void
	{
		$registry = $this->createMock(WorkerRegistry::class);
		$registry->method('heartbeat')->willReturn(false);
		$this->headers[Application::WORKER_ID_HEADER] = '999';

		$response = $this->controller($registry)->workerHeartbeat();

		// 404, not 409: the row is gone, the fix is a fresh registration.
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	/**
	 * The refusal contract: 409, a machine-readable code in the body, and a
	 * log line — today's silent discard is the bug this replaces.
	 */
	private function assertStaleRefusal(DataResponse $response): void
	{
		$property = new \ReflectionProperty(DataResponse::class, 'data');
		$property->setAccessible(true);
		$data = $property->getValue($response);
		$this->assertIsArray($data);
		$this->assertSame('error_worker_job_reference_stale', $data['reason_code'] ?? null);
		$this->assertArrayHasKey('message', $data);
		$this->assertNotEmpty($this->warnings, 'a stale claim refusal must be logged, not silent');
		$this->assertStringContainsString('7', $this->warnings[0]);
	}
}
