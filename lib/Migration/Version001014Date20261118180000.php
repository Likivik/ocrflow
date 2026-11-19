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
 * A claim becomes a lease.
 *
 * `claimed_at` could only ever answer "when was this taken". Recovery keyed off
 * the holder's heartbeat, which answers "is that process alive" — not "does it
 * still hold this job". Three live rows proved the difference: the worker
 * finished the OCR, the app answered 502 during a deploy, the claim was never
 * reported, and because the worker kept beating, `requeueStale()` saw a live
 * owner forever. Those rows could never move again.
 *
 * `claim_expires_at` is renewed by the holder while it works. Recovery reads it,
 * so a claim nobody renews returns to the queue on its own — dead process,
 * orphaned claim or 502 alike.
 */
class Version001014Date20261118180000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		$schema = $schemaClosure();
		if (!$schema->hasTable('ocrflow_jobs')) {
			return null;
		}
		$jobs = $schema->getTable('ocrflow_jobs');
		if (!$jobs->hasColumn('claim_expires_at')) {
			// NULL on every existing row: a pre-lease claim. Recovery treats
			// NULL as "expired", because the holder never promised to renew.
			$jobs->addColumn('claim_expires_at', Types::INTEGER, [
				'notnull' => false,
				'default' => null,
			]);
		}

		return $schema;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$output->info('ocrflow: claims are leases now; existing claims have no lease and will be recovered');
	}
}
