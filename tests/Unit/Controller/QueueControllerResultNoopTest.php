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
use OCA\OcrFlow\Service\PendingResults;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] `result()` is a no-op for a job whose result was stashed.
 *
 * The stash (Part C) closes the job terminally at write time, so the
 * worker's later `result()` call arrives for a job that is already
 * `skipped` with no claim token. That call must be answered with a
 * terminal 200 (the worker must not retry) while doing nothing: no
 * `done`, no trash, no ledger verdict.
 *
 * Fails on unmodified main: there is no pending-results store at all, and
 * `result()` would 409 on the cleared claim token of a terminal job.
 */
class QueueControllerResultNoopTest extends TestCase
{
	private const JOB_PATH = '/likivik/files/Work/scan.pdf';

	/**
	 * @param array<string, string> $params
	 */
	private function controller(array $params, bool $hasPending): QueueController
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
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);

		$job = new Job();
		$job->setId(7);
		$job->setPath(self::JOB_PATH);
		$job->setFileId(42);
		$job->setEtag('old-etag');
		// The state the stash leaves behind: terminal, no claim token.
		$job->setStatus(Queue::STATUS_SKIPPED);
		$job->setReasonCode('skip_source_changed_result_saved');

		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturn($job);
		$queue->expects($this->never())->method('finish');
		$queue->expects($this->never())->method('settleProduced');

		$pending = $this->createMock(PendingResults::class);
		$pending->method('hasPending')->willReturn($hasPending);

		$files = $this->createMock(FileExchange::class);
		$files->expects($this->never())->method('trashSource');

		return new QueueController(
			Application::APP_ID,
			$request,
			$queue,
			$config,
			$this->createMock(LoggerInterface::class),
			$files,
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
			$pending,
		);
	}

	public function testAResultForAStashedJobIsATerminalNoOp(): void
	{
		$response = $this->controller(['status' => 'done'], true)->result(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus(), 'the worker must not retry');
		$payload = $response->getData();
		$this->assertSame('skipped', $payload['status']);
		$this->assertTrue($payload['applied']);
	}

	public function testAResultForAJobWithoutAPendingRowRunsTheNormalPath(): void
	{
		// Without a pending row the no-op does not swallow the call: the
		// claim guard still answers a terminal job's stale claim reference
		// with 409 (the queue mock has no finish/settleProduced stubs, so a
		// swallowed path would fail the never() expectations instead).
		$response = $this->controller(['status' => 'done'], false)->result(7);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}
}
