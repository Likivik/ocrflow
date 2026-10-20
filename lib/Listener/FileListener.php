<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Listener;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Candidate;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Queues a freshly uploaded file, and does nothing else.
 *
 * Everything expensive — download, classification, rasterising, OCR — belongs to
 * the worker. A Nextcloud event listener runs inside the uploading request; a
 * five-minute OCR there would be a five-minute upload.
 *
 * @template-implements IEventListener<Event>
 */
class FileListener implements IEventListener
{
	public function __construct(
		private Queue $queue,
		private Candidate $candidate,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof NodeCreatedEvent && !$event instanceof NodeWrittenEvent) {
			return;
		}
		$node = $event->getNode();
		if (!$node instanceof Node || !$node->isFile()) {
			return;
		}
		if (!$this->candidate->isProcessable($node->getName())) {
			return;
		}

		try {
			$this->queue->enqueue(
				$node->getPath(),
				$node->getId(),
				$node->getSize(),
				$node->getEtag(),
			);
		} catch (\Throwable $e) {
			// Never break the upload. The reconciler will find the file again
			// because the queue, not this call, is the record of truth.
			$this->logger->warning('ocrflow: could not queue ' . $node->getPath(), ['exception' => $e]);
		}
	}
}
