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
	private const STATUS_DONE = 'done';

	private const FILES = 'ocrflow_files';

	/** Ids per `IN (`. Nextcloud refuses more than 1 000 on every driver. */
	private const IN_CHUNK = 900;
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
			// A claim outranks a queued row for the same file: one file, one
			// bucket. Without this a re-queued file could be counted twice.
			[
				'claimed' => count($claimedIds = $this->liveFileIds($uid, self::STATUS_CLAIMED)),
				'queued' => count($queuedIds = array_values(array_diff($this->liveFileIds($uid, self::STATUS_QUEUED), $claimedIds))),
			],
			$this->settledGroups($uid, array_merge($claimedIds, $queuedIds)),
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
	 * The files with a live worker row, one status at a time.
	 *
	 * Live rows are the queue's depth, not the library's size: a few hundred at
	 * most, usually zero. Asking for ids keeps the set arithmetic in PHP and the
	 * SQL free of `NOT EXISTS` — which Nextcloud's expression builder does not
	 * have, a fact that cost a 500 on every panel load in 0.1.46. `claimed` and
	 * `queued` are made disjoint in `forUser()`, so one file is never counted
	 * twice.
	 *
	 * @return list<int>
	 */
	private function liveFileIds(string $uid, string $status): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('j.file_id')
			->from(self::JOBS, 'j')
			->innerJoin('j', 'filecache', 'f', $qb->expr()->eq('f.fileid', 'j.file_id'));
		$this->disk->constrain($qb, $uid);
		$this->disk->processableOnly($qb);
		$qb->andWhere($qb->expr()->eq('j.status', $qb->createNamedParameter($status)));

		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[] = (int) $row['file_id'];
		}
		return $out;
	}

	/**
	 * Ledger rows, grouped by what they say — the only per-file table involved.
	 *
	 * `ocrflow_files` is `UNIQUE (file_id)`, so a group counts files, not rows.
	 * Files with a live job row are excluded here (they are counted in the queue
	 * instead); the exclusion list is chunked so a deep queue cannot produce an
	 * over-wide `IN (`, and group counts add up across chunks because the keys
	 * are identical.
	 *
	 * @param list<int> $liveIds
	 * @return list<array{reason: ?string, engine: ?string, version: ?string, count: int}>
	 */
	private function settledGroups(string $uid, array $liveIds): array
	{
		$groups = [];
		$chunks = $liveIds === [] ? [[]] : array_chunk($liveIds, self::IN_CHUNK);

		foreach ($chunks as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('l.reason', 'l.engine', 'l.engine_version')
				->selectAlias($qb->func()->count('*'), 'n')
				->from(self::FILES, 'l')
				->innerJoin('l', 'filecache', 'f', $qb->expr()->eq('f.fileid', 'l.file_id'));
			$this->disk->constrain($qb, $uid);
			$this->disk->processableOnly($qb);
			if ($chunk !== []) {
				$qb->andWhere($qb->expr()->notIn('l.file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			}
			$qb->groupBy('l.reason', 'l.engine', 'l.engine_version');

			foreach ($qb->executeQuery()->fetchAll() as $row) {
				$key = ($row['reason'] ?? '') . "\x1f" . ($row['engine'] ?? '') . "\x1f" . ($row['engine_version'] ?? '');
				$groups[$key] ??= [
					'reason' => $row['reason'] === null ? null : (string) $row['reason'],
					'engine' => $row['engine'] === null ? null : (string) $row['engine'],
					'version' => $row['engine_version'] === null ? null : (string) $row['engine_version'],
					'count' => 0,
				];
				$groups[$key]['count'] += (int) $row['n'];
			}
		}

		return array_values($groups);
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
		usort($out, static function (array $a, array $b): int {
			// Count first, then the name: a tie broken by row order is a
			// difference that shows up on one database and not another.
			return ($b['count'] <=> $a['count'])
				?: ((string) ($a['engine'] ?? '') <=> (string) ($b['engine'] ?? ''))
				?: ((string) ($a['version'] ?? '') <=> (string) ($b['version'] ?? ''));
		});
		return $out;
	}

	public function pageStatsFor(string $uid, int $days = 30): array
	{
		$prefix = $uid . '/files/%';

		$qb = $this->db->getQueryBuilder();
		// No AVG in Nextcloud's function builder, and no reason to add one:
		// SUM over COUNT is the same number and both are portable.
		$qb->selectAlias($qb->func()->count('*'), 'n')
			->selectAlias($qb->func()->count('pages'), 'n_pages')
			->selectAlias($qb->func()->sum('pages'), 'sum_pages')
			->from(self::JOBS)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_DONE)))
			->andWhere($qb->expr()->isNotNull('pages'))
			->andWhere($qb->expr()->like('path', $qb->createNamedParameter($prefix)))
			->andWhere($qb->expr()->gt('finished_at', $qb->createNamedParameter(time() - $days * 86400, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		$samples = (int) ($row['n'] ?? 0);
		$paged = (int) ($row['n_pages'] ?? 0);
		$avg = $paged > 0 ? (float) $row['sum_pages'] / $paged : null;

		$qb2 = $this->db->getQueryBuilder();
		$qb2->selectAlias($qb2->func()->count('*'), 'n')
			->from(self::JOBS)
			->where($qb2->expr()->eq('status', $qb2->createNamedParameter(self::STATUS_DONE)))
			->andWhere($qb2->expr()->like('path', $qb2->createNamedParameter($prefix)))
			->andWhere($qb2->expr()->gt('finished_at', $qb2->createNamedParameter(time() - 3600, IQueryBuilder::PARAM_INT)));
		$recent = (int) ($qb2->executeQuery()->fetchOne() ?? 0);

		return [
			'avg_pages' => $samples >= 20 && $avg !== null ? round((float) $avg, 1) : null,
			'samples' => $samples,
			'per_minute' => $recent >= 3 ? round($recent / 60, 1) : null,
		];
	}
}
