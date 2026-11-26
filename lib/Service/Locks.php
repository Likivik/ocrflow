<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\LockContext;
use OCP\Files\Node;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * The one owner of every `files_lock` touch: the run lock and the reads that
 * answer "is someone else in this file?".
 *
 * Two facts shape this class (both verified on 34.0.5):
 *  - taking a lock **rewrites the file's etag** (`propagateEtag()`), and so does
 *    releasing it — the write ladder therefore compares against `run_etag`,
 *    captured after the lock, and falls back to the source hash;
 *  - a TYPE_APP lock is checked against the *lock scope*, not the user, so our
 *    own write passes inside `runInScope()` and every other writer — the
 *    owner included — is refused while the lock stands.
 *
 * The lock is a conflict-avoider, never the correctness gate: without
 * `files_lock`, or when a call fails, the run proceeds and the ladder decides.
 */
final class Locks
{
	/** The owner prefix recorded on the lock row. */
	public const OWNER = 'ocrflow';

	/** Config flag: hold the file while a run works. */
	public const FLAG = 'lock_while_running';

	public function __construct(
		private ILockManager $lockManager,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/** Is the feature on? Operator switch, off means 0.1.65 behaviour. */
	public function flagged(): bool
	{
		return $this->config->getAppValue('ocrflow', self::FLAG, 'yes') !== 'no';
	}

	/**
	 * Is a lock provider registered (i.e. is `files_lock` installed and on)?
	 * Never throws: an instance without the app must behave exactly as before.
	 */
	public function available(): bool
	{
		try {
			return $this->lockManager->isLockProviderAvailable();
		} catch (\Throwable $e) {
			$this->logger->warning('ocrflow: lock provider lookup failed: ' . $e->getMessage());

			return false;
		}
	}

	/** The node a job points at, or null when the row has no file id or the file is gone. */
	public function nodeFor(?int $fileId): ?Node
	{
		if ($fileId === null) {
			return null;
		}
		try {
			$nodes = $this->rootFolder->getById($fileId);

			return $nodes[0] ?? null;
		} catch (\Throwable $e) {
			$this->logger->info('ocrflow: no node for a lock: ' . $e->getMessage());

			return null;
		}
	}

	/**
	 * Take (or extend) the run lock.
	 *
	 * @return bool true when the run may proceed — we hold the lock, the
	 *              feature is off, or locking is unavailable/broken; false only
	 *              when someone else holds the file: a real "being edited" answer
	 */
	public function lockForRun(Node $node, string $claimToken): bool
	{
		if (!$this->flagged() || !$this->available()) {
			return true;
		}
		try {
			$this->lockManager->lock($this->context($node, $claimToken));
			$this->logger->info('ocrflow: lock_acquired', ['file_id' => $node->getId()]);

			return true;
		} catch (\OCP\Files\Lock\OwnerLockedException|\OCP\Lock\LockedException $e) {
			$this->logger->info('ocrflow: lock_skipped', ['file_id' => $node->getId()]);

			return false;
		} catch (\Throwable $e) {
			// A lock glitch must never stop the queue: proceed and let the
			// ladder protect the write.
			$this->logger->warning('ocrflow: could not lock a file for the run: ' . $e->getMessage());

			return true;
		}
	}

	/** Release the run lock. Idempotent, never throws. */
	public function releaseForRun(Node $node, string $claimToken): void
	{
		if (!$this->available()) {
			return;
		}
		try {
			$this->lockManager->unlock($this->context($node, $claimToken));
			$this->logger->info('ocrflow: lock_released', ['file_id' => $node->getId()]);
		} catch (\Throwable $e) {
			$this->logger->info('ocrflow: nothing to unlock: ' . $e->getMessage());
		}
	}

	/** Release by file id when the node cannot be resolved (file moved or gone). */
	public function releaseById(int $fileId, string $claimToken): bool
	{
		$node = $this->nodeFor($fileId);
		if ($node === null) {
			return false;
		}
		$this->releaseForRun($node, $claimToken);

		return true;
	}

	/** The etag of the file as it stands now — a re-resolved node, never a cached one. */
	public function freshEtag(string $owner, string $relative): ?string
	{
		try {
			return (string) $this->rootFolder->getUserFolder($owner)->get($relative)->getEtag();
		} catch (\Throwable $e) {
			$this->logger->info('ocrflow: no etag for ' . $owner . '/' . $relative . ': ' . $e->getMessage());

			return null;
		}
	}

	/** Run our own write inside the app's lock scope, so the wrapper lets it through. */
	public function runInScope(Node $node, string $claimToken, callable $fn): mixed
	{
		if (!$this->flagged() || !$this->available()) {
			return $fn();
		}
		$result = null;
		$this->lockManager->runInScope(
			$this->context($node, $claimToken),
			static function () use ($fn, &$result): void {
				$result = $fn();
			},
		);

		return $result;
	}

	/** A stable, non-secret owner identity for exactly one claim. */
	private function context(Node $node, string $claimToken): LockContext
	{
		return new LockContext($node, ILock::TYPE_APP, self::OWNER . ':' . hash('sha256', $claimToken));
	}
}
