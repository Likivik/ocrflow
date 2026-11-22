<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Listener;

use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Listener\DeletedFileListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A deleted file must not stay in the queue.
 *
 * Two production rows read `error_worker_request_malformed` because the owner
 * deleted the file after it was queued: the row survived, a worker claimed it,
 * and the app answered 404 for bytes that no longer existed. The reconciler
 * cannot help — it walks files that exist.
 *
 * The rule narrowed here: only `queued` rows are dropped (the queue method
 * knows that), a folder is ignored, and a bookkeeping failure never breaks the
 * user's delete.
 */
class DeletedFileListenerTest extends TestCase
{
	/** @var list<int> */
	private array $dropped = [];

	/** @var list<string> */
	private array $warnings = [];

	private function listener(?JobStore $queue = null): DeletedFileListener
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});
		$logger->method('info');

		if ($queue === null) {
			$queue = $this->createMock(JobStore::class);
			$queue->method('deleteQueuedForFile')->willReturnCallback(function (int $fileId): int {
				$this->dropped[] = $fileId;

				return 1;
			});
		}

		return new DeletedFileListener($queue, $logger);
	}

	private function file(int $id = 42): File
	{
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn('/u/files/scan.pdf');

		return $file;
	}

	public function testItDropsTheQueuedRowOfADeletedFile(): void
	{
		$this->listener()->handle(new NodeDeletedEvent($this->file(4242)));

		$this->assertSame([4242], $this->dropped);
	}

	public function testAFolderIsIgnored(): void
	{
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(7);

		$this->listener()->handle(new NodeDeletedEvent($folder));

		$this->assertSame([], $this->dropped, 'a folder has no queued job of its own');
	}

	public function testAnotherEventIsIgnored(): void
	{
		$this->listener()->handle(new NodeWrittenEvent($this->file()));

		$this->assertSame([], $this->dropped, 'writing a file is not deleting it');
	}

	public function testAFailureIsLoggedAndNotThrown(): void
	{
		$queue = $this->createMock(JobStore::class);
		$queue->method('deleteQueuedForFile')->willThrowException(new \RuntimeException('db down'));

		$this->listener($queue)->handle(new NodeDeletedEvent($this->file()));

		$this->assertNotEmpty($this->warnings, 'a bookkeeping fault must be visible in the log');
		$this->assertStringContainsString('db down', $this->warnings[0]);
	}
}
