<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Db\Job;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The "rescan anyway" bit is a bool on the row, a bool in the API.
 *
 * Two failure modes this pins, both silent:
 *
 *   * `force` stored as the integer 1 comes back as the string "1" from some
 *     drivers, and (bool) "0" is true — the classic way a retry flag ends up
 *     permanently on, which would make every later scan of that file bypass
 *     the skip bar and re-bill the engine;
 *   * a row written before the column existed (`force` absent) must read as
 *     false, not throw and not null: the worker's `item.get("force", False)`
 *     receives whatever this emits.
 */
class JobForceApiTest extends TestCase
{
	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function row(array $overrides = []): array
	{
		return array_merge([
			'id' => 41,
			'file_id' => 7,
			'path' => 'alice/files/scan.pdf',
			'status' => 'skipped',
			'reason' => 'suspected foreign text layer',
			'reason_code' => 'skip_ocred_by_suspected_foreign',
			'retryable' => false,
			'engine' => null,
			'claim_token' => null,
			'claimed_by_worker_id' => null,
			'attempts' => 0,
			'size' => 1024,
			'etag' => 'abc123',
			'source_sha256' => null,
			'written_etag' => null,
			'pages' => null,
			'chars' => null,
			'error' => null,
			'created_at' => 1_760_000_000,
			'claimed_at' => null,
			'finished_at' => null,
			'force' => 0,
		], $overrides);
	}

	public function testAForcedRowReachesTheWorkerAsTrue(): void
	{
		$api = Job::fromRow($this->row(['force' => 1]))->toApi();
		$this->assertTrue($api['force']);
	}

	public function testAnOrdinaryRowIsFalse(): void
	{
		$this->assertFalse(Job::fromRow($this->row(['force' => 0]))->toApi()['force']);
	}

	public function testARowFromBeforeTheColumnReadsFalse(): void
	{
		$row = $this->row();
		unset($row['force']);
		$this->assertFalse(Job::fromRow($row)->toApi()['force']);
	}

	public function testTheStringZeroIsNotTrue(): void
	{
		// (bool) '0' === false in PHP, but (bool) 'some string' is true — a
		// driver returning '' or 'f' must not switch a force on.
		$this->assertFalse(Job::fromRow($this->row(['force' => '0']))->toApi()['force']);
		$this->assertFalse(Job::fromRow($this->row(['force' => 'f']))->toApi()['force']);
		$this->assertTrue(Job::fromRow($this->row(['force' => '1']))->toApi()['force']);
		$this->assertTrue(Job::fromRow($this->row(['force' => 't']))->toApi()['force']);
	}
}
