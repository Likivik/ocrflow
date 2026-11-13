<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The stash: results whose source changed while the job ran.
 *
 * The write-time ladder (etag → content hash → here) refuses to write a
 * result over a file the user changed after the job was queued. Refusing
 * silently lost the worker's work — the measured defect the
 * `skip_source_changed_result_saved` outcome exists to fix — so the bytes
 * are saved aside instead, in the app's own data directory, and the row
 * here is what the owner's Decide tab (Part B) will list.
 *
 * Two rules the table enforces with the code:
 *
 *  * `UNIQUE (file_id, etag)`: one pending artifact per file version. A
 *    repeat stash for the same version supersedes — the old artifact bytes
 *    are replaced by the new one, never kept beside it.
 *  * artifacts live in appdata, never in the user's files: the stash is
 *    the app's decision to keep something the user did not ask for, so it
 *    must not appear in the user's tree. `artifact_path` is appdata-relative.
 *
 * Retention: `pending_results_retention_days` (default 30, `0` disables).
 * The sweep runs on the existing ReconcileJob tick; an expired artifact is
 * deleted and its row is left behind as `discarded` — the audit trail of
 * what was thrown away.
 *
 * Kept free of controller concerns and (like FileExchange) a plain
 * collaborator: the worker-plane unit tests mock it without touching
 * IRootFolder. The DB seams are protected methods so tests can subclass
 * instead of standing up a database.
 */
class PendingResults
{
	public const CONFIG_KEY = 'pending_results_retention_days';

	public const RETENTION_DEFAULT_DAYS = 30;

	public const STATUS_PENDING = 'pending';

	public const STATUS_DISCARDED = 'discarded';

	public const STATUS_APPLIED = 'applied';

	private const TABLE = 'ocrflow_pending_results';

	private const FOLDER = 'pending-results';

	public function __construct(
		private ?IDBConnection $db,
		private IAppDataFactory $appDataFactory,
		private IConfig $config,
		private LoggerInterface $logger,
		private ?\OCP\Files\IRootFolder $rootFolder = null,
		private ?\OCP\App\IAppManager $appManager = null,
	) {
	}

	/**
	 * Save a conflicted result aside. The body is the worker's upload; it
	 * is consumed (copied once into a temp stream) and left positioned by
	 * the caller.
	 *
	 * @param resource $body
	 */
	public function stash(
		int $fileId,
		?string $etag,
		$body,
		?string $engine,
		?string $engineVersion,
		?int $pages,
	): void {
		$name = self::artifactName($fileId, $etag);

		// One buffer, one write: the request body is not rewindable, and a
		// php://temp stream spills to disk past 2 MiB, so a large result
		// does not live in memory.
		$buffer = fopen('php://temp', 'rb+');
		if ($buffer === false) {
			throw new \RuntimeException('cannot buffer the stashed result');
		}
		try {
			if (stream_copy_to_stream($body, $buffer) === false) {
				throw new \RuntimeException('cannot read the stashed result');
			}
			rewind($buffer);

			$folder = $this->folder();
			if ($folder->fileExists($name)) {
				// Supersede: same (file_id, etag), new bytes over the old.
				$folder->getFile($name)->putContent($buffer);
			} else {
				$folder->newFile($name, $buffer);
			}
		} finally {
			if (is_resource($buffer)) {
				fclose($buffer);
			}
		}

		$this->recordRow($fileId, $etag, $name, $engine, $engineVersion, $pages, time());
	}

	/**
	 * Does this file version have a pending (undecided) stashed result?
	 *
	 * `result()` asks this before its claim guard: the stash already closed
	 * the job terminally, so the worker's follow-up result call must be
	 * answered, not refused.
	 */
	public function hasPending(int $fileId, ?string $etag): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('1'))
			->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING)))
			->setMaxResults(1);
		$this->whereEtag($qb, $etag);
		$result = $qb->executeQuery();
		try {
			return $result->fetchOne() !== false;
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * Delete artifacts whose retention expired, leaving `discarded` rows.
	 *
	 * One ReconcileJob tick's worth: bounded, idempotent, safe to overlap
	 * with the next tick. Returns how many rows were discarded.
	 */
	public function sweepExpired(): int
	{
		$days = $this->retentionDays();
		if ($days === null) {
			// `0`: the operator asked to keep every artifact indefinitely.
			return 0;
		}
		$cutoff = time() - $days * 86400;

		$discarded = 0;
		foreach ($this->expiredRows($cutoff) as $row) {
			$this->deleteArtifact((string) $row['artifact_path']);
			$this->discardRow((int) $row['id']);
			$discarded++;
		}
		return $discarded;
	}

	/**
	 * Total bytes the pending artifacts occupy. One number, one log line
	 * per tick — the operator's way of noticing the stash growing.
	 */
	public function totalArtifactBytes(): int
	{
		try {
			$folder = $this->folder();
			$total = 0;
			foreach ($this->artifactPaths() as $path) {
				if ($folder->fileExists($path)) {
					$total += (int) $folder->getFile($path)->getSize();
				}
			}
			return $total;
		} catch (\Throwable $e) {
			$this->logger->warning('ocrflow: could not measure the pending result artifacts: ' . $e->getMessage());
			return 0;
		}
	}

	/**
	 * Configured retention in days, or null when disabled. Non-numeric or
	 * `< 1` (other than the explicit `0`) is a typo and becomes the default.
	 */
	public function retentionDays(): ?int
	{
		$raw = trim((string) $this->config->getAppValue(
			Application::APP_ID,
			self::CONFIG_KEY,
			(string) self::RETENTION_DEFAULT_DAYS,
		));
		if ($raw === '0') {
			return null;
		}
		if ($raw === '' || !ctype_digit($raw) || (int) $raw < 1) {
			return self::RETENTION_DEFAULT_DAYS;
		}
		return (int) $raw;
	}

	/**
	 * The artifact's name inside the appdata folder: deterministic in
	 * (file_id, etag), so a repeat stash overwrites instead of
	 * accumulating, and never names anything inside a user's tree.
	 */
	public static function artifactName(int $fileId, ?string $etag): string
	{
		return sprintf('pending-%d-%s.pdf', $fileId, hash('sha256', $etag ?? ''));
	}

	/**
	 * Apply a stashed result to its file — the Decide tab's Apply button.
	 *
	 * Apply OVERWRITES the current content of the file with the artifact,
	 * so the previous content must be recoverable first: if versioning is
	 * unavailable the answer is a refusal with the warning a human reads,
	 * never a silent overwrite. That is the plan's hard rule; there is no
	 * path from here that fabricates a version.
	 *
	 * @return array{status: 'applied'|'refused', warning: ?string}
	 */
	public function apply(int $id): array
	{
		$row = $this->fetchRow($id);
		if ($row === null || ($row['status'] ?? null) !== self::STATUS_PENDING) {
			return ['status' => 'refused', 'warning' => 'no pending stash with this id'];
		}

		if (!$this->versioningAvailable()) {
			$this->logger->warning('ocrflow: apply refused for stash ' . $id . ': versioning is unavailable, the previous content of the file would not be recoverable');
			return [
				'status' => 'refused',
				'warning' => 'versioning is unavailable: applying would overwrite the file with no way back to its previous content',
			];
		}

		$file = $this->nodeForFile((int) ($row['file_id'] ?? 0));
		if ($file === null) {
			return ['status' => 'refused', 'warning' => 'the file this stash belongs to is gone'];
		}

		try {
			$artifact = $this->folder()->getFile((string) $row['artifact_path']);
			$file->putContent($artifact->read());
		} catch (\Throwable $e) {
			$this->logger->warning('ocrflow: apply failed for stash ' . $id . ': ' . $e->getMessage());
			return ['status' => 'refused', 'warning' => 'the stashed bytes could not be written: ' . $e->getMessage()];
		}

		$this->markRow($id, self::STATUS_APPLIED);
		// The bytes now live in the file: the appdata copy is not a keeper.
		$this->deleteArtifact((string) $row['artifact_path']);
		$this->logger->info('ocrflow: stash ' . $id . ' applied to file_id ' . (int) ($row['file_id'] ?? 0) . '; the previous content is a version');
		return ['status' => 'applied', 'warning' => null];
	}

	/**
	 * Discard a stashed result — the Decide tab's Discard button. The same
	 * end state the retention sweep produces: bytes deleted, row kept as
	 * the audit trail. Idempotent in effect: a row that is not pending is
	 * left alone, so a double click or a stale page cannot re-decide it.
	 *
	 * @return array{status: 'discarded'|'refused', warning: ?string}
	 */
	public function discard(int $id): array
	{
		$row = $this->fetchRow($id);
		if ($row === null || ($row['status'] ?? null) !== self::STATUS_PENDING) {
			return ['status' => 'refused', 'warning' => 'no pending stash with this id'];
		}

		$this->deleteArtifact((string) $row['artifact_path']);
		$this->discardRow($id);
		return ['status' => 'discarded', 'warning' => null];
	}

	/**
	 * One row by id, with everything Apply and Discard need.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function fetchRow(int $id): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'etag', 'artifact_path', 'engine', 'engine_version', 'pages', 'created_at', 'status')
			->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		try {
			$row = $result->fetchAssociative();
			return $row === false ? null : $row;
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * Close a row with its decision. The status column is the audit trail.
	 */
	protected function markRow(int $id, string $status): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter($status))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * The file node a stash belongs to, or null when it is gone. The root
	 * folder is optional so worker-plane consumers (and tests) can omit it.
	 */
	protected function nodeForFile(int $fileId): ?\OCP\Files\File
	{
		if ($this->rootFolder === null) {
			return null;
		}
		$nodes = $this->rootFolder->getById($fileId);
		$node = $nodes[0] ?? null;
		return $node instanceof \OCP\Files\File ? $node : null;
	}

	/**
	 * Is the previous content of a file recoverable after Apply? The
	 * versions app must be enabled — without it, overwriting is data loss
	 * dressed up as a convenience, and Apply refuses instead.
	 */
	protected function versioningAvailable(): bool
	{
		return $this->appManager !== null && $this->appManager->isEnabledForUser('files_versions');
	}

	private function folder(): ISimpleFolder
	{
		$root = $this->appDataFactory->get(Application::APP_ID);
		try {
			return $root->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			// The folder is created on the first stash ever made — the
			// degraded "result" of this catch IS the correct new folder.
			return $root->newFolder(self::FOLDER);
		}
	}

	/**
	 * Insert the row; on the unique violation a repeat stash causes,
	 * supersede the existing row instead.
	 */
	protected function recordRow(
		int $fileId,
		?string $etag,
		string $artifact,
		?string $engine,
		?string $engineVersion,
		?int $pages,
		int $createdAt,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'file_id' => $qb->createNamedParameter($fileId, \PDO::PARAM_INT),
				'etag' => $qb->createNamedParameter($etag),
				'artifact_path' => $qb->createNamedParameter($artifact),
				'engine' => $qb->createNamedParameter($engine),
				'engine_version' => $qb->createNamedParameter($engineVersion),
				'pages' => $qb->createNamedParameter($pages, \PDO::PARAM_INT),
				'created_at' => $qb->createNamedParameter($createdAt, \PDO::PARAM_INT),
				'status' => $qb->createNamedParameter(self::STATUS_PENDING),
			]);
		try {
			$qb->executeStatement();
			return;
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}

		// A repeat for the same (file_id, etag): the artifact bytes were
		// already replaced above; the row is refreshed, back to pending.
		$u = $this->db->getQueryBuilder();
		$u->update(self::TABLE)
			->set('artifact_path', $u->createNamedParameter($artifact))
			->set('engine', $u->createNamedParameter($engine))
			->set('engine_version', $u->createNamedParameter($engineVersion))
			->set('pages', $u->createNamedParameter($pages, \PDO::PARAM_INT))
			->set('created_at', $u->createNamedParameter($createdAt, \PDO::PARAM_INT))
			->set('status', $u->createNamedParameter(self::STATUS_PENDING))
			->where($u->expr()->eq('file_id', $u->createNamedParameter($fileId, \PDO::PARAM_INT)));
		$this->whereEtag($u, $etag);
		$u->executeStatement();
	}

	/**
	 * The etag of a legacy row may be NULL, and `eq(null)` is not that.
	 *
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb
	 */
	private function whereEtag($qb, ?string $etag): void
	{
		if ($etag === null) {
			$qb->andWhere($qb->expr()->isNull('etag'));
		} else {
			$qb->andWhere($qb->expr()->eq('etag', $qb->createNamedParameter($etag)));
		}
	}

	/**
	 * @return list<array<string, mixed>> pending rows past the cutoff
	 */
	protected function expiredRows(int $cutoff): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'artifact_path')
			->from(self::TABLE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, \PDO::PARAM_INT)))
			->setMaxResults(500);
		$result = $qb->executeQuery();
		try {
			return $result->fetchAllAssociative();
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * @return list<string> artifact names of rows still pending
	 */
	protected function artifactPaths(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('artifact_path')
			->from(self::TABLE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING)));
		$result = $qb->executeQuery();
		try {
			$paths = [];
			foreach ($result->fetchAllAssociative() as $row) {
				$paths[] = (string) $row['artifact_path'];
			}
			return $paths;
		} finally {
			$result->closeCursor();
		}
	}

	protected function discardRow(int $id): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter(self::STATUS_DISCARDED))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	private function deleteArtifact(string $name): void
	{
		try {
			$folder = $this->folder();
			if ($folder->fileExists($name)) {
				$folder->getFile($name)->delete();
			}
		} catch (\Throwable $e) {
			// The row still becomes `discarded`: a leftover artifact file
			// is an acceptable failure, a row nobody can sweep again is not.
			$this->logger->warning('ocrflow: could not delete the expired artifact ' . $name . ': ' . $e->getMessage());
		}
	}
}
