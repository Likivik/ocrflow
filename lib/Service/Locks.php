<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCP\Files\Lock\ILockManager;
use Psr\Log\LoggerInterface;

/**
 * Read-only view of `files_lock`: is this file being edited right now?
 *
 * Why read-only, and why not "hold a lock while the run works": taking a
 * files_lock lock **rewrites the file's etag** — `LockService::lock()` ends
 * with `propagateEtag()`, which sets `etag = uniqid()` in the file cache. The
 * write ladder compares the etag recorded at intake against the file's etag at
 * write-back, so our own lock makes every result look like a conflict and push
 * it to the stash (proven: the integration smoke turned every result-file into
 * `stashed:true` until the claim-time lock was removed). Holding a longer lock
 * therefore needs an etag baseline captured after the lock and a stash-key
 * review — a separate change.
 *
 * What is safe today is the question a claim needs answered: "is someone else
 * in this file?" — a read, no etag touched.
 *
 * Without `files_lock` every answer is "not locked": the app behaves exactly as
 * it did before.
 */
final class Locks
{
	public function __construct(
		private ILockManager $lockManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Is a lock provider registered (i.e. is `files_lock` installed and on)?
	 * Never throws: an instance without the app must behave exactly as before.
	 */
	public function available(): bool
	{
		try {
			return $this->lockManager->isLockProviderAvailable()
				&& class_exists(\OCA\FilesLock\Service\LockService::class);
		} catch (\Throwable $e) {
			$this->logger->warning('ocrflow: lock provider lookup failed: ' . $e->getMessage());

			return false;
		}
	}

	/**
	 * Is anyone holding a lock on this file right now?
	 *
	 * A read: `files_lock` exposes no public query, so this asks its service
	 * directly, guarded by `available()`. A lock of any owner counts — a user's
	 * manual lock, an Office/Text session, another app's run. This app holds no
	 * locks of its own, so there is nothing of ours to exclude.
	 */
	public function isLocked(?int $fileId): bool
	{
		if ($fileId === null || !$this->available()) {
			return false;
		}
		try {
			$lock = \OCP\Server::get(\OCA\FilesLock\Service\LockService::class)
				->getLockFromFileId($fileId);
		} catch (\OCA\FilesLock\Exceptions\LockNotFoundException $e) {
			// Not a degradation: "no lock row" IS the answer — nobody holds
			// this file right now, so the claim may proceed untouched.
			return false;
		} catch (\Throwable $e) {
			// Never let a lock lookup stop the queue: "not locked" is the
			// pre-lock behaviour, and the write ladder still protects the file.
			$this->logger->info('ocrflow: lock lookup failed, treating as unlocked: ' . $e->getMessage());

			return false;
		}

		return $lock !== null;
	}
}
