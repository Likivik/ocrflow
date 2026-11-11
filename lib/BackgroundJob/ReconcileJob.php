<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\BackgroundJob;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Db\Queue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Upkeep for the job tables: take back claims whose worker died, and delete
 * job rows nobody needs any more.
 *
 * Retention (the sweep): `oc_ocrflow_jobs` gains one row per file version
 * and `ocrflow_stage_events` up to ~7 per job, and nothing removed them —
 * both tables grew forever. Every tick now deletes TERMINAL job rows
 * (`done`, `skipped`, `error`) older than `jobs_retention_days` (default 90)
 * together with their stage rows. `0` disables the sweep entirely — the
 * operator's "keep everything". Anything that is not a positive whole
 * number is a typo, not a policy, and becomes the default.
 *
 * Why here and not a dedicated job: this is the upkeep job — it already
 * runs every 300 s, is idempotent, and is registered in Application::boot();
 * one job row in `oc_jobs` for all table upkeep, one concern per method.
 *
 * Concurrency: `nextcloud-cron.timer` fires every 5 minutes and
 * `oc_jobs.reserved_at` is epoch zero on every row, so Nextcloud does not
 * reserve this job exclusively — a slow sweep can overlap the next tick.
 * The guard is the server's own DB-backed locking provider
 * (`OCP\Lock\ILockingProvider`, the `oc_locks` table, shared by every cron
 * process), not a hand-rolled SQL token: it already exists, it already
 * expires, and it already works on all three supported databases. The
 * `ILockFactory` app-lock API does not exist in OCP 34.
 */
class ReconcileJob extends TimedJob
{
	public const RETENTION_CONFIG_KEY = 'jobs_retention_days';

	public const RETENTION_DEFAULT_DAYS = 90;

	/** Named lock token for the retention sweep. */
	public const RETENTION_LOCK = 'ocrflow/retention';

	/**
	 * Ceiling on job rows deleted by one run: one tick must never hold row
	 * locks long enough to matter. The remainder waits for the next tick —
	 * the queue drains at up to this many rows per 5 minutes, which empties
	 * even a very large backlog within a day without a single big DELETE.
	 */
	public const RETENTION_MAX_PER_RUN = 500;

	public function __construct(
		ITimeFactory $time,
		private Queue $queue,
		private LoggerInterface $logger,
		private IConfig $config,
		private ILockingProvider $locks,
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

		$this->sweepRetention();
	}

	private function sweepRetention(): void
	{
		$days = $this->retentionDays();
		if ($days === null) {
			// `0`: retention explicitly disabled by the operator.
			return;
		}

		// Overlap guard, see the class docblock. `isLocked` then `acquire`
		// (acquire would throw on a held lock); `finally` releases even when
		// the sweep dies, or one crash would disable retention until reboot.
		if ($this->locks->isLocked(self::RETENTION_LOCK, ILockingProvider::LOCK_EXCLUSIVE)) {
			return;
		}
		$this->locks->acquireLock(self::RETENTION_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$deleted = $this->queue->purgeTerminal(
				$this->time->getTime() - $days * 86400,
				self::RETENTION_MAX_PER_RUN,
			);
		} finally {
			$this->locks->releaseLock(self::RETENTION_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}

		// One line per run, and only when it deleted something: a no-op
		// sweep every five minutes would be noise nobody reads.
		if ($deleted['jobs'] + $deleted['stage_events'] > 0) {
			$this->logger->info(sprintf(
				'ocrflow: retention deleted %d job(s), %d stage row(s) older than %d day(s)',
				$deleted['jobs'],
				$deleted['stage_events'],
				$days,
			));
		}
	}

	/**
	 * Configured retention in days, or null when disabled. Non-numeric or
	 * `< 1` (other than the explicit `0`) is a typo and becomes the default.
	 */
	private function retentionDays(): ?int
	{
		$raw = trim((string) $this->config->getAppValue(
			Application::APP_ID,
			self::RETENTION_CONFIG_KEY,
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
}
