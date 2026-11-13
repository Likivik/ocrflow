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
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A job must only ever act on the exact file version it was queued for.
 *
 * The job row carries the etag recorded at enqueue. If the user edits or
 * replaces the source while OCR runs, the file on disk has a different etag —
 * and the worker's (now stale) result must not be written over the newer
 * version, nor may the newer version be trashed by the image→PDF cleanup.
 *
 * The measured defect: `resultFile()` wrote the worker's bytes through
 * `createBeside()` and `result()` let `trashSource()` run, with no etag
 * comparison anywhere on the result path.
 *
 * Legacy rows (no etag) and a missing node (no current etag) keep today's
 * behaviour: nothing to compare, so nothing is refused.
 */
class QueueControllerSourceChangedTest extends TestCase
{
    private const JOB_PATH = '/likivik/files/Work/scan.pdf';

    /**
     * @param array<string, string> $params the worker's form payload
     * @param array<string, mixed> $finish captured finish() payloads
     */
    private function controller(
        array $params,
        ?string $jobEtag,
        ?string $currentEtag,
        ?FileExchange $files = null,
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
        $job->setStatus(Queue::STATUS_CLAIMED);
        $job->setClaimToken('tok-7');
        if ($jobEtag !== null) {
            $job->setEtag($jobEtag);
        }

        $queue = $this->createMock(Queue::class);
        $queue->method('getById')->willReturn($job);
        $queue->method('finish')->willReturnCallback(
            function (int $id, array $result) use (&$finish): bool {
                $finish = $result;
                return true;
            }
        );

        $files ??= $this->createMock(FileExchange::class);
        if ($currentEtag !== null || $jobEtag !== null) {
            $files->method('currentEtag')->willReturn($currentEtag);
        }

        return new QueueController(
            Application::APP_ID,
            $request,
            $queue,
            $config,
            $this->createMock(LoggerInterface::class),
            $files,
            new RetryPolicy(),
            $this->createMock(WorkerRegistry::class),
        );
    }

    public function testAChangedSourceIsNotOverwritten(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->method('currentEtag')->willReturn('new-etag');
        $files->expects($this->never())->method('createBeside');

        $finish = [];
        $response = $this->controller(['name' => 'scan.pdf'], 'old-etag', 'new-etag', $files, $finish)->resultFile(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus(), 'the worker must not retry a terminal skip');
        $this->assertSame('skipped', $finish['status']);
        $this->assertSame('source_changed', $finish['reason_code']);
        $this->assertFalse($finish['retryable']);
        $payload = $response->getData();
        $this->assertSame('skipped', $payload['status']);
        $this->assertTrue($payload['applied']);
    }

    public function testAnUnchangedSourceStillReceivesTheResult(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->method('currentEtag')->willReturn('old-etag');
        $files->expects($this->once())
            ->method('createBeside')
            ->with('likivik', 'Work/scan.pdf', 'scan.pdf', $this->anything())
            ->willReturn(['name' => 'scan.pdf', 'path' => self::JOB_PATH, 'file_id' => 42, 'etag' => 'new-etag', 'size' => 10]);

        $finish = [];
        $response = $this->controller(['name' => 'scan.pdf'], 'old-etag', 'old-etag', $files, $finish)->resultFile(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        // The write happened on the result-file path; the job is finished by
        // the worker's later `result` call, not here.
        $this->assertSame([], $finish);
    }

    public function testALegacyJobWithoutAnEtagStillReceivesTheResult(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->method('currentEtag')->willReturn('some-etag');
        $files->expects($this->once())
            ->method('createBeside')
            ->willReturn(['name' => 'scan.pdf', 'path' => self::JOB_PATH, 'file_id' => 42, 'etag' => 'new-etag', 'size' => 10]);

        $response = $this->controller(['name' => 'scan.pdf'], null, 'some-etag', $files)->resultFile(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testAGoneSourceKeepsTodaysBehaviour(): void
    {
        // The node is gone: no current etag, so nothing to compare and the
        // result path proceeds exactly as before.
        $files = $this->createMock(FileExchange::class);
        $files->method('currentEtag')->willReturn(null);
        $files->expects($this->once())
            ->method('createBeside')
            ->willReturn(['name' => 'scan.pdf', 'path' => self::JOB_PATH, 'file_id' => 42, 'etag' => 'new-etag', 'size' => 10]);

        $response = $this->controller(['name' => 'scan.pdf'], 'old-etag', null, $files)->resultFile(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testAChangedSourceIsNeverTrashedOnTheResultPath(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->method('currentEtag')->willReturn('new-etag');
        $files->expects($this->never())->method('trashSource');

        $finish = [];
        $response = $this->controller([
            'status' => 'done',
            'source_converted' => '1',
            'source_path' => self::JOB_PATH,
            'result_path' => '/likivik/files/Work/scan.pdf.pdf',
        ], 'old-etag', 'new-etag', $files, $finish)->result(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('skipped', $finish['status']);
        $this->assertSame('source_changed', $finish['reason_code']);
        $this->assertFalse($finish['retryable']);
        $payload = $response->getData();
        $this->assertSame('skipped', $payload['status']);
    }

    public function testAnUnchangedSourceIsTrashedAfterAConversion(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->method('currentEtag')->willReturn('old-etag');
        $files->expects($this->once())
            ->method('trashSource')
            ->with('likivik', 'Work/scan.pdf')
            ->willReturn(true);

        $finish = [];
        $response = $this->controller([
            'status' => 'done',
            'source_converted' => '1',
            'source_path' => self::JOB_PATH,
            'result_path' => '/likivik/files/Work/scan.pdf.pdf',
        ], 'old-etag', 'old-etag', $files, $finish)->result(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('done', $finish['status']);
    }
}
