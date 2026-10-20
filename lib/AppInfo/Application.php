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

	/** How long a claim may sit untouched before the reconciler takes it back. */
	public const STALE_CLAIM_SECONDS = 900;

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
		$context->injectFn(static function (IJobList $jobs): void {
			if (!$jobs->has(ReconcileJob::class)) {
				$jobs->add(ReconcileJob::class, null);
			}
		});
	}
}
