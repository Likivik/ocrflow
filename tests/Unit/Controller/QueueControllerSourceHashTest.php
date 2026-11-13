<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Controller\QueueController;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Db\WorkerRegistry;
use OCA\OcrFlow\Service\FileExchange;
use OCA\OcrFlow\Service\PendingResults;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\IOutput;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] `file()` hashes the exact bytes it serves into
 * `oc_ocrflow_jobs.source_sha256`.
 *
 * The ladder's second rung compares that hash with the file's content
 * later, at write time. The hash must be of the bytes the worker actually
 * received — not a re-read of the file (which may already be the newer
 * version) — so it is computed while streaming the download.
 *
 * Fails on unmodified main: no hash is recorded anywhere (`recordSourceHash`
 * does not exist), and a hash computed at write time from a re-read would
 * compare the new version against itself.
 */
class QueueControllerSourceHashTest extends TestCase
{
	private const BYTES = "the scan's exact bytes\n%PDF-1.7\n";

	public function testTheServedBytesAreHashedIntoTheJob(): void
	{
		$handle = fopen('php://memory', 'rb+');
		self::assertIsResource($handle);
		fwrite($handle, self::BYTES);
		rewind($handle);

		$files = $this->createMock(FileExchange::class);
		$files->method('open')
			->with('likivik', 'Work/scan.pdf')
			->willReturn(['name' => 'scan.pdf', 'mime' => 'application/pdf', 'handle' => $handle]);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match ($name) {
				Application::WORKER_SECRET_HEADER => 's3cret',
				Application::CLAIM_HEADER => 'tok-7',
				default => '',
			}
		);

		$job = new \OCA\OcrFlow\Db\Job();
		$job->setId(7);
		$job->setPath('/likivik/files/Work/scan.pdf');
		$job->setStatus(Queue::STATUS_CLAIMED);
		$job->setClaimToken('tok-7');

		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturn($job);
		$queue->expects($this->once())
			->method('recordSourceHash')
			->with(7, hash('sha256', self::BYTES));

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with(Application::APP_ID, 'worker_secret', '')
			->willReturn('s3cret');

		$controller = new QueueController(
			Application::APP_ID,
			$request,
			$queue,
			$config,
			$this->createMock(LoggerInterface::class),
			$files,
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
			$this->createMock(PendingResults::class),
		);

		$response = $controller->file(7);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		// The hash was of the bytes actually served: run the response and
		// check the worker received exactly `BYTES`.
		$served = null;
		$output = $this->outputCapturing($served);
		$response->callback($output);
		self::assertIsResource($served);
		rewind($served);
		$this->assertSame(self::BYTES, (string) stream_get_contents($served));
	}

	/**
	 * @param resource|null $served set to the resource the response handed to the output
	 */
	private function outputCapturing(&$served): IOutput&MockObject
	{
		$output = $this->createMock(IOutput::class);
		$output->method('getHttpResponseCode')->willReturn(Http::STATUS_OK);
		$output->method('setReadfile')->willReturnCallback(
			static function ($pathOrHandle) use (&$served): bool {
				$served = $pathOrHandle;
				return true;
			}
		);
		return $output;
	}
}
