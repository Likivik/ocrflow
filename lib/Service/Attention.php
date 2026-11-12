<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Db\Queue;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * The operator's worklist: every job row whose reason code carries the
 * `needs_fix` verdict.
 *
 * A needs_fix is a deployment or worker problem a human must resolve (the
 * worker secret was rejected, the engine credentials are missing, the file
 * is broken); retrying the same bytes cannot fix it, so the queue will not
 * retry it on its own. The row therefore IS the work — it must be listed
 * (`GET /attention`, `occ ocrflow:errors`, the admin panel), kept (retention
 * does not purge it) and, once the human has fixed the cause, re-queued from
 * ONE retry implementation with two front doors: the `occ ocrflow:requeue`
 * command and `POST /attention/retry`.
 *
 * One data source on purpose: the endpoint, the command and the panel all
 * read `list()`, so they cannot disagree about whose work it is.
 */
class Attention
{
	/** Config key for the per-click cap on "retry all". */
	public const RETRY_CAP_KEY = 'attention_retry_cap';

	/** Default cap: one click must not queue an unbounded paid batch. */
	public const DEFAULT_RETRY_CAP = 500;

	/** The prose column is truncated to this before it leaves the app. */
	private const ERROR_TRUNCATE = 500;

	public function __construct(
		private Queue $queue,
		private RetryPolicy $retryPolicy,
		private IConfig $config,
		private ?LoggerInterface $logger = null,
		private ?IDBConnection $db = null,
	) {
	}

	/**
	 * The worklist, newest first.
	 *
	 * @return array{rows: list<array{id: int, path: string, code: string, label: string, fix: string, error: ?string, attempts: int, finished_at: ?int}>, total: int, cap: int}
	 */
	public function list(int $limit, int $offset = 0, ?string $code = null): array
	{
		$spellings = $this->spellings($code);
		$total = $this->fetchTotal($spellings);
		if ($offset >= $total || $limit <= 0) {
			return ['rows' => [], 'total' => $total, 'cap' => $this->cap()];
		}
		$rows = [];
		foreach ($this->fetchRows($limit, $offset, $spellings) as $row) {
			$rows[] = $this->toApi($row);
		}
		return ['rows' => $rows, 'total' => $total, 'cap' => $this->cap()];
	}

	/**
	 * The needs_fix codes the panel's filter offers: canonical code → label.
	 *
	 * @return array<string, string>
	 */
	public function codes(): array
	{
		$out = [];
		foreach ($this->retryPolicy->codesForVerdict(RetryPolicy::VERDICT_NEEDS_FIX) as $code) {
			$entry = $this->retryPolicy->entry($code);
			if ($entry !== null) {
				$out[$code] = $entry['label'];
			}
		}
		return $out;
	}

	/**
	 * Retry ONE worklist row by job id. False when there is nothing to do —
	 * including when the row was already retried (the retry deletes the
	 * terminal row it acts on, so a second call finds nothing: idempotent
	 * by construction, not by flag).
	 *
	 * A job whose code is not needs_fix is refused: the worklist must not
	 * become a general-purpose "requeue anything" button.
	 */
	public function retryById(int $id): bool
	{
		$job = $this->queue->getById($id);
		if ($job === null) {
			return false;
		}
		$code = $this->rowCode($job->getReasonCode(), $job->getReason());
		if ($this->retryPolicy->verdict($code) !== RetryPolicy::VERDICT_NEEDS_FIX) {
			$this->logger?->warning('ocrflow: attention: job ' . $id . ' was asked for a retry but its code is not needs_fix (' . (string) $code . '); refused');
			return false;
		}
		$fileId = $job->getFileId();
		if ($fileId === null) {
			$this->logger?->warning('ocrflow: attention: job ' . $id . ' has no file to retry; skipped');
			return false;
		}
		$this->retryFile($fileId, $job->getPath(), 'attention');
		return true;
	}

	/**
	 * Retry the whole worklist, capped. Returns how many rows were retried;
	 * a worklist larger than the cap keeps its remainder for the next click.
	 */
	public function retryAll(): int
	{
		$cap = $this->cap();
		$ids = $this->fetchIds($cap, $this->spellings(null));
		$retried = 0;
		foreach ($ids as $id) {
			if ($this->retryById((int) $id)) {
				$retried++;
			}
		}
		if ($retried === 0 && $ids !== []) {
			$this->logger?->warning('ocrflow: attention: retry-all selected ' . count($ids) . ' row(s) but retried none');
		}
		return $retried;
	}

	/**
	 * The "retry all" cap, from config `attention_retry_cap` (default 500).
	 */
	public function cap(): int
	{
		$cap = (int) $this->config->getAppValue(Application::APP_ID, self::RETRY_CAP_KEY, (string) self::DEFAULT_RETRY_CAP);
		return $cap > 0 ? $cap : self::DEFAULT_RETRY_CAP;
	}

	/**
	 * The `occ ocrflow:requeue` door. Same retry implementation the
	 * endpoint uses; only the lookup differs (path vs job id).
	 *
	 * @return array{status: 'missing'|'done'|'cleared', reason: ?string, deleted: int}
	 */
	public function retryPath(string $path): array
	{
		$file = $this->queue->findFileByPath($path);
		if ($file === null) {
			return ['status' => 'missing', 'reason' => null, 'deleted' => 0];
		}
		if ($file['status'] === Queue::STATUS_DONE) {
			return ['status' => 'done', 'reason' => $file['reason'], 'deleted' => 0];
		}
		$deleted = $this->retryFile((int) $file['file_id'], $file['path'], 'requeue');
		return ['status' => 'cleared', 'reason' => $file['reason'], 'deleted' => $deleted];
	}

	/**
	 * THE retry implementation: clear the permanent verdict and delete the
	 * file's terminal job rows, so the next scan queues the file again as a
	 * fresh attempt. One log line per retry, whichever door it came from.
	 */
	private function retryFile(int $fileId, string $path, string $door): int
	{
		$deleted = $this->queue->deleteTerminalForFile($fileId);
		$this->queue->clearPermanentVerdict($fileId);
		$this->logger?->info('ocrflow: ' . $door . ': cleared the failure verdict for ' . $path . ' (file_id ' . $fileId . '); deleted ' . $deleted . ' terminal job row(s); the next scan will queue it again');
		return $deleted;
	}

	/**
	 * The spellings to select on: every needs_fix name and alias, or the
	 * spellings of ONE code when the caller filters. An unknown filter code
	 * selects nothing — it cannot match any row by definition.
	 *
	 * @return list<string>
	 */
	private function spellings(?string $code): array
	{
		if ($code === null || $code === '') {
			return $this->retryPolicy->spellingsForVerdict(RetryPolicy::VERDICT_NEEDS_FIX);
		}
		return $this->retryPolicy->spellingsOf($code);
	}

	/**
	 * The code of a row: reason_code when the row has one, reason when it
	 * predates the column (old rows stored the code there).
	 */
	private function rowCode(?string $reasonCode, ?string $reason): ?string
	{
		if ($reasonCode !== null && $reasonCode !== '') {
			return $reasonCode;
		}
		return $reason;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: int, path: string, code: string, label: string, fix: string, error: ?string, attempts: int, finished_at: ?int}
	 */
	private function toApi(array $row): array
	{
		$code = $this->rowCode(
			isset($row['reason_code']) && is_string($row['reason_code']) ? $row['reason_code'] : null,
			isset($row['reason']) && is_string($row['reason']) ? $row['reason'] : null,
		);
		$entry = $this->retryPolicy->entry($code);
		$error = isset($row['error']) && is_string($row['error']) && $row['error'] !== '' ? $row['error'] : null;
		return [
			'id' => (int) $row['id'],
			'path' => (string) $row['path'],
			'code' => $this->retryPolicy->canonical($code),
			'label' => $entry['label'] ?? 'unknown',
			'fix' => $entry['fix'] ?? 'unknown',
			// Prose can be a full exception trace; nobody needs 50 KB of it
			// in a table row.
			'error' => $error === null ? null : (mb_strlen($error) > self::ERROR_TRUNCATE
				? mb_substr($error, 0, self::ERROR_TRUNCATE) . '…'
				: $error),
			'attempts' => (int) ($row['attempts'] ?? 0),
			'finished_at' => isset($row['finished_at']) && $row['finished_at'] !== null ? (int) $row['finished_at'] : null,
		];
	}

	/**
	 * The worklist predicate: reason_code carries a needs_fix spelling, or
	 * (pre-0.1.5 shape) reason_code is empty and reason carries one.
	 *
	 * @param list<string> $spellings
	 */
	private function predicate(IQueryBuilder $qb, array $spellings): string
	{
		return $qb->expr()->orX(
			$qb->expr()->in('reason_code', $qb->createNamedParameter($spellings, IQueryBuilder::PARAM_STR_ARRAY)),
			$qb->expr()->andX(
				$qb->expr()->orX(
					$qb->expr()->isNull('reason_code'),
					$qb->expr()->eq('reason_code', $qb->createNamedParameter('')),
				),
				$qb->expr()->in('reason', $qb->createNamedParameter($spellings, IQueryBuilder::PARAM_STR_ARRAY)),
			),
		);
	}

	/**
	 * @param list<string> $spellings
	 * @return list<array<string, mixed>>
	 */
	protected function fetchRows(int $limit, int $offset, array $spellings): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'path', 'reason_code', 'reason', 'error', 'attempts', 'finished_at')
			->from('ocrflow_jobs')
			->where($this->predicate($qb, $spellings))
			->orderBy('finished_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setFirstResult($offset)
			->setMaxResults($limit);
		return $qb->executeQuery()->fetchAll();
	}

	/**
	 * @param list<string> $spellings
	 */
	protected function fetchTotal(array $spellings): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'total')
			->from('ocrflow_jobs')
			->where($this->predicate($qb, $spellings));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? 0 : (int) ($row['total'] ?? 0);
	}

	/**
	 * @param list<string> $spellings
	 * @return list<int>
	 */
	protected function fetchIds(int $limit, array $spellings): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('ocrflow_jobs')
			->where($this->predicate($qb, $spellings))
			->orderBy('finished_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		return array_map(intval(...), array_column($qb->executeQuery()->fetchAll(), 'id'));
	}
}
