<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Indexes for the retention sweep (see `Queue::purgeTerminal()` and the
 * ReconcileJob sweep): the job pass filters `status IN (terminal)` and
 * `finished_at < cutoff`, so a compound index on the pair turns a full
 * table scan into a range scan on every tick; the orphan pass filters
 * stage rows by `created_at`, which had no index at all.
 *
 * Bumped 0.1.3 → 0.1.4: a migration without a version bump never runs.
 */
class Version001005Date20261111000000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		$jobs = $schema->getTable('ocrflow_jobs');
		if (!$jobs->hasIndex('ocrflow_jobs_status_finished')) {
			$jobs->addIndex(['status', 'finished_at'], 'ocrflow_jobs_status_finished');
			$changed = true;
		}

		$stage = $schema->getTable('ocrflow_stage_events');
		if (!$stage->hasIndex('ocrflow_stage_events_created_idx')) {
			$stage->addIndex(['created_at'], 'ocrflow_stage_events_created_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
