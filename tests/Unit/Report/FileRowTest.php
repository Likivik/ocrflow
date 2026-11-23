<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Report;

use OCA\OcrFlow\Report\CategoryRow;
use OCA\OcrFlow\Report\FileRow;
use PHPUnit\Framework\TestCase;

/**
 * [unit] Two shapes, and the difference is the point.
 *
 * A CategoryRow is a count under a label — no fileId, no path. A FileRow is
 * one file — an id, a path, one sentence. Keeping them separate is what stops a
 * block being counted as if it were one file.
 */
class FileRowTest extends TestCase
{
	public function testACategoryRowIsACountAndALabel(): void
	{
		$row = CategoryRow::of('queued', 'Waiting to be processed', 3);

		$this->assertSame(
			['key' => 'queued', 'label' => 'Waiting to be processed', 'count' => 3, 'title' => null, 'below_bar' => false],
			$row,
		);
		$this->assertArrayNotHasKey('fileId', $row);
		$this->assertArrayNotHasKey('path', $row);
	}

	public function testAFileRowIsOneFile(): void
	{
		$row = FileRow::of([
			'id' => 9,
			'file_id' => 7,
			'path' => 'alice/files/scan.pdf',
			'reason_code' => 'error_file_broken',
			'error' => 'xref table is gone',
			'attempts' => 2,
			'finished_at' => time() - 60,
		], 'mine', true);

		$this->assertSame(9, $row['id']);
		$this->assertSame(7, $row['file_id']);
		$this->assertSame('alice/files/scan.pdf', $row['path']);
		$this->assertSame('error_file_broken', $row['code']);
		$this->assertSame('This file is damaged and cannot be read.', $row['text']);
		$this->assertSame('mine', $row['origin']);
		$this->assertTrue($row['retryable']);
		$this->assertSame(2, $row['attempts']);
		$this->assertArrayNotHasKey('fileId', $row, 'the wire keeps its own names; the shape is what is unified');
	}

	public function testTheOldReasonSpellingStillResolvesToACode(): void
	{
		// Rows written before 0.1.5 carry the code in `reason`.
		$row = FileRow::of(['id' => 1, 'file_id' => 2, 'path' => 'p', 'reason' => 'error_file_broken']);

		$this->assertSame('error_file_broken', $row['code']);
	}

	public function testTheWorklistsDoNotTruncateTheProseButTheAttentionListDoes(): void
	{
		$raw = ['id' => 1, 'file_id' => 2, 'path' => 'p', 'error' => str_repeat('x', 900)];

		$this->assertSame(900, mb_strlen(FileRow::of($raw)['error']));
		$this->assertSame(501, mb_strlen(FileRow::of($raw, null, null, 500)['error']), '500 characters and an ellipsis');
	}

	public function testACodeFromANewerWorkerStillRendersARow(): void
	{
		$row = FileRow::of(['id' => 1, 'file_id' => 2, 'path' => 'p', 'reason_code' => 'no_such_code']);

		$this->assertSame('error_worker_sent_unknown_code', $row['code']);
		$this->assertSame('The worker reported a code this version of the app does not know.', $row['text'], 'the unknown code owns the sentence');
	}
}
