<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\AppInfo;

use OCA\OcrFlow\BackgroundJob\ReconcileJob;
use OCA\OcrFlow\Listener\FileListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'ocrflow';

	/** Header carrying the worker's shared secret on the queue endpoints. */
	public const WORKER_SECRET_HEADER = 'X-OcrFlow-Secret';

	/** Header carrying the worker's self-reported name (informational). */
	public const WORKER_HEADER = 'X-OcrFlow-Worker';

	/**
	 * Header carrying the claim token: minted by `claim()`, required by
	 * `file` / `result` / `failure` (and the byte endpoints). A request
	 * without the matching token is a stale claim reference, answered 409.
	 */
	public const CLAIM_HEADER = 'X-OcrFlow-Claim';

	/** Header carrying the registry row id a worker got at registration. */
	public const WORKER_ID_HEADER = 'X-OcrFlow-Worker-Id';

	/**
	 * How stale a worker's heartbeat may be before its claims are taken back
	 * and its registry row pruned. The worker heartbeats every
	 * `NC_OCR_HEARTBEAT_SECONDS` (default 60); this budget is ~3x that, so
	 * one lost beat does not kill a claim, and a dead worker is noticed
	 * within minutes instead of the old blind 900-second window.
	 */
	public const HEARTBEAT_STALE_SECONDS = 180;

	public function __construct(array $urlParams = [])
	{
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void
	{
		// Uploads are the primary trigger. The listener only inserts a row: the
		// expensive work happens in the worker, so a slow OCR never delays the
		// request that uploaded the file.
		$context->registerEventListener(NodeCreatedEvent::class, FileListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, FileListener::class);
	}

	public function boot(IBootContext $context): void
	{
		// The reconciler has to exist even if no event ever reaches us, because
		// Nextcloud drops failed event deliveries without retrying them.
		//
		// `IJobList::has()` takes the argument as a second parameter in NC 34+;
		// calling it with the class alone raises an ArgumentCountError, which
		// surfaces only as "Could not boot ocrflow" in nextcloud.log while the
		// app still serves requests — a silent half-boot found by the
		// integration smoke, not by any unit test.
		$context->injectFn(static function (IJobList $jobs): void {
			if (!$jobs->has(ReconcileJob::class, null)) {
				$jobs->add(ReconcileJob::class, null);
			}
		});
	}
}
