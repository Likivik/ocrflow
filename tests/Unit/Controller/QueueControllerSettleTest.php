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
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] The write-back settles the produced file's verdict at birth.
 *
 * Catches: the produced file leaving no ledger row, which is what let the
 * scan re-queue it after retention purged the job row (the 8135 loop — a
 * second OCR of bytes this app had already written). Both write-back
 * paths (`putFile` for OCR-in-place, `resultFile` for image → PDF) must
 * call `Queue::settleProduced()` with the produced identity (file_id +
 * NEW etag) and the engine facts the worker reported.
 */
class QueueControllerSettleTest extends TestCase
{
	private const JOB_PATH = '/likivik/files/Work/scan.pdf';

	/**
	 * @param array<string, string> $params the worker's form payload
	 * @param array<int, array<int|string, mixed>> $settles captured settleProduced() calls
	 */
	private function controller(
		array $params,
		?Job $job = null,
		array &$settles = [],
	): QueueController {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with(Application::APP_ID, 'worker_secret', '')
			->willReturn('s3cret');

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match ($name) {
				Application::WORKER_SECRET_HEADER => 's3cret',
				Application::CLAIM_HEADER => 'tok-7',
				default => '',
			}
		);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);

		$job ??= $this->claimedJob();

		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturn($job);
		$queue->method('settleProduced')->willReturnCallback(
			function (...$args) use (&$settles): void {
				$settles[] = $args;
			}
		);

		return new QueueController(
			Application::APP_ID,
			$request,
			$queue,
			$config,
			$this->createMock(LoggerInterface::class),
			$this->files(),
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
		);
	}

	private function claimedJob(): Job
	{
		$job = new Job();
		$job->setId(7);
		$job->setPath(self::JOB_PATH);
		$job->setStatus(Queue::STATUS_CLAIMED);
		$job->setClaimToken('tok-7');
		$job->setFileId(42);
		$job->setEtag('old-etag');
		$job->setEngine('google');
		return $job;
	}

	private function files(): FileExchange
	{
		$files = $this->createMock(FileExchange::class);
		$files->method('currentEtag')->willReturn('old-etag');
		$files->method('replace')->willReturn([
			'size' => 10,
			'etag' => 'new-etag',
			'file_id' => 42,
		]);
		$files->method('createBeside')->willReturn([
			'name' => 'scan.pdf',
			'path' => '/likivik/files/Work/scan.pdf',
			'file_id' => 99,
			'etag' => 'produced-etag',
			'size' => 10,
		]);
		return $files;
	}

	public function testPutFileSettlesTheProducedIdentityWithTheEngineFacts(): void
	{
		$settles = [];
		$response = $this->controller(['engine' => 'google', 'engine_version' => '3.15.0'], null, $settles)->putFile(7);

		$this->assertCount(1, $settles, 'the write-back must settle the produced file in the same request');
		[$fileId, $path, $size, $etag, $engine, $engineVersion] = $settles[0];
		$this->assertSame(42, $fileId);
		$this->assertSame(self::JOB_PATH, $path);
		$this->assertSame('new-etag', $etag, 'the settle names the PRODUCED etag, not the source one');
		$this->assertSame('google', $engine);
		$this->assertSame('3.15.0', $engineVersion);
		$this->assertSame('new-etag', $response->getData()['etag'], 'the response carries the identity back to the worker');
	}

	public function testPutFileFallsBackToTheJobEngineStampWhenTheWorkerIsOlder(): void
	{
		$settles = [];
		$this->controller([], null, $settles)->putFile(7);

		$this->assertCount(1, $settles);
		$this->assertSame('google', $settles[0][4], 'no engine fact from the worker means the job\'s engine stamp');
		$this->assertNull($settles[0][5], 'and an unknown version, which is below any bar');
	}

	public function testResultFileSettlesTheProducedFileItself(): void
	{
		$settles = [];
		$response = $this->controller(['name' => 'scan.pdf', 'engine' => 'google', 'engine_version' => '3.15.0'], null, $settles)->resultFile(7);

		$this->assertCount(1, $settles);
		[$fileId, $path, $size, $etag, $engine, $engineVersion] = $settles[0];
		$this->assertSame(99, $fileId, 'the produced file is a NEW file with its own id');
		$this->assertSame('/likivik/files/Work/scan.pdf', $path);
		$this->assertSame('produced-etag', $etag);
		$this->assertSame('google', $engine);
		$this->assertSame('3.15.0', $engineVersion);
		$this->assertSame('produced-etag', $response->getData()['etag']);
	}

	public function testALedgerFailureNeverFailsTheWriteBack(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with(Application::APP_ID, 'worker_secret', '')
			->willReturn('s3cret');

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match ($name) {
				Application::WORKER_SECRET_HEADER => 's3cret',
				Application::CLAIM_HEADER => 'tok-7',
				default => '',
			}
		);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $default
		);

		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturn($this->claimedJob());
		$queue->method('settleProduced')->willThrowException(new \RuntimeException('ledger down'));

		$controller = new QueueController(
			Application::APP_ID,
			$request,
			$queue,
			$config,
			$this->createMock(LoggerInterface::class),
			$this->files(),
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
		);

		$response = $controller->putFile(7);

		$this->assertSame(200, $response->getStatus(), 'the user already has the bytes; the settle is bookkeeping');
	}
}
