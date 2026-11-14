<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * [unit] One list holds both answers a reader acts on.
 *
 * The panel listed `needs_fix` rows only, and `isRetryable()` approves only
 * `wait` rows — inverse sets, so the Retry button was offered on nothing and a
 * list without the retryable rows looked like a list of hopeless files. The
 * list is now `actionable`: needs_fix plus wait, and `retryable` is answered
 * per row by the same policy the endpoint enforces.
 */
class ActionableListTest extends TestCase
{
	private StoreAttention $attention;

	protected function setUp(): void
	{
		$this->attention = new StoreAttention($this->createMock(Queue::class));
	}

	/** @return array<string, mixed> */
	private function row(int $id, string $code, int $finished): array
	{
		return [
			'id' => $id,
			'file_id' => 100 + $id,
			'path' => 'bob/files/' . $code . '.pdf',
			'status' => 'error',
			'reason_code' => $code,
			'reason' => $code,
			'error' => null,
			'attempts' => 1,
			'finished_at' => $finished,
		];
	}

	public function testTheListHoldsBothPermanentAndRetryableRows(): void
	{
		$this->attention->rows = [
			$this->row(1, 'error_file_broken', 100),
			$this->row(2, 'error_provider_timed_out', 200),
			$this->row(3, 'skip_text_native', 300),
		];

		$listed = $this->attention->list(10, 0, null, RetryPolicy::VERDICT_ACTIONABLE);
		$codes = array_column($listed['rows'], 'code');
		sort($codes);

		self::assertSame(
			['error_file_broken', 'error_provider_timed_out'],
			$codes,
			'a needs_fix row and a wait row are listed; a skip is not',
		);
		self::assertSame(2, $listed['total']);
	}

	public function testRetryableIsTrueOnlyForTheWaitRow(): void
	{
		$policy = new RetryPolicy();

		self::assertFalse($policy->isRetryable('error_file_broken'), 'a damaged file cannot be retried into a working one');
		self::assertTrue($policy->isRetryable('error_provider_timed_out'), 'a provider timeout is worth another attempt');
	}

	public function testTheDefaultListIsActionable(): void
	{
		$this->attention->rows = [
			$this->row(1, 'error_file_broken', 100),
			$this->row(2, 'error_provider_timed_out', 200),
		];

		self::assertSame(
			2,
			$this->attention->list(10)['total'],
			'the panel must not have to ask for the retryable rows specially',
		);
	}
}
