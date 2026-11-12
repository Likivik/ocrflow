<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The worker registry (`ocrflow_workers`): which workers are alive.
 *
 * A worker registers once at startup — its name (from `NC_OCR_WORKER_NAME`)
 * and its own version — and gets back the row id it heartbeats with. The row
 * exists so a claim can point at a real holder (`claimed_by_worker_id`) and
 * stale-claim recovery can ask *evidence* — "is this worker still beating?" —
 * instead of guessing from a timer.
 *
 * The registry is not authentication: any holder of the shared worker secret
 * can register and claim new jobs. It scopes what a claim belongs to.
 */
class WorkerRegistry
{
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Insert one worker row, answer its id. Called once per process start;
	 * a restart is a new row, and pruning forgets the old one.
	 */
	public function register(string $name, ?string $version): int
	{
		$now = new \DateTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('ocrflow_workers')
			->values([
				'name' => $qb->createNamedParameter($name),
				'version' => $qb->createNamedParameter($version),
				'started_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE),
				'last_seen_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE),
			]);
		$qb->executeStatement();
		return (int) $qb->getLastInsertId();
	}

	/**
	 * Refresh `last_seen_at`. Answers false when the row is gone (pruned
	 * while the worker was down or between calls) — the caller must register
	 * again, which gives it a fresh row and a fresh claim identity.
	 */
	public function heartbeat(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('ocrflow_workers')
			->set('last_seen_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\Types::INTEGER)))
			->executeStatement();
		return $affected === 1;
	}

	/**
	 * Delete rows whose heartbeat fell behind. Returns the row count.
	 */
	public function pruneStale(int $olderThanSeconds): int
	{
		$cutoff = new \DateTime('@' . (time() - $olderThanSeconds));
		$qb = $this->db->getQueryBuilder();
		return $qb->delete('ocrflow_workers')
			->where($qb->expr()->lt('last_seen_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->executeStatement();
	}
}
