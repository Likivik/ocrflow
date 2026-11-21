<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Storage;

use OCA\OcrFlow\Service\RetryPolicy;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The per-file ledger: one row per file, the file's verdict.
 *
 * `ocrflow_jobs` is a run log — queued, claimed, settled, purged by retention.
 * `ocrflow_files` is the file: it outlives the run that wrote it, and it is
 * what the report reads. The two were one class for as long as the report lived
 * in the queue; they are two subjects and now two classes.
 *
 * Nothing here writes a job row, and nothing here decides whether a job runs —
 * the verdict it returns is an input to that decision, not the decision.
 */
class Ledger
{
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_ERROR = 'error';

	private const FILES = 'ocrflow_files';

	/**
	 * The audit readers below (`ledgerDrift`, `provenanceCounts`,
	 * `skippedByReason`, `errorReasons`) compare the ledger against the run log,
	 * so they name the other table. They read it; they never write it.
	 */
	private const JOBS = 'ocrflow_jobs';
	public const STATUS_DONE = 'done';

	/** Ids per `IN (`. Nextcloud refuses more than 1 000 on every driver. */
	private const IN_CHUNK = 900;

	public function __construct(
		private IDBConnection $db,
		private RetryPolicy $retryPolicy,
	) {
	}

	/**
	 * The settled facts recorded in the ledger for THIS etag, or null.
	 *
	 * "Settled" is the third verdict class: these bytes were already written
	 * by us (`reason = skip_ocred_by_ocrflow`, spelled either era), so the
	 * question is not "can they ever succeed" (permanent) but "does the
	 * engine that wrote them clear today's bar". The row carries only facts;
	 * {@see EnginePolicy::stampVerdict()} derives the verdict at enqueue.
	 *
	 * @return array{engine: ?string, engine_version: ?string}|null
	 * @throws Exception
	 */
	public function settledForVersion(int $fileId, ?string $etag): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('engine', 'engine_version')->from(self::FILES)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('reason', $qb->createNamedParameter(
				$this->retryPolicy->spellingsOf('skip_ocred_by_ocrflow'),
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($this->etagPredicate($qb, $etag))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		return [
			'engine' => isset($row['engine']) && is_string($row['engine']) && $row['engine'] !== '' ? $row['engine'] : null,
			'engine_version' => isset($row['engine_version']) && is_string($row['engine_version']) && $row['engine_version'] !== '' ? $row['engine_version'] : null,
		];
	}

	/**
	 * The permanent reason recorded in the ledger for THIS etag, or null.
	 *
	 * This is the half of the dedupe that survives retention: the job row is
	 * history and gets purged, the per-file ledger is the verdict. The row
	 * only blocks when its status is a terminal failure AND its reason is in
	 * the permanent set AND the etag still names the failed bytes — anything
	 * else (a retryable code, an unknown code, a missing row, new bytes)
	 * fails open toward retrying.
	 *
	 * @throws Exception
	 */
	public function permanentFailureForVersion(int $fileId, ?string $etag): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('reason')->from(self::FILES)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[self::STATUS_ERROR, self::STATUS_SKIPPED],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($this->etagPredicate($qb, $etag))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		$reason = $row['reason'] ?? null;
		return is_string($reason) && $this->retryPolicy->isPermanent($reason) ? $reason : null;
	}

	/**
	 * The ledger row for a path, or null when this app has never seen it.
	 *
	 * @return array{file_id: int, path: string, status: string, reason: ?string, etag: ?string}|null
	 * @throws Exception
	 */
	public function findFileByPath(string $path): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'path', 'status', 'reason', 'etag')->from(self::FILES)
			->where($qb->expr()->eq('path', $qb->createNamedParameter($path)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		return [
			'file_id' => (int) $row['file_id'],
			'path' => (string) $row['path'],
			'status' => (string) $row['status'],
			'reason' => isset($row['reason']) && is_string($row['reason']) ? $row['reason'] : null,
			'etag' => isset($row['etag']) && is_string($row['etag']) ? $row['etag'] : null,
		];
	}

	/**
	 * The operator override (`occ ocrflow:requeue`): forget the permanent
	 * verdict for one file. The ledger keeps its status history; only the
	 * reason is cleared, which is the only column the block reads.
	 *
	 * @throws Exception
	 */
	public function clearPermanentVerdict(int $fileId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::FILES)
			->set('reason', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * The ledger rows for these files: one row per file, never purged.
	 *
	 * This is what replaced reading the newest job row. A job row is a run; the
	 * ledger is the file. Reading runs meant a file read once and skipped once
	 * could be described by the wrong one of the two — which is exactly how 405
	 * files came to be labelled "engine not recorded".
	 *
	 * @param list<int> $fileIds
	 * @return array<int, array{status: mixed, reason: mixed, engine: mixed, engine_version: mixed}>
	 */
	protected function ledgerRowsFor(array $fileIds): array
	{
		$out = [];
		foreach (array_chunk($fileIds, self::IN_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id', 'status', 'reason', 'engine', 'engine_version')
				->from(self::FILES)
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($qb->executeQuery()->fetchAll() as $row) {
				$out[(int) $row['file_id']] = [
					'status' => $row['status'],
					'reason' => $row['reason'],
					'engine' => $row['engine'],
					'engine_version' => $row['engine_version'],
				];
			}
		}
		return $out;
	}

	/**
	 * Ledger rows that disagree with the file's last settled job row.
	 *
	 * The report reads the ledger, so the ledger must be right. Every settle
	 * path is supposed to write it; this finds the ones that did not. Read-only
	 * by construction — it is an audit, not a repair.
	 *
	 * @return array{total: int, rows: list<array{file_id: int, ledger_status: string, ledger_reason: ?string, job_status: string, job_reason: ?string}>}
	 */
	public function ledgerDrift(int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id')
			->selectAlias($qb->func()->max('id'), 'mid')
			->from(self::JOBS)
			->where($qb->expr()->in('status', $qb->createNamedParameter(
				[self::STATUS_DONE, self::STATUS_SKIPPED, self::STATUS_ERROR],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->groupBy('file_id');
		$mids = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$mids[(int) $row['mid']] = (int) $row['file_id'];
		}
		if ($mids === []) {
			return ['total' => 0, 'rows' => []];
		}

		$jobs = [];
		$qbJobs = $this->db->getQueryBuilder();
		$qbJobs->select('id', 'status', 'reason_code')
			->from(self::JOBS)
			->where($qbJobs->expr()->in('id', $qbJobs->createNamedParameter(array_keys($mids), IQueryBuilder::PARAM_INT_ARRAY)));
		foreach ($qbJobs->executeQuery()->fetchAll() as $row) {
			$jobs[$mids[(int) $row['id']]] = [
				'status' => (string) $row['status'],
				'reason' => $row['reason_code'] === null ? null : (string) $row['reason_code'],
			];
		}

		$ledger = [];
		$qbLedger = $this->db->getQueryBuilder();
		$qbLedger->select('file_id', 'status', 'reason')
			->from(self::FILES)
			->where($qbLedger->expr()->in('file_id', $qbLedger->createNamedParameter(array_keys($jobs), IQueryBuilder::PARAM_INT_ARRAY)));
		foreach ($qbLedger->executeQuery()->fetchAll() as $row) {
			$ledger[(int) $row['file_id']] = [
				'status' => (string) $row['status'],
				'reason' => $row['reason'] === null ? null : (string) $row['reason'],
			];
		}

		// `limit` caps what is printed, never what is examined — a truncated
		// audit that reports "no drift" because it looked at 500 files is worse
		// than no audit at all.
		$total = 0;
		$out = [];
		foreach ($jobs as $fileId => $job) {
			$row = $ledger[$fileId] ?? null;
			if ($row !== null && $row['status'] === $job['status'] && $row['reason'] === $job['reason']) {
				continue;
			}
			$total++;
			if (count($out) >= $limit) {
				continue;
			}
			$out[] = [
				'file_id' => $fileId,
				'ledger_status' => $row === null ? '(no ledger row)' : $row['status'],
				'ledger_reason' => $row === null ? null : $row['reason'],
				'job_status' => $job['status'],
				'job_reason' => $job['reason'],
			];
		}
		return ['total' => $total, 'rows' => $out];
	}

	/**
	 * What read these files, and with which engine version.
	 *
	 * The per-file ledger keeps provenance (`engine`, `engine_version`) and is
	 * never purged, so this is the durable answer to "is my archive read, and
	 * by whom" — the question a person actually has. Job rows are runs; the
	 * ledger is the state of the file.
	 *
	 * @return list<array{engine: ?string, version: ?string, count: int}>
	 */
	public function provenanceCounts(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('engine', 'engine_version')
			->selectAlias($qb->func()->count('*'), 'n')
			->from(self::FILES)
			->groupBy('engine')
			->groupBy('engine_version')
			->orderBy('n', 'DESC');
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[] = [
				'engine' => isset($row['engine']) && $row['engine'] !== '' ? (string) $row['engine'] : null,
				'version' => isset($row['engine_version']) && $row['engine_version'] !== '' ? (string) $row['engine_version'] : null,
				'count' => (int) $row['n'],
			];
		}
		return $out;
	}

	/**
	 * Skipped rows by reason code, biggest first.
	 *
	 * One "Skipped" number hides six different decisions: a photo, a born-digital
	 * PDF, our own stamp, a foreign tesseract layer, an unsupported type. They
	 * mean opposite things to the person reading the panel, so the count is split
	 * where the decision was made — on `reason_code`, which the worker already
	 * records.
	 *
	 * @return array<string, int> code => count, biggest first
	 */
	public function skippedByReason(): array
	{
		return $this->reasonsForStatus(self::STATUS_SKIPPED);
	}

	/**
	 * Error rows by reason code, biggest first — the mirror of the skip split,
	 * so the three blocks the panel shows (read / not read / needs you) each
	 * answer with the sentence the app already writes for the code.
	 *
	 * @return array<string, int>
	 */
	public function errorReasons(): array
	{
		return $this->reasonsForStatus(self::STATUS_ERROR);
	}

	/**
	 * @return array<string, int>
	 */
	private function reasonsForStatus(string $status): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('reason_code')
			->selectAlias($qb->func()->count('*'), 'n')
			->from(self::JOBS)
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->groupBy('reason_code');
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			// An empty code is a real bucket, not noise: the pre-worker rows
			// were skipped before reason codes existed, and dropping them here
			// would make the parts stop adding up to the total.
			$out[(string) ($row['reason_code'] ?? '')] = (int) $row['n'];
		}
		arsort($out);
		return $out;
	}

	/**
	 * `etag = ?` or `etag IS NULL`. An unknown version never matches a known one,
	 * in either direction: "we do not know which version this is" is not "this
	 * version is already done".
	 */
	private function etagPredicate(IQueryBuilder $qb, ?string $etag): string
	{
		return $etag === null
			? $qb->expr()->isNull('etag')
			: $qb->expr()->eq('etag', $qb->createNamedParameter($etag));
	}
}
