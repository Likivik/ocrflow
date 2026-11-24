<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Storage;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The spend journal: one row per paid engine run, and the only writer of spend
 * state.
 *
 * Money leaves when the engine runs — not when a button is clicked — so the
 * booking happens at claim and the row IS the run: `(job_id, attempts)` is
 * unique and the database refuses to book one run twice. A run's units go
 * exactly one way: `reserved` → `spent` (the engine's real page count) or
 * `reserved` → `released` (the run died before spending). Only `released`
 * gives the day its units back.
 *
 * The day's total is `SUM(units)` where the state is not `released` — reserved
 * and spent both count, because a reserved run is money about to leave and
 * must not be spendable twice. `day` is the reservation's day, so a run that
 * claims at 23:59 and settles after midnight books to the day that
 * authorised it.
 *
 * Overspend at settle is recorded, never refused: money already spent cannot
 * be refused, and a record that hides it is a book that lies. The gate is the
 * next claim.
 */
class SpendJournal
{
	public const TABLE = 'ocrflow_spend_events';

	/** Pages one user may spend per day when the admin has said nothing. */
	public const DEFAULT_CAP = 2000;

	/** How long a reservation may sit before `sweep()` assumes it is dead. */
	public const SWEEP_SECONDS = 86400;

	/** How long settled rows stay for the books. */
	public const RETENTION_DAYS = 90;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly int $capPages = self::DEFAULT_CAP,
	) {
	}

	/**
	 * Book one run. False means the day is spent: the run must wait.
	 *
	 * The gate is a sum-then-insert; the unique `(job_id, attempts)` makes the
	 * common race (two workers, one job) impossible, and the compensating
	 * release bounds any overshoot to one run.
	 */
	public function reserveForRun(string $uid, int $jobId, int $attempts, int $estimateUnits): bool
	{
		$units = max(1, $estimateUnits);
		$day = gmdate('Y-m-d');

		if ($this->sumToday($uid, $day) + $units > $this->capPages) {
			return false;
		}

		try {
			$this->insertRun([
				'uid' => $uid,
				'day' => $day,
				'job_id' => $jobId,
				'attempts' => $attempts,
				'units' => $units,
				'state' => 'reserved',
				'created_at' => time(),
				'settled_at' => null,
			]);
		} catch (\Throwable $e) {
			// One row per run, ever: a duplicate insert means this run is
			// already booked and must not be booked again. Anything else is
			// a real failure and must not look like a booking.
			if ($this->hasRun($jobId, $attempts)) {
				return true;
			}
			throw $e;
		}

		// Two writers can pass the sum at the same moment. If the post-insert
		// sum walked past the cap, this row gives its units back — the
		// overshoot is bounded to one run and never grows.
		if ($this->sumToday($uid, $day) > $this->capPages) {
			$this->transitionRun($jobId, $attempts, 'reserved', 'released', null, time());

			return false;
		}

		return true;
	}

	/**
	 * The run is done: record the pages the engine really read.
	 *
	 * Null means "unknown" — a run whose worker died mid-engine may have read
	 * its pages before it went. The books keep the booked estimate: charging
	 * the hope that nothing was spent is how a cap becomes a suggestion.
	 */
	public function settleForRun(int $jobId, int $attempts, ?int $actualUnits): void
	{
		// WHERE state = 'reserved': a released run cannot be spent later, and
		// a second settle for one run is a no-op rather than a second booking.
		$this->transitionRun($jobId, $attempts, 'reserved', 'spent', $actualUnits === null ? null : max(0, $actualUnits), time());
	}

	/**
	 * The run died before spending: the day gets its units back.
	 */
	public function releaseForRun(int $jobId, int $attempts): void
	{
		$this->transitionRun($jobId, $attempts, 'reserved', 'released', null, time());
	}

	/**
	 * What this user has spent today — reserved and spent both count.
	 */
	public function todayUnits(string $uid): int
	{
		return $this->sumToday($uid, gmdate('Y-m-d'));
	}

	/**
	 * The day split by state, for the panel's one honest number.
	 *
	 * @return array{reserved: int, spent: int, released: int}
	 */
	public function todayByState(string $uid): array
	{
		$sums = $this->sumsByState($uid, gmdate('Y-m-d'));

		return [
			'reserved' => (int) ($sums['reserved'] ?? 0),
			'spent' => (int) ($sums['spent'] ?? 0),
			'released' => (int) ($sums['released'] ?? 0),
		];
	}

	/**
	 * The safety net: a reservation whose release never ran (a crash between
	 * claim and any settle) stops counting against the day. The normal paths
	 * release explicitly; this catches what they cannot.
	 */
	public function sweep(int $olderThanSeconds = self::SWEEP_SECONDS): int
	{
		return $this->sweepReserved(time() - max(1, $olderThanSeconds));
	}

	/**
	 * Books, not evidence: settled rows older than the retention go. Reserved
	 * rows never do — a live booking is not clutter.
	 */
	public function purge(int $olderThanDays = self::RETENTION_DAYS): int
	{
		return $this->purgeSettled(time() - max(1, $olderThanDays) * 86400);
	}

	// ── SQL seams: the production statements below, overridable in tests ──

	protected function insertRun(array $row): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'uid' => $qb->createNamedParameter($row['uid']),
				'day' => $qb->createNamedParameter($row['day']),
				'job_id' => $qb->createNamedParameter($row['job_id'], IQueryBuilder::PARAM_INT),
				'attempts' => $qb->createNamedParameter($row['attempts'], IQueryBuilder::PARAM_INT),
				'units' => $qb->createNamedParameter($row['units'], IQueryBuilder::PARAM_INT),
				'state' => $qb->createNamedParameter($row['state']),
				'created_at' => $qb->createNamedParameter($row['created_at'], IQueryBuilder::PARAM_INT),
				'settled_at' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT),
			]);
		$qb->executeStatement();
	}

	protected function hasRun(int $jobId, int $attempts): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('1'))->from(self::TABLE)
			->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($attempts, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		return $qb->executeQuery()->fetch() !== false;
	}

	protected function transitionRun(int $jobId, int $attempts, string $fromState, string $toState, ?int $units, int $now): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter($toState))
			->set('settled_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($attempts, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($fromState)));
		if ($units !== null) {
			$qb->set('units', $qb->createNamedParameter($units, IQueryBuilder::PARAM_INT));
		}

		return $qb->executeStatement() === 1;
	}

	protected function sumToday(string $uid, string $day): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COALESCE(SUM(units), 0)'))->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->andWhere($qb->expr()->neq('state', $qb->createNamedParameter('released')));
		$row = $qb->executeQuery()->fetch();

		return $row === false ? 0 : (int) array_values($row)[0];
	}

	protected function sumsByState(string $uid, string $day): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('state')->select($qb->createFunction('COALESCE(SUM(units), 0) AS n'))->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->groupBy('state');
		$sums = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$sums[(string) $row['state']] = (int) $row['n'];
		}

		return $sums;
	}

	protected function sweepReserved(int $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter('released'))
			->set('settled_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('state', $qb->createNamedParameter('reserved')))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	protected function purgeSettled(int $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->neq('state', $qb->createNamedParameter('reserved')))
			->andWhere($qb->expr()->lt('settled_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
