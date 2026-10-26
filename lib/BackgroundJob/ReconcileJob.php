<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\BackgroundJob;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Db\Queue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Take back claims whose worker died.
 *
 * A worker that is killed mid-job leaves its row `claimed` forever, and a queue
 * with one stuck row at the head is a queue that never moves. Every five minutes
 * any claim older than the stale window goes back to `queued`; the worker's own
 * attempt counter is preserved, so a file that kills the worker repeatedly
 * eventually shows up as a job with a high attempt count instead of looping
 * silently.
 */
class ReconcileJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private Queue $queue,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void
	{
		$requeued = $this->queue->requeueStale(Application::STALE_CLAIM_SECONDS);
		if ($requeued > 0) {
			$this->logger->info('ocrflow: requeued ' . $requeued . ' stale claim(s)');
		}
	}
}
