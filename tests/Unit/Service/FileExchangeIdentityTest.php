<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\FileExchange;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The write-back's identity: `file_id` + `etag`, both paths.
 *
 * Catches: a produced file whose etag is not returned — the ledger's
 * settled verdict is keyed on the etag, so a settle without it would
 * match every future version of the file (or none), and the scan would
 * re-queue the produced bytes (the 8135 loop) or skip a user's new ones.
 */
class FileExchangeIdentityTest extends TestCase
{
	private function exchange(Folder $folder, File $file): FileExchange
	{
		$root = $this->createMock(IRootFolder::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturnCallback(
			function (string $path) use ($folder, $file) {
				return $path === 'Work' ? $folder : $file;
			}
		);
		$root->method('getUserFolder')->willReturn($userFolder);

		$validator = $this->createMock(IFilenameValidator::class);

		return new FileExchange($root, $validator);
	}

	public function testReplaceReturnsTheFileIdAndTheNewEtag(): void
	{
		$file = $this->createMock(File::class);
		$file->method('putContent')->with('bytes');
		$file->method('getSize')->willReturn(10);
		$file->method('getEtag')->willReturn('new-etag');
		$file->method('getId')->willReturn(42);

		$written = $this->exchange($this->createMock(Folder::class), $file)
			->replace('alice', 'Work/scan.pdf', 'bytes');

		$this->assertSame(42, $written['file_id'], 'the produced identity needs the file_id');
		$this->assertSame('new-etag', $written['etag'], 'the produced identity needs the etag of the bytes just written');
		$this->assertSame(10, $written['size']);
	}

	public function testCreateBesideReturnsTheNewFileIdAndItsEtag(): void
	{
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$created = $this->createMock(File::class);
		$created->method('getId')->willReturn(99);
		$created->method('getEtag')->willReturn('produced-etag');
		$created->method('getSize')->willReturn(10);
		$folder->method('newFile')->with('scan.pdf', 'bytes')->willReturn($created);

		$stored = $this->exchange($folder, $this->createMock(File::class))
			->createBeside('alice', 'Work/photo.jpg', 'scan.pdf', 'bytes');

		$this->assertSame(99, $stored['file_id']);
		$this->assertSame('produced-etag', $stored['etag'], 'a produced file without its etag would match every future version');
		$this->assertSame(10, $stored['size']);
	}

	public function testANotFoundReplaceStillThrows(): void
	{
		$this->expectException(NotFoundException::class);

		$root = $this->createMock(IRootFolder::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willThrowException(new NotFoundException());
		$root->method('getUserFolder')->willReturn($userFolder);

		(new FileExchange($root, $this->createMock(IFilenameValidator::class)))
			->replace('alice', 'Work/gone.pdf', 'bytes');
	}
}
