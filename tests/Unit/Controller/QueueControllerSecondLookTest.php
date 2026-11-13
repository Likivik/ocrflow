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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] The second look: the ladder is re-run immediately before the write.
 *
 * The measured defect: `writeConflict()` decided once, and the write itself
 * happened later — the gap between the comparison and `replace()` /
 * `createBeside()` is seconds for a large source, and a real mid-run user edit
 * landed inside it. The hash comparison saw the original snapshot, the write
 * followed anyway, and the user's replacement bytes were overwritten: the
 * job ended `done`, nothing stashed, no `skip_source_changed_result_saved`.
 *
 * The fix: both write endpoints re-run the SAME ladder immediately before
 * the mutating call. The fakes below return a different hash on the second
 * `contentSha256()` call — the ladder's first evaluation passes on the
 * original bytes, the re-check sees the replaced ones — which is exactly a
 * file edited between the decision and the write.
 *
 * `testBytesThatMoveInTheDecisionWindowAreNotWrittenOver` and
 * `testAFailedSecondLookLeavesTheConvertedSourceUntrashed` fail on
 * unmodified main: there the first (and only) ladder pass authorised the
 * write, the file's newer bytes were overwritten, and — on the image branch —
 * the source the user had just replaced was trashed.
 */
class QueueControllerSecondLookTest extends TestCase
{
	private const JOB_PATH = '/likivik/files/Work/scan.pdf';

	private PendingResults&MockObject $pending;

	protected function setUp(): void
	{
		$this->pending = $this->createMock(PendingResults::class);
	}

	/**
	 * @param array<string, string> $params the worker's form payload
	 * @param array<string, mixed> $finish captured finish() payloads
	 */
	private function controller(
		array $params,
		string $jobEtag,
		?string $sourceHash,
		FileExchange $files,
		array &$finish = [],
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
		$job->setEtag($jobEtag);
		if ($sourceHash !== null) {
			$job->setSourceSha256($sourceHash);
		}

		$queue = $this->createMock(Queue::class);
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
			$files,
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
			$this->pending,
		);
	}

	/**
	 * A FileExchange whose etag has moved (so the ladder must hash) and whose
	 * content hash changes between calls: the first read sees the bytes that
	 * authorised the write, the second sees the user's replacement.
	 */
	private function driftingExchange(string $originalHash, string $editedHash): FileExchange&MockObject
	{
		$files = $this->createMock(FileExchange::class);
		$files->method('currentEtag')->willReturn('rewritten-etag');
		$files->method('contentSha256')->willReturnOnConsecutiveCalls($originalHash, $editedHash);
		return $files;
	}

	public function testBytesThatMoveInTheDecisionWindowAreNotWrittenOver(): void
	{
		$original = hash('sha256', 'the ORIGINAL bytes');
		$edited = hash('sha256', 'the EDITED bytes');

		$files = $this->driftingExchange($original, $edited);
		$files->expects($this->never())->method('replace');

		$this->pending->expects($this->once())->method('stash');

		$finish = [];
		$response = $this->controller([], 'old-etag', $original, $files, $finish)->putFile(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus(), 'a terminal skip is a 200 the worker must not retry');
		$this->assertSame('skipped', $finish['status']);
		$this->assertSame('skip_source_changed_result_saved', $finish['reason_code']);
		$this->assertFalse($finish['retryable']);
		$this->assertTrue($response->getData()['stashed']);
	}

	public function testUnchangedBytesPassTheSecondLookAndNothingIsStashed(): void
	{
		$hash = hash('sha256', 'the original bytes');

		$files = $this->createMock(FileExchange::class);
		$files->method('currentEtag')->willReturn('rewritten-etag');
		$files->method('contentSha256')->willReturn($hash);
		$files->expects($this->once())->method('replace')->willReturn(
			['size' => 10, 'etag' => 'new-etag', 'file_id' => 42]
		);

		$this->pending->expects($this->never())->method('stash');

		$finish = [];
		$response = $this->controller([], 'old-etag', $hash, $files, $finish)->putFile(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('new-etag', $response->getData()['etag']);
		$this->assertSame([], $finish, 'the write happened; the job is closed by the later result call');
	}

	public function testAFailedSecondLookLeavesTheConvertedSourceUntrashed(): void
	{
		$original = hash('sha256', 'the ORIGINAL bytes');
		$edited = hash('sha256', 'the EDITED bytes');

		$files = $this->driftingExchange($original, $edited);
		$files->expects($this->never())->method('createBeside');
		$files->expects($this->never())->method('trashSource');

		$this->pending->expects($this->once())->method('stash');

		$finish = [];
		$response = $this->controller(
			['name' => 'scan.pdf', 'source_converted' => '1'],
			'old-etag',
			$original,
			$files,
			$finish,
		)->resultFile(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('skipped', $finish['status']);
		$this->assertSame('skip_source_changed_result_saved', $finish['reason_code']);
		$this->assertTrue($response->getData()['stashed']);
	}
}
