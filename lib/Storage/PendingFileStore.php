<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Storage;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Durable desired work: the latest observation of a file that still needs an
 * enqueue decision.
 *
 * A row is not a run and not a settled verdict. It exists so an event that
 * arrives while a claim owns the file is remembered instead of dropped. The
 * revision is the compare-and-swap fence: a slow drain may clear only the
 * observation it read.
 */
class PendingFileStore
{
	private const TABLE = 'ocrflow_pending_files';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** Record the latest metadata and answer the new revision. */
	public function observe(int $fileId, string $path, ?string $etag, ?int $size): int
	{
		$now = time();
		$update = $this->db->getQueryBuilder();
		$update->update(self::TABLE)
			->set('path', $update->createNamedParameter($path))
			->set('etag', $update->createNamedParameter($etag))
			->set('size', $update->createNamedParameter($size, IQueryBuilder::PARAM_INT))
			->set('revision', $update->createFunction('revision + 1'))
			->set('observed_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('file_id', $update->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		if ($update->executeStatement() === 1) {
			$row = $this->get($fileId);
			return (int) ($row['revision'] ?? 1);
		}

		$insert = $this->db->getQueryBuilder();
		$insert->insert(self::TABLE)
			->values([
				'file_id' => $insert->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'path' => $insert->createNamedParameter($path),
				'etag' => $insert->createNamedParameter($etag),
				'size' => $insert->createNamedParameter($size, IQueryBuilder::PARAM_INT),
				'revision' => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'observed_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			]);
		try {
			$insert->executeStatement();
			return 1;
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}

		// Another process inserted first: add to its revision instead.
		$retry = $this->db->getQueryBuilder();
		$retry->update(self::TABLE)
			->set('path', $retry->createNamedParameter($path))
			->set('etag', $retry->createNamedParameter($etag))
			->set('size', $retry->createNamedParameter($size, IQueryBuilder::PARAM_INT))
			->set('revision', $retry->createFunction('revision + 1'))
			->set('observed_at', $retry->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($retry->expr()->eq('file_id', $retry->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$retry->executeStatement();
		$row = $this->get($fileId);
		return (int) ($row['revision'] ?? 2);
	}

	/** @return array{path: string, etag: ?string, size: ?int, revision: int}|null */
	public function get(int $fileId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('path', 'etag', 'size', 'revision')
			->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		return [
			'path' => (string) $row['path'],
			'etag' => $row['etag'] === null ? null : (string) $row['etag'],
			'size' => $row['size'] === null ? null : (int) $row['size'],
			'revision' => (int) $row['revision'],
		];
	}

	public function deleteIfRevision(int $fileId, int $revision): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement() === 1;
	}

	/** @return int[] */
	public function ids(int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id')
			->from(self::TABLE)
			->orderBy('observed_at', 'ASC')
			->setMaxResults(max(1, $limit));
		$ids = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$ids[] = (int) $row['file_id'];
		}
		return $ids;
	}
}
