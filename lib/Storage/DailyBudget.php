<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Storage;

use OCA\OcrFlow\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;

/**
 * The daily budget: how many files one user's button may spend today.
 *
 * One owner for the money. The old code did arithmetic in the controller —
 * read a counter from `oc_preferences`, compare, queue, write the sum back —
 * which lost writes under two tabs and treated the cap as a gate instead of a
 * budget (195 of 200 used, one click queued 200 more).
 *
 * The rule here is arithmetic-free for callers: `reserve()` returns what the
 * click may take, clamped to what is left. Every increment is one conditional
 * `UPDATE … SET used = used + n WHERE used + n <= cap` — the database
 * serialises concurrent writers, so a grant can never walk past the cap and
 * no increment can be lost. The same statement is the lock and the check.
 *
 * The key is (uid, day); a new day is a new row, so nothing resets counters.
 */
class DailyBudget
{
	public const TABLE = 'ocrflow_spend';

	/** How many files one user may queue per day by default. */
	public const DEFAULT_DAILY_CAP = 200;

	/** How many files one click may take by default. */
	public const DEFAULT_PER_CLICK = 200;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ?IConfig $config = null,
	) {
	}

	/**
	 * The admin's daily ceiling for one user.
	 */
	public function cap(): int
	{
		return $this->appInt('process_daily_cap', self::DEFAULT_DAILY_CAP);
	}

	/**
	 * How many files one click may take: the admin's ceiling, then ours.
	 */
	public function perClickCap(): int
	{
		// Hard-capped at ours: the admin may lower one click, not raise it.
		return min($this->appInt('process_max_files', self::DEFAULT_PER_CLICK), self::DEFAULT_PER_CLICK);
	}

	/**
	 * What this user has spent today.
	 */
	public function used(string $uid): int
	{
		return $this->usedFor($uid, gmdate('Y-m-d'));
	}

	/**
	 * Take what the click may spend: the ask, clamped to what is left of the
	 * daily cap. The grant is truth — a caller that queues exactly `granted`
	 * cannot exceed the budget, whatever it asked for.
	 *
	 * @return array{granted: int, used: int, cap: int} `used` is after the grant
	 */
	public function reserve(string $uid, int $want): array
	{
		$cap = $this->cap();
		// The clamp applies again here, not only in the panel: a client is
		// not trusted to respect its own field.
		$want = max(0, min($want, $this->perClickCap()));
		$day = gmdate('Y-m-d');

		$granted = 0;
		if ($want > 0) {
			$this->ensureRow($uid, $day);
			// The full ask first — one statement either grants it or does
			// not. A refusal is not an error; it means "ask again for less".
			if ($this->addUsed($uid, $day, $want, $cap) === 1) {
				$granted = $want;
			} else {
				// What is left is what the click may take. The guard runs
				// again on the smaller number, so even this read-then-add
				// cannot overshoot: if someone raced us into the last slot,
				// the statement simply refuses.
				$left = max(0, $cap - $this->usedFor($uid, $day));
				if ($left > 0 && $this->addUsed($uid, $day, $left, $cap) === 1) {
					$granted = $left;
				}
			}
		}

		return [
			'granted' => $granted,
			'used' => $this->usedFor($uid, $day),
			'cap' => $cap,
		];
	}

	/**
	 * Give back what was granted but never queued: a click that found only
	 * two candidates must not charge the day for five. The reserve is the
	 * lock; the refund is one more guarded statement, and `used >= n` in the
	 * WHERE keeps the sum of refunds from ever touching another click's
	 * grant.
	 */
	public function refund(string $uid, int $n): void
	{
		if ($n <= 0) {
			return;
		}
		$day = gmdate('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('used', $qb->createFunction('used - ' . $qb->createNamedParameter($n, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->andWhere($qb->expr()->gte(
				$qb->createFunction('used'),
				$qb->createNamedParameter($n, IQueryBuilder::PARAM_INT),
			));
		$qb->executeStatement();
	}

	/**
	 * The atomic half: `used = used + n` only while the cap still allows it.
	 * One statement is the check and the write together — the reason this
	 * class exists.
	 */
	protected function addUsed(string $uid, string $day, int $n, int $cap): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('used', $qb->createFunction('used + ' . $qb->createNamedParameter($n, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->andWhere($qb->expr()->lte(
				$qb->createFunction('used + ' . $qb->createNamedParameter($n, IQueryBuilder::PARAM_INT)),
				$qb->createNamedParameter($cap, IQueryBuilder::PARAM_INT),
			));

		return $qb->executeStatement();
	}

	/**
	 * A row per user per day; the primary key (uid, day) is what makes a
	 * repeated ensure harmless.
	 */
	protected function ensureRow(string $uid, string $day): void
	{
		$this->db->insertIfNotExist(self::TABLE, [
			'uid' => $uid,
			'day' => $day,
			'used' => 0,
		], ['uid', 'day']);
	}

	protected function usedFor(string $uid, string $day): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('used')->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();

		return $row === false ? 0 : (int) $row['used'];
	}

	private function appInt(string $key, int $default): int
	{
		$raw = trim((string) ($this->config?->getAppValue(Application::APP_ID, $key, (string) $default) ?? $default));

		return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : $default;
	}
}
