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
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\AppFramework\Http;
use OCA\OcrFlow\Service\FileExchange;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The worker plane is `#[PublicPage]` — reachable without a user session,
 * because a container is not a user and must not hold a user's credentials.
 * The shared secret is therefore the only boundary, and these are the cases
 * that decide whether it holds.
 */
class QueueControllerTest extends TestCase
{
    private function controller(string $configuredSecret, ?string $givenSecret, ?Queue $queue = null): QueueController
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->with(Application::APP_ID, 'worker_secret', '')
            ->willReturn($configuredSecret);

        $request = $this->createMock(IRequest::class);
        // The claim path reads two headers: the secret, and the worker's self-
        // reported name. Answer per header name rather than pinning the whole
        // mock to one string, or the test fails on the second read for a reason
        // that has nothing to do with authentication.
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === Application::WORKER_SECRET_HEADER ? ($givenSecret ?? '') : ''
        );

        return new QueueController(
            Application::APP_ID,
            $request,
            $queue ?? $this->createMock(Queue::class),
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(FileExchange::class),
            new RetryPolicy(),
            $this->createMock(WorkerRegistry::class),
        );
    }

    /**
     * A claimed job row, as the queue hands it back after `claim()`.
     */
    private function claimedJob(string $token = 'tok-7'): Job
    {
        $job = new Job();
        $job->setId(7);
        $job->setPath('/u/files/a.pdf');
        $job->setStatus(Queue::STATUS_CLAIMED);
        $job->setClaimToken($token);
        return $job;
    }

    public function testUnconfiguredSecretRejectsEverything(): void
    {
        // An empty expected value must never authenticate, including against an
        // empty presented value: that combination is what an unconfigured app
        // and a curl with no header produce.
        $response = $this->controller('', '')->claim();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testEmptyPresentedSecretIsRejectedWhenOneIsConfigured(): void
    {
        $response = $this->controller('s3cret', null)->claim();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testWrongSecretIsRejected(): void
    {
        $response = $this->controller('s3cret', 's3creT')->claim();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testCorrectSecretWithAnEmptyQueueAnswersNoContent(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('claim')->willReturn(null);

        $response = $this->controller('s3cret', 's3cret', $queue)->claim();

        // 204, not 200-with-empty-body: the worker must be able to tell "nothing
        // to do" from "the call failed" without parsing a body.
        $this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }

    /**
     * The worker may only report codes from the closed vocabulary. Anything
     * else is recorded under the canonical name for that fact --
     * `error_worker_sent_unknown_code`, whose needs_fix verdict decides the
     * row -- so the job row cannot inherit the frozen retry answer of the
     * legacy `unknown` spelling. A second attempt cannot fix a version skew.
     */
    public function testFailureWithAnUnrecognizedReasonCodeStoresTheCanonicalCodeAndDoesNotRetry(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('getById')->willReturn($this->claimedJob('tok-7'));
        $captured = null;
        $queue->expects($this->once())
            ->method('finish')
            ->with(7, $this->callback(function (array $payload) use (&$captured): bool {
                $captured = $payload;
                return true;
            }), 'tok-7')
            ->willReturn(true);

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => match ($name) {
                Application::WORKER_SECRET_HEADER => 'the-secret',
                Application::CLAIM_HEADER => 'tok-7',
                default => '',
            },
        );
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => match ($key) {
                'reason_code' => 'made_up_engine_fault',
                'error' => 'engine exploded',
                default => $default,
            },
        );

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->with(Application::APP_ID, 'worker_secret', '')
            ->willReturn('the-secret');

        $controller = new QueueController(
            Application::APP_ID,
            $request,
            $queue,
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(FileExchange::class),
            new RetryPolicy(),
            $this->createMock(WorkerRegistry::class),
        );

        $data = $controller->failure(7)->getData();
        $this->assertTrue($data['applied']);
        $this->assertSame(Queue::STATUS_ERROR, $captured['status']);
        $this->assertSame(
            'error_worker_sent_unknown_code',
            $captured['reason_code'],
            'a freshly-unknown code must be recorded under its canonical name, not the legacy unknown spelling',
        );
        $this->assertFalse(
            $captured['retryable'],
            'a version skew cannot be fixed by a second attempt: the verdict decides, not the frozen legacy answer',
        );
    }

    public function testFailureEndpointRejectsAWrongSecret(): void
    {
        $response = $this->controller('s3cret', 'nope')->failure(7);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testFileEndpointRejectsAWrongSecret(): void
    {
        $response = $this->controller('s3cret', 'nope')->file(1);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testFileEndpointReportsAMissingJob(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('getById')->with(42)->willReturn(null);

        $response = $this->controller('s3cret', 's3cret', $queue)->file(42);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    /**
     * The path split is the contract with Nextcloud's own path format. A path
     * that does not match must be refused rather than guessed at: guessing is
     * how a worker ends up reading someone else's file.
     */
    public function testSplitPathAcceptsTheNextcloudForm(): void
    {
        $this->assertSame(
            ['likivik', 'Work/scan.pdf'],
            QueueController::splitPath('/likivik/files/Work/scan.pdf'),
        );
    }

    public function testSplitPathRefusesForeignShapes(): void
    {
        foreach ([
            '',
            '/',
            '/likivik',
            '/likivik/files',
            '/likivik/files/',
            '//files/x.pdf',
            '/likivik/other/x.pdf',
        ] as $bad) {
            $this->assertNull(QueueController::splitPath($bad), 'accepted: ' . $bad);
        }
    }

    public function testPutFileRejectsAWrongSecret(): void
    {
        $response = $this->controller('s3cret', 'nope')->putFile(1);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testPutFileReportsAMissingJob(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('getById')->with(9)->willReturn(null);

        $response = $this->controller('s3cret', 's3cret', $queue)->putFile(9);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testResultFileRejectsAWrongSecret(): void
    {
        $response = $this->controller('s3cret', 'nope')->resultFile(1);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testResultFileReportsAMissingJob(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('getById')->willReturn(null);

        $response = $this->controller('s3cret', 's3cret', $queue)->resultFile(3);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    /**
     * The collision rule is the part of resultFile worth pinning: it decides
     * whether a second OCR overwrites the first, and it is pure.
     */
    public function testNextFreeNameTakesTheFirstGap(): void
    {
        $taken = ['scan.pdf' => true, 'scan (1).pdf' => true];

        $this->assertSame(
            'scan (2).pdf',
            FileExchange::nextFreeName('scan.pdf', static fn (string $n): bool => isset($taken[$n])),
        );
    }

    public function testNextFreeNameKeepsAFreeName(): void
    {
        $this->assertSame(
            'scan.pdf',
            FileExchange::nextFreeName('scan.pdf', static fn (string $n): bool => false),
        );
    }

    public function testNextFreeNameHandlesNamesWithoutExtension(): void
    {
        $this->assertSame(
            'notes (1)',
            FileExchange::nextFreeName('notes', static fn (string $n): bool => $n === 'notes'),
        );
    }

    /**
     * The job is closed by the worker that holds the claim, and only that one.
     *
     * `finish()` takes the claim token, so a worker whose stale claim was
     * recovered and re-claimed elsewhere cannot write its late result over
     * the new claimant's row. The token comes from the header `claim()` minted
     * it into; if the worker did not present it, the endpoint answers 409
     * before `finish()` is ever called.
     */
    public function testResultPassesTheClaimingWorkersTokenToFinish(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('getById')->willReturn($this->claimedJob('tok-7'));
        $queue->expects($this->once())
            ->method('finish')
            ->with(7, $this->isType('array'), 'tok-7')
            ->willReturn(true);

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => match ($name) {
                Application::WORKER_SECRET_HEADER => 'the-secret',
                Application::CLAIM_HEADER => 'tok-7',
                'X-OcrFlow-Worker' => 'worker-7',
                default => '',
            },
        );
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'status' ? 'done' : $default,
        );

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->with(Application::APP_ID, 'worker_secret', '')
            ->willReturn('the-secret');

        $controller = new QueueController(
            Application::APP_ID,
            $request,
            $queue,
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(FileExchange::class),
            new RetryPolicy(),
            $this->createMock(WorkerRegistry::class),
        );

        $data = $controller->result(7)->getData();
        $this->assertTrue($data['applied'], 'a claim holder\'s result must be applied');
    }

    public function testAWorkerThatLostTheClaimIsToldTheResultWasDiscarded(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('getById')->willReturn($this->claimedJob('tok-7'));
        $queue->method('finish')->willReturn(false);

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => match ($name) {
                Application::WORKER_SECRET_HEADER => 'the-secret',
                Application::CLAIM_HEADER => 'tok-7',
                default => '',
            },
        );
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'status' ? 'done' : $default,
        );

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->with(Application::APP_ID, 'worker_secret', '')
            ->willReturn('the-secret');

        $controller = new QueueController(
            Application::APP_ID,
            $request,
            $queue,
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(FileExchange::class),
            new RetryPolicy(),
            $this->createMock(WorkerRegistry::class),
        );

        // Not an error status: the job simply belongs to someone else now
        // (the token matched the endpoint check but lost the database race).
        $response = $controller->result(7);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertFalse($response->getData()['applied']);
    }
}
