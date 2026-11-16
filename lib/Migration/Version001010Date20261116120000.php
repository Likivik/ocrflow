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
 * `oc_ocrflow_jobs.force` — the operator's "rescan anyway" (0.1.25).
 *
 * The worker has always understood a force bit: `process(..., force=True)`
 * returns before every skip decision (stamp-by-us, born-digital, foreign text
 * layer) and OCRs the file regardless. What was missing was a way to SET it:
 * the app queued jobs with no such column, so a row that had been skipped —
 * `skip_text_native`, `skip_ocred_by_suspected_foreign`, `skip_ocred_by_ocrflow`
 * — offered no action at all, and the only escape was deleting the row by hand.
 *
 * The column is the durable half of that bit: it rides the claim payload to
 * the worker, and `Queue::forceRequeue()` sets it while putting a terminal row
 * back in the queue. It is cleared when the job finishes, so a later ordinary
 * scan cannot inherit the operator's one-off decision.
 */
class Version001010Date20261116120000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocrflow_jobs')) {
			return null;
		}

		$jobs = $schema->getTable('ocrflow_jobs');
		if ($jobs->hasColumn('force')) {
			return null;
		}

		$jobs->addColumn('force', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
			'unsigned' => true,
		]);

		return $schema;
	}
}
