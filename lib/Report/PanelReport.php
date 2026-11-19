<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Report;

use OCA\OcrFlow\Storage\DiskFiles;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * What the panel counts, from aggregates.
 *
 * The report used to load every file id the user owns, then four chunked
 * `IN (...)` readers, then loop in PHP. On a 7 000-file library that is a
 * request-shaped problem: the cost grew with the library and any list wider
 * than 1 000 expressions was a 500 — which is exactly what happened in 0.1.44.
 *
 * Every number here is a `GROUP BY` or a `COUNT`. Nothing in this class reads a
 * list of ids, except the ledger's own `UNIQUE (file_id)` guarantee that a
 * group cannot double-count a file.
 *
 * The arithmetic — and only the arithmetic — is pure and lives in `assemble()`,
 * so the part that has been wrong before is testable without a database.
 */
class PanelReport
{
	/** A file that vanished mid-run and came back: unprocessed, not an error. */
	public const GHOST_REASON = 'error_worker_file_gone';

	private const STATUS_QUEUED = 'queued';
	private const STATUS_CLAIMED = 'claimed';

	private const FILES = 'ocrflow_files';
	private const JOBS = 'ocrflow_jobs';

	public function __construct(
		private IDBConnection $db,
		private DiskFiles $disk,
	) {
	}

	/**
	 * @return array{
	 *   total: int,
	 *   never_processed: int,
	 *   empty: int,
	 *   unreadable_format: int,
	 *   queue: array{queued: int, claimed: int},
	 *   by_code: array<string, int>,
	 *   read_by_engine: list<array{engine: ?string, version: ?string, count: int}>,
	 *   no_text_by_engine: list<array{engine: ?string, version: ?string, count: int}>
	 * }
	 */
	public function forUser(string $uid): array
	{
		return self::assemble(
			$this->disk->processableCount($uid),
			$this->disk->emptyCount($uid),
			$this->disk->unreadableCount($uid),
			[
				'claimed' => $this->liveFiles($uid, self::STATUS_CLAIMED, false),
				// A claim outranks a queued row for the same file: one file, one
				// bucket. Without this the queue total could exceed the disk.
				'queued' => $this->liveFiles($uid, self::STATUS_QUEUED, true),
			],
			$this->settledGroups($uid),
		);
	}

	/**
	 * The report from counts. No database, no ids, no order-dependent maps.
	 *
	 * @param array{queued: int, claimed: int} $queue distinct files per status
	 * @param list<array{reason: ?string, engine: ?string, version: ?string, count: int}> $settled
	 * @return array<string, mixed>
	 */
	public static function assemble(int $total, int $empty, int $unreadable, array $queue, array $settled): array
	{
		$byCode = [];
		$read = [];
		$noText = [];
		$settledFiles = 0;

		foreach ($settled as $group) {
			$code = $group['reason'] ?? '';
			// The file was not there when the worker fetched it, so these bytes
			// were never read. The report is built from the disk, so a row here
			// means the file is back: unprocessed, not settled, not an error.
			if ($code === self::GHOST_REASON) {
				continue;
			}
			$settledFiles += $group['count'];
			$byCode[$code] = ($byCode[$code] ?? 0) + $group['count'];

			$engineKey = ($group['engine'] ?? '') . '|' . ($group['version'] ?? '');
			if ($code === '' || $code === 'skip_ocred_by_ocrflow') {
				$read[$engineKey] = ($read[$engineKey] ?? 0) + $group['count'];
			}
			// "No text" is named by the reader that looked, so it splits by
			// engine only: two versions of one reader are one row.
			if ($code === 'skip_ocred_by_ocrflow_found_nothing') {
				$noText[($group['engine'] ?? '') . '|'] = ($noText[($group['engine'] ?? '') . '|'] ?? 0) + $group['count'];
			}
		}

		$live = $queue['queued'] + $queue['claimed'];

		return [
			'total' => $total,
			// Never negative: a database mid-deploy can briefly describe more
			// settled files than the disk admits, and a negative count in the UI
			// is worse than a zero that corrects itself on reload.
			'never_processed' => max(0, $total - $settledFiles - $live),
			'empty' => $empty,
			'unreadable_format' => $unreadable,
			'queue' => $queue,
			'by_code' => $byCode,
			'read_by_engine' => self::engineSplit($read),
			'no_text_by_engine' => self::engineSplit($noText),
		];
	}

	/**
	 * Files with a live worker row: queued, or claimed (excluding claimed ones
	 * when counting queued, so each file lands in exactly one bucket).
	 */
	private function liveFiles(string $uid, string $status, bool $excludeClaimed): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(DISTINCT j.file_id)'), 'n')
			->from(self::JOBS, 'j')
			->innerJoin('j', 'filecache', 'f', $qb->expr()->eq('f.fileid', 'j.file_id'));
		$this->disk->constrain($qb, $uid);
		$this->disk->processableOnly($qb);
		$qb->andWhere($qb->expr()->eq('j.status', $qb->createNamedParameter($status)));

		if ($excludeClaimed) {
			$sub = $this->db->getQueryBuilder();
			$sub->select('1')
				->from(self::JOBS, 'jc')
				->where($sub->expr()->eq('jc.file_id', 'j.file_id'))
				->andWhere($sub->expr()->eq('jc.status', $sub->createNamedParameter(self::STATUS_CLAIMED)));
			$qb->andWhere($qb->expr()->not($qb->expr()->exists($sub->getSQL())));
		}

		return (int) $qb->executeQuery()->fetchOne();
	}

	/**
	 * Ledger rows, grouped by what they say — the only per-file table involved.
	 *
	 * `ocrflow_files` is `UNIQUE (file_id)`, so a group counts files, not rows.
	 * Files with a live job row are excluded here and counted in the queue
	 * instead, matching the panel's arithmetic exactly.
	 *
	 * @return list<array{reason: ?string, engine: ?string, version: ?string, count: int}>
	 */
	private function settledGroups(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.reason', 'l.engine', 'l.engine_version')
			->selectAlias($qb->func()->count('*'), 'n')
			->from(self::FILES, 'l')
			->innerJoin('l', 'filecache', 'f', $qb->expr()->eq('f.fileid', 'l.file_id'));
		$this->disk->constrain($qb, $uid);
		$this->disk->processableOnly($qb);
		$qb->groupBy('l.reason', 'l.engine', 'l.engine_version');

		$sub = $this->db->getQueryBuilder();
		$sub->select('1')
			->from(self::JOBS, 'j')
			->where($sub->expr()->eq('j.file_id', 'l.file_id'))
			->andWhere($sub->expr()->in('j.status', $sub->createNamedParameter(
				[self::STATUS_QUEUED, self::STATUS_CLAIMED],
				IQueryBuilder::PARAM_STR_ARRAY,
			)));
		$qb->andWhere($qb->expr()->not($qb->expr()->exists($sub->getSQL())));

		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[] = [
				'reason' => $row['reason'] === null ? null : (string) $row['reason'],
				'engine' => $row['engine'] === null ? null : (string) $row['engine'],
				'version' => $row['engine_version'] === null ? null : (string) $row['engine_version'],
				'count' => (int) $row['n'],
			];
		}
		return $out;
	}

	/**
	 * @param array<string, int> $counts key: engine|version
	 * @return list<array{engine: ?string, version: ?string, count: int}>
	 */
	private static function engineSplit(array $counts): array
	{
		$out = [];
		foreach ($counts as $key => $count) {
			[$engine, $version] = explode('|', $key, 2);
			$out[] = [
				'engine' => $engine === '' ? null : $engine,
				'version' => $version === '' ? null : $version,
				'count' => $count,
			];
		}
		usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
		return $out;
	}
}
