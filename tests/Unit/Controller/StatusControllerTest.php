<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\Controller\StatusController;
use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\AccessGate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The user plane must only ever describe the caller's own files.
 *
 * The defect these pin: `job($id)` and `file($path)` answered for ANY job and
 * ANY path, so a signed-in user could enumerate other people's documents by
 * guessing ids or paths and read back the path, the error text, the engine and
 * the page count. The rule is that "does not exist" and "not yours" give the
 * same answer — a difference is itself a disclosure.
 */
class StatusControllerTest extends TestCase
{
    private function config(string $engine = 'tesseract'): IConfig
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $key === 'engine'
                ? $engine
                : $default,
        );
        $config->method('getUserValue')->willReturn('0');

        return $config;
    }

    private function controller(?IUser $user, Queue $queue, ?IRequest $request = null): StatusController
    {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $config = $this->config();
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isInGroup')->willReturn(true);

        return new StatusController(
            'ocrflow',
            $request ?? $this->createMock(IRequest::class),
            $queue,
            $this->createMock(IJobList::class),
            $session,
            new AccessGate($config, $groups),
            new EnginePolicy($config),
        );
    }

    private function user(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    private function job(string $path): Job
    {
        $job = new Job();
        $job->setId(7);
        $job->setPath($path);
        $job->setStatus('done');
        $job->setEngine('tesseract');

        return $job;
    }

    public function testAJobOfAnotherUserAnswersNotFound(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('getById')->willReturn($this->job('bob/files/secret.pdf'));

        $response = $this->controller($this->user('alice'), $queue)->job(7);

        // The same status and body as a genuinely missing job: a guessed id
        // must not reveal that it exists and belongs to someone else.
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['message' => 'no such job'], $response->getData());
    }

    public function testOwnJobIsReturned(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('getById')->willReturn($this->job('alice/files/mine.pdf'));

        $response = $this->controller($this->user('alice'), $queue)->job(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('alice/files/mine.pdf', $response->getData()['path']);
    }

    public function testAMissingJobAnswersNotFound(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('getById')->willReturn(null);

        $this->assertSame(
            Http::STATUS_NOT_FOUND,
            $this->controller($this->user('alice'), $queue)->job(999)->getStatus(),
        );
    }

    public function testNoSessionIsForbidden(): void
    {
        $this->expectException(OCSForbiddenException::class);
        $this->controller(null, $this->createMock(Queue::class))->job(7);
    }

    public function testFileStatusOfAForeignPathLeaksNothing(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->never())->method('findByPath');

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'path'
                ? 'bob/files/secret.pdf'
                : $default,
        );

        $data = $this->controller($this->user('alice'), $queue, $request)->file()->getData();

        // Indistinguishable from a file that was never queued.
        $this->assertSame('unknown', $data['status']);
        $this->assertArrayNotHasKey('engine', $data);
    }

    public function testFileStatusOfOwnPathIsAnswered(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('findByPath')->willReturn($this->job('alice/files/mine.pdf'));

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'path'
                ? 'alice/files/mine.pdf'
                : $default,
        );

        $data = $this->controller($this->user('alice'), $queue, $request)->file()->getData();

        $this->assertSame('done', $data['status']);
        $this->assertSame('tesseract', $data['engine']);
    }
}
