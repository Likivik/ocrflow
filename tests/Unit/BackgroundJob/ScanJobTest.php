<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\BackgroundJob;

use OCA\OcrFlow\BackgroundJob\ScanJob;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Candidate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/oc_hooks_emitter.php';

/**
 * The walk must respect its file budget at the point of cost, not only at the
 * point of counting: fetching a folder's children is the expensive step (a
 * folder with 100 000 children costs a 100 000-node listing and a
 * 100 000-entry stack), so once the budget is spent no further
 * `getDirectoryListing()` may run at all.
 *
 * The budget comes from config (`scan_max_files`, default 20 000), so an
 * operator can size the walk to their instance instead of recompiling.
 */
class ScanJobTest extends TestCase
{
    private ITimeFactory&MockObject $time;
    private IRootFolder&MockObject $rootFolder;
    private Queue&MockObject $queue;
    private LoggerInterface&MockObject $logger;
    private IConfig&MockObject $config;
    /** @var list<string> */
    private array $enqueuedPaths = [];
    private string $maxFiles = '20000';

    protected function setUp(): void
    {
        $this->time = $this->createMock(ITimeFactory::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->queue = $this->createMock(Queue::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(IConfig::class);

        $this->config->method('getAppValue')->willReturnCallback(
            function (string $appId, string $key): string {
                if ($key === 'engine') {
                    return 'tesseract';
                }
                return $this->maxFiles;
            },
        );

        $this->queue->method('enqueue')->willReturnCallback(function (string $path): ?int {
            $this->enqueuedPaths[] = $path;
            return 1;
        });
    }

    private function job(string $maxFiles): ScanJob
    {
        $this->maxFiles = $maxFiles;

        return new ScanJob(
            $this->time,
            $this->rootFolder,
            $this->queue,
            new Candidate(),
            new EnginePolicy($this->config),
            $this->logger,
            $this->config,
        );
    }

    private function file(string $path): File&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn(basename($path));
        $file->method('getPath')->willReturn($path);
        $file->method('getId')->willReturn(crc32($path));
        $file->method('getSize')->willReturn(1024);
        $file->method('getEtag')->willReturn('etag');
        return $file;
    }

    private function folder(string $path, array $children): Folder&MockObject
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getPath')->willReturn($path);
        $folder->method('getDirectoryListing')->willReturn($children);
        return $folder;
    }

    private static function runJob(ScanJob $job, array $argument): void
    {
        // `run()` is protected and `start()` needs a live IJobList; the unit
        // under test is the walk, not the job-list plumbing.
        $method = new \ReflectionMethod(ScanJob::class, 'run');
        $method->invoke($job, $argument);
    }

    private function root(Folder $target): void
    {
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->with('scan')->willReturn($target);
        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
    }

    public function testFolderIsNeverListedOnceTheBudgetIsExhausted(): void
    {
        // folderA alone overflows the budget, so folderB must never even be
        // listed — its listing is the cost this test exists to prevent.
        $folderA = $this->folder('/alice/files/scan/a', [
            $this->file('/alice/files/scan/a/1.pdf'),
            $this->file('/alice/files/scan/a/2.pdf'),
            $this->file('/alice/files/scan/a/3.pdf'),
            $this->file('/alice/files/scan/a/4.pdf'),
        ]);
        $folderA->expects($this->once())->method('getDirectoryListing');

        $folderB = $this->folder('/alice/files/scan/b', [
            $this->file('/alice/files/scan/b/1.pdf'),
        ]);
        $folderB->expects($this->never())->method('getDirectoryListing');

        $target = $this->folder('/alice/files/scan', [$folderA, $folderB]);
        $this->root($target);

        $this->runJob($this->job('3'), ['user' => 'alice', 'path' => '/scan']);

        $this->assertCount(3, $this->enqueuedPaths, 'the walk stops at the budget, not after it');
    }

    public function testASkippedFolderIsNamedInOneWarning(): void
    {
        // The budget is spent exactly, so the next node popped is folderB — a
        // folder — and the operator must be told where the tree was cut.
        $folderA = $this->folder('/alice/files/scan/a', [
            $this->file('/alice/files/scan/a/1.pdf'),
            $this->file('/alice/files/scan/a/2.pdf'),
            $this->file('/alice/files/scan/a/3.pdf'),
        ]);
        $folderB = $this->folder('/alice/files/scan/b', [
            $this->file('/alice/files/scan/b/1.pdf'),
        ]);
        $folderB->expects($this->never())->method('getDirectoryListing');

        $target = $this->folder('/alice/files/scan', [$folderA, $folderB]);
        $this->root($target);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('/alice/files/scan/b'));

        $this->runJob($this->job('3'), ['user' => 'alice', 'path' => '/scan']);

        $this->assertCount(3, $this->enqueuedPaths);
    }

    public function testNestedFoldersAreWalkedWhenTheBudgetAllows(): void
    {
        $inner = $this->folder('/alice/files/scan/a/inner', [
            $this->file('/alice/files/scan/a/inner/2.pdf'),
        ]);
        $outer = $this->folder('/alice/files/scan/a', [
            $this->file('/alice/files/scan/a/1.pdf'),
            $inner,
        ]);
        $target = $this->folder('/alice/files/scan', [$outer]);
        $this->root($target);

        $this->runJob($this->job('20000'), ['user' => 'alice', 'path' => '/scan']);

        $this->assertSame(
            ['/alice/files/scan/a/inner/2.pdf', '/alice/files/scan/a/1.pdf'],
            $this->enqueuedPaths,
            'both files of the nested tree are queued',
        );
    }

    public function testAnEmptyFolderIsListedButQueuesNothing(): void
    {
        $target = $this->folder('/alice/files/scan', [
            $this->folder('/alice/files/scan/empty', []),
        ]);
        $target->expects($this->once())->method('getDirectoryListing');
        $this->root($target);

        $this->logger->expects($this->never())->method('warning');

        $this->runJob($this->job('20000'), ['user' => 'alice', 'path' => '/scan']);

        $this->assertSame([], $this->enqueuedPaths);
    }

    public function testAGarbageBudgetFallsBackToTheDefault(): void
    {
        // A typo in config must not become "walk zero files" or "walk
        // everything"; it becomes the documented default.
        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $files[] = $this->file("/alice/files/scan/{$i}.pdf");
        }
        $target = $this->folder('/alice/files/scan', $files);
        $this->root($target);

        $this->runJob($this->job('yes'), ['user' => 'alice', 'path' => '/scan']);

        $this->assertCount(5, $this->enqueuedPaths);
    }
}
