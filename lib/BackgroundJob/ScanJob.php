<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\BackgroundJob;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Candidate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Walk one folder and queue every candidate file in it.
 *
 * A queued job, not an endpoint, because a real folder is thousands of files and
 * an HTTP request that walks them all is an HTTP request that times out. The
 * caller gets a job id back and polls.
 */
class ScanJob extends QueuedJob
{
	/** Default file budget of a single scan; configurable via `scan_max_files`. */
	private const DEFAULT_MAX_FILES = 20000;

	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private Queue $queue,
		private Candidate $candidate,
		private EnginePolicy $engine,
		private LoggerInterface $logger,
		private IConfig $config,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument Array with `user` and `path`.
	 */
	protected function run($argument): void
	{
		$user = (string) ($argument['user'] ?? '');
		$path = (string) ($argument['path'] ?? '/');
		if ($user === '') {
			$this->logger->warning('ocrflow: ScanJob without a user, nothing to do');
			return;
		}

		// Walking 20 000 files to enqueue nothing is the wrong way to find out
		// that no engine has been chosen.
		$engine = $this->engine->engineForJob();
		if ($engine === null) {
			$this->logger->info('ocrflow: scan skipped: no OCR engine configured (occ ocrflow:engine)');
			return;
		}

		try {
			$folder = $this->resolveFolder($user, $path);
		} catch (NotFoundException) {
			$this->logger->warning('ocrflow: scan target no longer exists: ' . $path);
			return;
		}

		$seen = 0;
		$queued = 0;
		$max = $this->maxFiles();
		foreach ($folder->getDirectoryListing() as $node) {
			$stack = [$node];
			while ($stack !== []) {
				$current = array_pop($stack);
				if ($current instanceof Folder) {
					// The budget check sits BEFORE the listing on purpose:
					// `getDirectoryListing()` is the expensive step (a folder
					// with 100 000 children costs a 100 000-node listing and a
					// 100 000-entry stack), so it must not run once the budget
					// is spent. The old guard only sat on the loop condition.
					if ($seen >= $max) {
						$this->logger->warning(sprintf(
							'ocrflow: scan of %s truncated after %d files (limit %d): skipping %s',
							$path,
							$seen,
							$max,
							$current->getPath(),
						));
						break 2;
					}
					foreach ($current->getDirectoryListing() as $child) {
						$stack[] = $child;
					}
					continue;
				}
				if ($seen >= $max) {
					break 2;
				}
				$seen++;
				if (!$this->candidate->isProcessable($current->getName())) {
					continue;
				}
				if ($this->queue->enqueue($current->getPath(), $current->getId(), $current->getSize(), $current->getEtag(), $engine) !== null) {
					$queued++;
				}
			}
		}

		$this->logger->info(sprintf('ocrflow: scan of %s saw %d files, queued %d', $path, $seen, $queued));
	}

	/**
	 * The file budget of one scan, from config. A value that is not a positive
	 * integer is treated as unconfigured rather than passed through: a typo
	 * must not become "walk zero files" or "walk everything".
	 */
	private function maxFiles(): int
	{
		$raw = trim((string) $this->config->getAppValue(Application::APP_ID, 'scan_max_files', (string) self::DEFAULT_MAX_FILES));
		if (ctype_digit($raw) && (int) $raw > 0) {
			return (int) $raw;
		}
		return self::DEFAULT_MAX_FILES;
	}

	private function resolveFolder(string $user, string $path): Folder
	{
		$userFolder = $this->rootFolder->getUserFolder($user);
		if ($path === '/' || $path === '') {
			return $userFolder;
		}
		// `getUserFolder()` returns a LazyFolder, which proxies only the Folder
		// interface — it has `get()`, not `IRootFolder::getNode()`. Calling getNode()
		// raises TypeError on NC 34 and the whole scan dies silently: the OCS
		// endpoint still answers 200 queued:1 (measured on poweredge, 2026-10-31).
		$node = $userFolder->get(ltrim($path, '/'));
		if (!$node instanceof Folder) {
			throw new NotFoundException('not a folder: ' . $path);
		}
		return $node;
	}
}
