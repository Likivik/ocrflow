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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] The write-time ladder: etag → content hash → stash.
 *
 * The measured defect this replaces: `sourceChanged()` compared the enqueue
 * etag with the file's current etag and refused the write on ANY mismatch —
 * including mismatches our own earlier `putFile` write caused (the in-place
 * path changes the etag first, so the guard fired on our own write), and
 * including a file whose bytes are identical but whose etag moved (a
 * metadata-only rewrite).
 *
 * The ladder:
 *  1. etag unchanged → write;
 *  2. etag differs, content hashes equal → write;
 *  3. etag differs, hashes differ (or unknown) → stash the result aside
 *     (Part C) and finish the job `skipped` with
 *     `skip_source_changed_result_saved`.
 *
 * Each rung below fails on unmodified main: rung 2 was refused there
 * (`source_changed`), and rung 3 did not stash anything.
 */
class QueueControllerWriteLadderTest extends TestCase
{
	private const JOB_PATH = '/likivik/files/Work/scan.pdf';

	private const BYTES = '%PDF-1.7 the OCR result';

	private PendingResults&Stub $pending;

	protected function setUp(): void
	{
		// A stub, not a mock: expectations are set per test, and a stash
		// failure (throwing) is its own test below.
		$this->pending = $this->createStub(PendingResults::class);
	}

	/**
	 * @param array<string, string> $params the worker's form payload
	 * @param array<string, mixed> $finish captured finish() payloads
	 */
	private function controller(
		array $params,
		?string $jobEtag,
		?FileExchange $files = null,
		array &$finish = [],
		?string $sourceHash = null,
		?string $writtenEtag = null,
		?Queue $queue = null,
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

		$job = new Job();
		$job->setId(7);
		$job->setPath(self::JOB_PATH);
		$job->setFileId(42);
		$job->setStatus(Queue::STATUS_CLAIMED);
		$job->setClaimToken('tok-7');
		if ($jobEtag !== null) {
			$job->setEtag($jobEtag);
		}
		if ($sourceHash !== null) {
			$job->setSourceSha256($sourceHash);
		}
		if ($writtenEtag !== null) {
			$job->setWrittenEtag($writtenEtag);
		}

		$queue ??= $this->createMock(Queue::class);
		$queue->method('getById')->willReturn($job);
		$queue->method('finish')->willReturnCallback(
			function (int $id, array $result, string $token) use (&$finish): bool {
				$finish = $result;
				return true;
			}
		);

		return new QueueController(
			Application::APP_ID,
			$request,
			$queue,
			$config,
			$this->createMock(LoggerInterface::class),
			$files ?? $this->createMock(FileExchange::class),
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
			$this->pending,
		);
	}

	/**
	 * A FileExchange whose source has the given etag and content hash.
	 */
	private function exchange(?string $currentEtag, ?string $currentHash, ?string $storedHash = null): FileExchange&MockObject
	{
		$files = $this->createMock(FileExchange::class);
		if ($currentEtag !== null) {
			$files->method('currentEtag')->willReturn($currentEtag);
		}
		if ($currentHash !== null) {
			$files->method('contentSha256')->willReturn($currentHash);
		}
		return $files;
	}

	public function testAnUnchangedEtagWritesWithoutHashing(): void
	{
		$files = $this->exchange('old-etag', null);
		$files->expects($this->never())->method('contentSha256');
		$files->expects($this->once())->method('createBeside')->willReturn(
			['name' => 'scan.pdf', 'path' => self::JOB_PATH, 'file_id' => 42, 'etag' => 'new-etag', 'size' => 10]
		);

		$finish = [];
		$response = $this->controller(['name' => 'scan.pdf'], 'old-etag', $files, $finish)->resultFile(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $finish, 'the write happened; the job is closed by the later result call');
	}

	/**
	 * [unit] Ladder rung 2: same bytes under a new etag still get the result.
	 *
	 * Fails on unmodified main: the old guard refused this write with
	 * `source_changed` even though the bytes the worker OCR'd are the bytes
	 * on disk (a metadata-only rewrite moves the etag and nothing else).
	 */
	public function testSameBytesUnderANewEtagStillReceiveTheResult(): void
	{
		$hash = hash('sha256', 'the source bytes');
		$files = $this->exchange('rewritten-etag', $hash);
		$files->expects($this->once())->method('createBeside')->willReturn(
			['name' => 'scan.pdf', 'path' => self::JOB_PATH, 'file_id' => 42, 'etag' => 'new-etag', 'size' => 10]
		);

		$finish = [];
		$response = $this->controller(['name' => 'scan.pdf'], 'old-etag', $files, $finish, $hash)->resultFile(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $finish);
	}

	/**
	 * [unit] Ladder rung 3: genuinely different bytes are not written over,
	 * the result is stashed, and the job closes as a terminal skip with the
	 * new code.
	 *
	 * Fails on unmodified main twice over: the old code was `source_changed`
	 * (now `skip_source_changed_result_saved`), and nothing was ever put in
	 * a pending-results store.
	 */
	public function testDifferentBytesAreStashedNotWritten(): void
	{
		$files = $this->exchange('edited-etag', hash('sha256', 'the EDITED bytes'));
		$files->expects($this->never())->method('createBeside');
		$files->expects($this->never())->method('replace');

		$this->pending = $this->createMock(PendingResults::class);
		$this->pending->expects($this->once())
			->method('stash')
			->with(42, 'old-etag', $this->anything(), 'google', '3.15.0', 12);

		$finish = [];
		$response = $this->controller(
			['engine' => 'google', 'engine_version' => '3.15.0', 'pages' => '12'],
			'old-etag',
			$files,
			$finish,
			hash('sha256', 'the ORIGINAL bytes'),
		)->resultFile(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus(), 'a terminal skip is a 200 the worker must not retry');
		$this->assertSame('skipped', $finish['status']);
		$this->assertSame('skip_source_changed_result_saved', $finish['reason_code']);
		$this->assertFalse($finish['retryable']);
		$payload = $response->getData();
		$this->assertSame('skipped', $payload['status']);
		$this->assertTrue($payload['applied']);
		$this->assertTrue($payload['stashed']);
	}

	/**
	 * [unit] The in-place path runs the same ladder: a conflicting putFile
	 * stashes instead of writing over the user's newer version, and the
	 * ladder's own-write exemption (written_etag) keeps a retried upload
	 * idempotent.
	 */
	public function testPutFileStashesOnConflictAndReArmsItsOwnEtag(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->expects($this->never())->method('armWrittenEtag');

		$files = $this->exchange('edited-etag', hash('sha256', 'the EDITED bytes'));
		$files->expects($this->never())->method('replace');

		$this->pending = $this->createMock(PendingResults::class);
		$this->pending->expects($this->once())->method('stash');

		$finish = [];
		$response = $this->controller([], 'old-etag', $files, $finish, hash('sha256', 'the ORIGINAL bytes'), null, $queue)->putFile(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('skip_source_changed_result_saved', $finish['reason_code']);
	}

	/**
	 * [unit] A successful putFile re-arms written_etag, so the worker's own
	 * earlier write is recognised by the ladder and a retried upload of the
	 * same result stays idempotent.
	 *
	 * Fails on unmodified main: there was no written_etag and no re-arm; a
	 * retried putFile after the first succeeded would have been treated as
	 * a conflicting source.
	 */
	public function testARetriedPutFileOverOurOwnWriteIsAllowed(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->expects($this->once())->method('armWrittenEtag')->with(7, 'written-by-us-2');

		$files = $this->exchange('written-by-us', hash('sha256', 'unrelated current bytes'));
		$files->expects($this->once())->method('replace')->willReturn(
			['size' => 10, 'etag' => 'written-by-us-2', 'file_id' => 42]
		);

		$this->pending = $this->createMock(PendingResults::class);
		$this->pending->expects($this->never())->method('stash');

		$finish = [];
		$response = $this->controller([], 'old-etag', $files, $finish, hash('sha256', 'the ORIGINAL bytes'), 'written-by-us', $queue)->putFile(7);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * [unit] A legacy row with no recorded source hash cannot prove the
	 * bytes match, so the ladder fails safe toward the stash — never toward
	 * writing over a possibly newer version.
	 */
	public function testALegacyRowWithoutAHashStashesOnEtagChange(): void
	{
		$files = $this->exchange('edited-etag', null);
		$files->expects($this->never())->method('createBeside');

		$this->pending = $this->createMock(PendingResults::class);
		$this->pending->expects($this->once())->method('stash');

		$finish = [];
		$response = $this->controller(['name' => 'scan.pdf'], 'old-etag', $files, $finish)->resultFile(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('skip_source_changed_result_saved', $finish['reason_code']);
	}

	/**
	 * [unit] A job whose etag was never recorded (a legacy row) has nothing
	 * to compare and keeps today's behaviour: the result is written.
	 */
	public function testALegacyJobWithoutAnEtagStillReceivesTheResult(): void
	{
		$files = $this->createMock(FileExchange::class);
		$files->expects($this->once())->method('createBeside')->willReturn(
			['name' => 'scan.pdf', 'path' => self::JOB_PATH, 'file_id' => 42, 'etag' => 'new-etag', 'size' => 10]
		);

		$response = $this->controller(['name' => 'scan.pdf'], null, $files)->resultFile(7);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * [unit] A stashing failure must still close the job terminally — the
	 * worker must not retry a conflict — but the response says the result
	 * was not saved.
	 */
	public function testAStashFailureStillClosesTheJobTerminally(): void
	{
		$files = $this->exchange('edited-etag', hash('sha256', 'the EDITED bytes'));
		$files->expects($this->never())->method('createBeside');

		$this->pending = $this->createMock(PendingResults::class);
		$this->pending->method('stash')->willThrowException(new \RuntimeException('appdata is gone'));

		$finish = [];
		$response = $this->controller(
			['name' => 'scan.pdf'],
			'old-etag',
			$files,
			$finish,
			hash('sha256', 'the ORIGINAL bytes'),
		)->resultFile(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('skip_source_changed_result_saved', $finish['reason_code']);
		$this->assertFalse($response->getData()['stashed']);
	}
}
