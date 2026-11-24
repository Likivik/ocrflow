<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Storage;

use OCA\OcrFlow\Storage\SpendJournal;
use OCP\IDBConnection;

/**
 * `SpendJournal` over an in-memory store that applies the SAME predicates the
 * production SQL applies (`state = 'reserved'` on every transition, the unique
 * `(job_id, attempts)` run identity, `state != 'released'` in the day's sum).
 * The production SQL shape is pinned separately by the builder-mock test in
 * `SpendJournalTest`.
 */
class StoreJournal extends SpendJournal
{
	/** @var list<array<string, mixed>> */
	private array $rows = [];

	private int $nextId = 1;

	/** Make one run's booking look old — for `sweep()` and for `purge()`. */
	public function ageRun(int $jobId, int $attempts, int $createdAt): void
	{
		foreach ($this->rows as $i => $row) {
			if ($row['job_id'] === $jobId && $row['attempts'] === $attempts) {
				$this->rows[$i]['created_at'] = $createdAt;
				if ($row['settled_at'] !== null) {
					$this->rows[$i]['settled_at'] = $createdAt;
				}
			}
		}
	}

	/** @return list<array<string, mixed>> */
	public function all(): array
	{
		return $this->rows;
	}

	protected function insertRun(array $row): void
	{
		foreach ($this->rows as $existing) {
			if ($existing['job_id'] === $row['job_id'] && $existing['attempts'] === $row['attempts']) {
				throw new \RuntimeException('unique violation: one row per run');
			}
		}
		$row['id'] = $this->nextId++;
		$this->rows[] = $row;
	}

	protected function hasRun(int $jobId, int $attempts): bool
	{
		foreach ($this->rows as $row) {
			if ($row['job_id'] === $jobId && $row['attempts'] === $attempts) {
				return true;
			}
		}

		return false;
	}

	protected function transitionRun(int $jobId, int $attempts, string $fromState, string $toState, ?int $units, int $now): bool
	{
		foreach ($this->rows as $i => $row) {
			if ($row['job_id'] === $jobId && $row['attempts'] === $attempts && $row['state'] === $fromState) {
				$this->rows[$i]['state'] = $toState;
				$this->rows[$i]['settled_at'] = $now;
				if ($units !== null) {
					$this->rows[$i]['units'] = $units;
				}

				return true;
			}
		}

		return false;
	}

	protected function sumToday(string $uid, string $day): int
	{
		$sum = 0;
		foreach ($this->rows as $row) {
			if ($row['uid'] === $uid && $row['day'] === $day && $row['state'] !== 'released') {
				$sum += (int) $row['units'];
			}
		}

		return $sum;
	}

	protected function sumsByState(string $uid, string $day): array
	{
		$sums = ['reserved' => 0, 'spent' => 0, 'released' => 0];
		foreach ($this->rows as $row) {
			if ($row['uid'] === $uid && $row['day'] === $day) {
				$sums[$row['state']] = ($sums[$row['state']] ?? 0) + (int) $row['units'];
			}
		}

		return $sums;
	}

	protected function sweepReserved(int $cutoff): int
	{
		$count = 0;
		foreach ($this->rows as $i => $row) {
			if ($row['state'] === 'reserved' && (int) $row['created_at'] < $cutoff) {
				$this->rows[$i]['state'] = 'released';
				$this->rows[$i]['settled_at'] = time();
				$count++;
			}
		}

		return $count;
	}

	protected function purgeSettled(int $cutoff): int
	{
		$keep = [];
		$count = 0;
		foreach ($this->rows as $row) {
			$isSettledGone = $row['state'] !== 'reserved'
				&& $row['settled_at'] !== null
				&& (int) $row['settled_at'] < $cutoff;
			if ($isSettledGone) {
				$count++;
				continue;
			}
			$keep[] = $row;
		}
		$this->rows = $keep;

		return $count;
	}
}
