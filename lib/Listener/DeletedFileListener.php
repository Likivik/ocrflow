<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Listener;

use OCA\OcrFlow\Db\Queue;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * A deleted file is not work: drop its queued row.
 *
 * The reconciler walks the file tree, so a file that no longer exists is
 * invisible to it. Without this listener the queued row survives, a worker
 * claims it, the app answers 404 for the bytes, and the queue gains an error
 * about a file the owner deleted hours earlier — two such rows are sitting in
 * production as `error_worker_request_malformed`.
 *
 * Only queued rows are dropped. A claimed row belongs to a worker mid-fetch,
 * and that worker reports its own outcome: `FileGone`, which reads as "the
 * file was deleted or moved after it was queued", verdict drop. History
 * (done, skipped, error) stays where it is — an audit trail is not a queue.
 *
 * Failures here never propagate: this runs inside a delete request, and a
 * bookkeeping fault must not fail the user's delete.
 *
 * @template-implements IEventListener<Event>
 */
class DeletedFileListener implements IEventListener
{
	public function __construct(
		private Queue $queue,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof NodeDeletedEvent) {
			return;
		}
		$node = $event->getNode();
		if (!$node instanceof File) {
			return;
		}
		try {
			$dropped = $this->queue->deleteQueuedForFile($node->getId());
			if ($dropped > 0) {
				$this->logger->info(
					'ocrflow: dropped ' . $dropped . ' queued job(s) for deleted file ' . $node->getPath(),
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ocrflow: could not drop queued jobs for deleted file ' . $node->getPath() . ': ' . $e->getMessage(),
			);
		}
	}
}
