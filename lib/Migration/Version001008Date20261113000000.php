<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The loop-breaker counter (0.1.6 → 0.1.7).
 *
 * `oc_ocrflow_jobs` is `UNIQUE (file_id, etag)` and retention purges its
 * terminal rows, so the jobs table cannot count how many times a scan has
 * queued one file version. The count must live in the never-purged ledger:
 * `times_enqueued` is incremented only on scan-initiated enqueues, and a
 * second scan-initiated enqueue for identical bytes is refused with
 * `error_queued_twice_for_same_bytes` (a needs_fix, operator-requeueable —
 * `Attention::retryFile()` resets this counter).
 *
 * Default 0, so an existing row that has already been queued before the
 * upgrade is NOT pre-poisoned: the breaker starts counting from now.
 */
class Version001008Date20261113000000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocrflow_files')) {
			return null;
		}

		$files = $schema->getTable('ocrflow_files');
		if ($files->hasColumn('times_enqueued')) {
			// Already there (a rerun, or an instance that skipped ahead).
			return null;
		}

		$files->addColumn('times_enqueued', Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
			'unsigned' => true,
		]);
		return $schema;
	}
}
