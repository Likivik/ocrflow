<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Controller\QueueController;
use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\FileExchange;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The source image of an image→PDF conversion is moved to the user's trash
 * bin by the APP, not deleted by the worker over WebDAV.
 *
 * The measured reason: a WebDAV DELETE is a permanent removal — 23 image
 * conversions left 0 source images and 0 trash rows behind. The app is the
 * only party with a Nextcloud identity, so the app does the move, through
 * the filesystem API, which is what the trashbin hooks catch.
 *
 * These tests pin the four properties that make that safe:
 *  * the trash happens only when the worker said it converted an image;
 *  * a failing trash does not fail the job (the PDF already exists);
 *  * the source that is trashed is the job's own file, not one the worker
 *    named;
 *  * the file the conversion produced is never trashed.
 */
class QueueControllerTrashTest extends TestCase
{
    private const JOB_PATH = '/likivik/files/Work/photo.jpg';

    /**
     * @param array<string, string> $params the worker's form payload
     */
    private function controller(
        array $params,
        ?FileExchange $files = null,
        ?Queue $queue = null,
    ): QueueController {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->with(Application::APP_ID, 'worker_secret', '')
            ->willReturn('s3cret');

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === Application::WORKER_SECRET_HEADER ? 's3cret' : ''
        );
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
        );

        $job = new Job();
        $job->setId(7);
        $job->setPath(self::JOB_PATH);

        $queue ??= $this->createMock(Queue::class);
        $queue->method('getById')->willReturn($job);

        return new QueueController(
            Application::APP_ID,
            $request,
            $queue,
            $config,
            $this->createMock(LoggerInterface::class),
            $files ?? $this->createMock(FileExchange::class),
            new RetryPolicy(),
        );
    }

    private function queueReturning(bool $applied): Queue
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('finish')->willReturn($applied);
        return $queue;
    }

    public function testConvertedSourceIsMovedToTheTrash(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->expects($this->once())
            ->method('trashSource')
            ->with('likivik', 'Work/photo.jpg')
            ->willReturn(true);

        $response = $this->controller([
            'status' => 'done',
            'source_converted' => '1',
            'source_path' => self::JOB_PATH,
            'result_path' => '/likivik/files/Work/photo.pdf',
        ], $files, $this->queueReturning(true))->result(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testWithoutTheFlagNothingIsTrashed(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->expects($this->never())->method('trashSource');

        // A worker that predates the field sends nothing at all: no
        // trashing, and the job still succeeds.
        $response = $this->controller([
            'status' => 'done',
        ], $files, $this->queueReturning(true))->result(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testAFailingTrashDoesNotFailTheJob(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->expects($this->once())
            ->method('trashSource')
            ->willThrowException(new \RuntimeException('trashbin unavailable'));

        $response = $this->controller([
            'status' => 'done',
            'source_converted' => '1',
            'source_path' => self::JOB_PATH,
            'result_path' => '/likivik/files/Work/photo.pdf',
        ], $files, $this->queueReturning(true))->result(7);

        // The PDF exists and is valid; a leftover image is acceptable, a
        // failed job is not.
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $payload = $response->getData();
        $this->assertSame('done', $payload['status']);
    }

    public function testTheSourceAndTheResultAreNeverTheSameFile(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->expects($this->never())->method('trashSource');

        $response = $this->controller([
            'status' => 'done',
            'source_converted' => '1',
            'source_path' => self::JOB_PATH,
            // The conversion landed on the source path: trashing it would
            // remove the PDF the job just produced.
            'result_path' => self::JOB_PATH,
        ], $files, $this->queueReturning(true))->result(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testAWorkerCannotNameTheFileToTrash(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->expects($this->never())->method('trashSource');

        // The shared secret must not become a delete-anything capability:
        // only the job's own source may be trashed.
        $response = $this->controller([
            'status' => 'done',
            'source_converted' => '1',
            'source_path' => '/likivik/files/Work/somebody-else.png',
            'result_path' => '/likivik/files/Work/photo.pdf',
        ], $files, $this->queueReturning(true))->result(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testASkippedJobTrashesNothing(): void
    {
        $files = $this->createMock(FileExchange::class);
        $files->expects($this->never())->method('trashSource');

        $response = $this->controller([
            'status' => 'skipped',
            'source_converted' => '1',
            'source_path' => self::JOB_PATH,
        ], $files, $this->queueReturning(true))->result(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }
}
