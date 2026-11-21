<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Storage;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Which files on disk are ours to consider — once.
 *
 * Before this class the same predicate existed three times: once to list ids
 * (`Queue::diskFileIds()`), once to count zero-byte files, once to count TIFFs.
 * Three copies meant three chances to disagree, and a report whose block totals
 * did not add up to the same set it was counting.
 *
 * The scopes are deliberately separate rather than one query split three ways:
 * `processable` pins `size > 0` and the readable extensions, `empty` needs
 * `size = 0`, and `unreadable` is TIFF — a format that is deliberately *not* in
 * `READABLE_EXTS`. Splitting `processable` would report zero for the other two.
 */
class DiskFiles
{
	/** Extensions the pipeline reads. Mirrors `Queue::READABLE_EXTS`. */
	public const READABLE_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];

	/** Extensions we can see but cannot read yet. */
	public const UNREADABLE_EXTS = ['tif', 'tiff'];

	public function __construct(private IDBConnection $db)
	{
	}

	/**
	 * The user's own file tree: their storage, under `files/`.
	 *
	 * Adds the `storages` join, so callers must use it on a builder whose first
	 * table is aliased `$alias` (default `f`) and must not have joined `storages`
	 * themselves.
	 */
	public function constrain(IQueryBuilder $qb, string $uid, string $alias = 'f'): void
	{
		$qb->innerJoin($alias, 'storages', 'storages', $qb->expr()->eq('storages.numeric_id', $alias . '.storage'))
			->andWhere($qb->expr()->eq('storages.id', $qb->createNamedParameter('home::' . $uid)))
			->andWhere($qb->expr()->like($alias . '.path', $qb->createNamedParameter('files/%')));
	}

	/**
	 * Files we are willing to read: non-empty, known extension.
	 */
	public function processableOnly(IQueryBuilder $qb, string $alias = 'f'): void
	{
		$qb->andWhere($qb->expr()->gt($alias . '.size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(...$this->extensionPredicates($qb, self::READABLE_EXTS, $alias)));
	}

	/**
	 * Files with no content: in a format we read, but zero bytes.
	 */
	public function emptyOnly(IQueryBuilder $qb, string $alias = 'f'): void
	{
		$qb->andWhere($qb->expr()->eq($alias . '.size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(...$this->extensionPredicates($qb, self::READABLE_EXTS, $alias)));
	}

	/**
	 * Files on disk in a format the pipeline does not read (TIFF today).
	 */
	public function unreadableOnly(IQueryBuilder $qb, string $alias = 'f'): void
	{
		$qb->andWhere($qb->expr()->gt($alias . '.size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(...$this->extensionPredicates($qb, self::UNREADABLE_EXTS, $alias)));
	}

	public function processableCount(string $uid): int
	{
		return $this->count($uid, fn (IQueryBuilder $qb, string $alias) => $this->processableOnly($qb, $alias));
	}

	public function emptyCount(string $uid): int
	{
		return $this->count($uid, fn (IQueryBuilder $qb, string $alias) => $this->emptyOnly($qb, $alias));
	}

	public function unreadableCount(string $uid): int
	{
		return $this->count($uid, fn (IQueryBuilder $qb, string $alias) => $this->unreadableOnly($qb, $alias));
	}

	/**
	 * The ids in the processable scope, keyed for set operations.
	 *
	 * Still an id list, and still O(files) — it is what the batch selector needs
	 * (it must hand individual files to `enqueue()`), not what the report reads.
	 *
	 * @return array<int, int>
	 */
	public function ids(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.fileid')->from('filecache', 'f');
		$this->constrain($qb, $uid);
		$this->processableOnly($qb);
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[(int) $row['fileid']] = 1;
		}
		return $out;
	}

	/**
	 * @param list<string> $extensions
	 * @return list<\OCP\DB\QueryBuilder\ICompositeExpression|string>
	 */
	private function extensionPredicates(IQueryBuilder $qb, array $extensions, string $alias): array
	{
		$out = [];
		foreach ($extensions as $ext) {
			$out[] = $qb->expr()->like($qb->func()->lower($alias . '.name'), $qb->createNamedParameter('%.' . $ext));
		}
		return $out;
	}

	/**
	 * @param callable(IQueryBuilder, string): void $scope
	 */
	private function count(string $uid, callable $scope): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'n')->from('filecache', 'f');
		$this->constrain($qb, $uid);
		$scope($qb, 'f');
		return (int) $qb->executeQuery()->fetchOne();
	}
}
