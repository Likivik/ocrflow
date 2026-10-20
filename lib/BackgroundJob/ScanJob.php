<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\BackgroundJob;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Candidate;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
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
	private const MAX_FILES = 20000;

	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private Queue $queue,
		private Candidate $candidate,
		private LoggerInterface $logger,
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

		try {
			$folder = $this->resolveFolder($user, $path);
		} catch (NotFoundException) {
			$this->logger->warning('ocrflow: scan target no longer exists: ' . $path);
			return;
		}

		$seen = 0;
		$queued = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			$stack = [$node];
			while ($stack !== [] && $seen < self::MAX_FILES) {
				$current = array_pop($stack);
				if ($current instanceof Folder) {
					foreach ($current->getDirectoryListing() as $child) {
						$stack[] = $child;
					}
					continue;
				}
				$seen++;
				if (!$this->candidate->isProcessable($current->getName())) {
					continue;
				}
				if ($this->queue->enqueue($current->getPath(), $current->getId(), $current->getSize(), $current->getEtag()) !== null) {
					$queued++;
				}
			}
		}

		$this->logger->info(sprintf('ocrflow: scan of %s saw %d files, queued %d', $path, $seen, $queued));
	}

	private function resolveFolder(string $user, string $path): Folder
	{
		$userFolder = $this->rootFolder->getUserFolder($user);
		if ($path === '/' || $path === '') {
			return $userFolder;
		}
		$node = $userFolder->getNode(ltrim($path, '/'));
		if (!$node instanceof Folder) {
			throw new NotFoundException('not a folder: ' . $path);
		}
		return $node;
	}
}
