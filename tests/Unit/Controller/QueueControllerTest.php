<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Controller\QueueController;
use OCA\OcrFlow\Db\Queue;
use OCP\AppFramework\Http;
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
        );
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

    public function testFailureEndpointRejectsAWrongSecret(): void
    {
        $response = $this->controller('s3cret', 'nope')->failure(7);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }
}
