<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The queue, and the only place that knows what a job row means.
 *
 * Claiming is deliberately portable: no `SKIP LOCKED`, no `RETURNING`. MySQL
 * has neither, and the app has to install on all three supported databases. A
 * claim is a conditional UPDATE keyed on the row still being `queued`; if
 * another worker got there first the update touches zero rows and we try the
 * next candidate. That is enough for a worker pool of one or a few.
 */
class Queue extends QBMapper
{
	public const STATUS_QUEUED = 'queued';
	public const STATUS_CLAIMED = 'claimed';
	public const STATUS_DONE = 'done';
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_ERROR = 'error';

	private const JOBS = 'ocrflow_jobs';
	private const FILES = 'ocrflow_files';
	private const MAX_CLAIM_TRIES = 8;

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::JOBS, Job::class);
	}

	/**
	 * Queue one path. Returns null when the path is already in flight or done —
	 * the caller treats null as "nothing to do", not as an error.
	 *
	 * @throws Exception
	 */
	public function enqueue(string $path, ?int $fileId, ?int $size, ?string $etag): ?int
	{
		$existing = $this->findByPath($path);
		if ($existing !== null) {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::JOBS)
			->values([
				'path' => $qb->createNamedParameter($path),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'status' => $qb->createNamedParameter(self::STATUS_QUEUED),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
				'etag' => $qb->createNamedParameter($etag),
			]);
		try {
			$qb->executeStatement();
		} catch (Exception $e) {
			// A concurrent insert won the unique(path) race. Same answer as
			// "already queued": nothing for this caller to do.
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return null;
			}
			throw $e;
		}

		return (int) $this->db->getLastInsertId(self::JOBS);
	}

	/**
	 * @throws Exception
	 */
	public function findByPath(string $path): ?Job
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::JOBS)
			->where($qb->expr()->eq('path', $qb->createNamedParameter($path)))
			->setMaxResults(1);
		$rows = $qb->executeQuery()->fetchAll();
		return $rows === [] ? null : Job::fromRow($rows[0]);
	}

	/**
	 * Oldest queued job, now owned by $worker. Null when the queue is empty.
	 *
	 * @throws Exception
	 */
	public function claim(string $worker): ?Job
	{
		for ($try = 0; $try < self::MAX_CLAIM_TRIES; $try++) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from(self::JOBS)
				->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_QUEUED)))
				->orderBy('id', 'ASC')
				->setMaxResults(1);
			$row = $qb->executeQuery()->fetch();
			if ($row === false) {
				return null;
			}
			$id = (int) $row['id'];

			$up = $this->db->getQueryBuilder();
			$up->update(self::JOBS)
				->set('status', $up->createNamedParameter(self::STATUS_CLAIMED))
				->set('claimed_by', $up->createNamedParameter($worker))
				->set('claimed_at', $up->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->set('attempts', $up->createFunction('attempts + 1'))
				->where($up->expr()->eq('id', $up->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($up->expr()->eq('status', $up->createNamedParameter(self::STATUS_QUEUED)));
			if ($up->executeStatement() === 1) {
				return $this->getById($id);
			}
			// Someone else claimed it between our SELECT and UPDATE: try again.
		}
		return null;
	}

	/**
	 * @throws Exception
	 */
	public function getById(int $id): ?Job
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::JOBS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$rows = $qb->executeQuery()->fetchAll();
		return $rows === [] ? null : Job::fromRow($rows[0]);
	}

	/**
	 * Finish a job and record what the file now is. One write to the job row and
	 * one to the per-file row, so the Files sidebar answer does not depend on the
	 * history table being complete.
	 *
	 * @param array{status: string, reason?: ?string, engine?: ?string, pages?: ?int,
	 *              chars?: ?int, stamp_version?: ?string, error?: ?string} $result
	 * @throws Exception
	 */
	public function finish(int $id, array $result): void
	{
		$job = $this->getById($id);
		if ($job === null) {
			return;
		}

		$status = $result['status'];
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::JOBS)
			->set('status', $qb->createNamedParameter($status))
			->set('reason', $qb->createNamedParameter($result['reason'] ?? null))
			->set('engine', $qb->createNamedParameter($result['engine'] ?? null))
			->set('pages', $qb->createNamedParameter($result['pages'] ?? null, IQueryBuilder::PARAM_INT))
			->set('chars', $qb->createNamedParameter($result['chars'] ?? null, IQueryBuilder::PARAM_INT))
			->set('error', $qb->createNamedParameter($result['error'] ?? null))
			->set('finished_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$this->recordFile($job, $status, $result);
	}

	/**
	 * @param array<string, mixed> $result
	 * @throws Exception
	 */
	private function recordFile(Job $job, string $status, array $result): void
	{
		if ($job->getFileId() === null) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::FILES)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($job->getFileId(), IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$exists = $qb->executeQuery()->fetch() !== false;

		$values = [
			'status' => $qb->createNamedParameter($status),
			'reason' => $qb->createNamedParameter($result['reason'] ?? null),
			'engine' => $qb->createNamedParameter($result['engine'] ?? null),
			'stamp_version' => $qb->createNamedParameter($result['stamp_version'] ?? null),
			'path' => $qb->createNamedParameter($job->getPath()),
			'size' => $qb->createNamedParameter($job->getSize(), IQueryBuilder::PARAM_INT),
			'etag' => $qb->createNamedParameter($job->getEtag()),
			'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		];

		$w = $this->db->getQueryBuilder();
		if ($exists) {
			$w->update(self::FILES);
			foreach ($values as $column => $param) {
				$w->set($column, $param);
			}
			$w->where($w->expr()->eq('file_id', $w->createNamedParameter($job->getFileId(), IQueryBuilder::PARAM_INT)));
		} else {
			$w->insert(self::FILES)
				->values($values + [
					'file_id' => $w->createNamedParameter($job->getFileId(), IQueryBuilder::PARAM_INT),
				]);
		}
		$w->executeStatement();
	}

	/**
	 * Put a claimed job back in the queue — used when a worker dies mid-job and
	 * its claim goes stale.
	 *
	 * @throws Exception
	 */
	public function requeueStale(int $olderThanSeconds): int
	{
		$cutoff = time() - $olderThanSeconds;
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::JOBS)
			->set('status', $qb->createNamedParameter(self::STATUS_QUEUED))
			->set('claimed_by', $qb->createNamedParameter(null))
			->set('claimed_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CLAIMED)))
			->andWhere($qb->expr()->lt('claimed_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * @return array<string, int>
	 * @throws Exception
	 */
	public function counts(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')
			->selectAlias($qb->func()->count('*'), 'n')
			->from(self::JOBS)
			->groupBy('status');
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[(string) $row['status']] = (int) $row['n'];
		}
		return $out;
	}
}
