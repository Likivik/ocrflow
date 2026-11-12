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
 * Drop `ocrflow_jobs.claimed_by` — the worker NAME a claim used to be guarded
 * on. It was never an identity: it came from a header, defaulted to `worker`,
 * and was truncated to 64 chars, so any holder of the shared secret could
 * finish any claimed job by sending the same name.
 *
 * The claim's identity is now the `claim_token` (minted at claim time,
 * compared in the endpoint and in `Queue::finish()`), and the claim's holder
 * is `claimed_by_worker_id` (a registry row the reconciler can ask for a
 * heartbeat). Both columns landed unused in the 0.1.5 migration specifically
 * so this one could drop the name column after its last writer was removed —
 * the removal shipped with this migration in the same release.
 */
class Version001007Date20261112050000 extends SimpleMigrationStep
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
		if (!$jobs->hasColumn('claimed_by')) {
			// Already dropped (a rerun, or an instance that skipped ahead).
			return null;
		}

		$jobs->dropColumn('claimed_by');
		return $schema;
	}
}
