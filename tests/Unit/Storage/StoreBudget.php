<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Storage;

use OCA\OcrFlow\Storage\DailyBudget;
use OCP\IDBConnection;

/**
 * `DailyBudget` over an in-memory store that applies the SAME predicate the
 * production SQL applies (`used + n <= cap` in one conditional UPDATE) — so a
 * test can bite on real numbers instead of a mock that replays the query we
 * just wrote. The production SQL shape is pinned separately by the
 * builder-mock test in `DailyBudgetTest`.
 */
class StoreBudget extends DailyBudget
{
	/** @var array<string, int> day|uid => used */
	private array $rows = [];

	public function __construct(
		IDBConnection $db,
		private readonly int $capValue = 200,
		private readonly int $perClickValue = 200,
	) {
		parent::__construct($db);
	}

	/** Put a number in the day's row, as if earlier clicks had spent it. */
	public function setUsed(string $uid, int $used): void
	{
		$this->rows[gmdate('Y-m-d') . '|' . $uid] = $used;
	}

	public function cap(): int
	{
		return $this->capValue;
	}

	public function perClickCap(): int
	{
		return $this->perClickValue;
	}

	protected function ensureRow(string $uid, string $day): void
	{
		$this->rows[$day . '|' . $uid] ??= 0;
	}

	protected function addUsed(string $uid, string $day, int $n, int $cap): int
	{
		$key = $day . '|' . $uid;
		$used = $this->rows[$key] ?? 0;
		if ($used + $n > $cap) {
			return 0;
		}
		$this->rows[$key] = $used + $n;

		return 1;
	}

	public function refund(string $uid, int $n): void
	{
		// The same predicate the production UPDATE carries: `used >= n`.
		$key = gmdate('Y-m-d') . '|' . $uid;
		$used = $this->rows[$key] ?? 0;
		if ($n > 0 && $used >= $n) {
			$this->rows[$key] = $used - $n;
		}
	}

	protected function usedFor(string $uid, string $day): int
	{
		return $this->rows[$day . '|' . $uid] ?? 0;
	}
}
