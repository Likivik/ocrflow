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
 * `oc_ocrflow_spend_events` — the spend journal: one row per paid engine run.
 *
 * Money leaves when the engine runs, not when a button is clicked, so the
 * reservation happens at claim and the row IS the run: `(job_id, attempts)` is
 * unique, the database refuses to book one run twice. `units` is an estimate
 * at reserve and the actual page count at settle; `day` is the RESERVATION's
 * day, so a run that claims at 23:59 and settles after midnight books to the
 * day that authorised it.
 *
 * States: `reserved` (the run is authorised, money not yet known) → `spent`
 * (settled with the real number) or `released` (the run died before it could
 * spend: a failed claim, a lease requeue). Only `released` gives units back.
 *
 * This migration also drops `ocrflow_spend` (0.1.62): the click-counter was a
 * stopgap for the daily gate, and two counters for one number will disagree.
 * Its rows are daily and self-resetting, so nothing is lost.
 */
class Version001016Date20261124010000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;

		if (!$schema->hasTable('ocrflow_spend_events')) {
			$table = $schema->createTable('ocrflow_spend_events');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			// 'YYYY-MM-DD' UTC, the shape gmdate('Y-m-d') writes: a string,
			// not a date type — no timezone drift between the writer and a
			// reader in another session.
			$table->addColumn('day', Types::STRING, ['length' => 10, 'notnull' => true]);
			$table->addColumn('job_id', Types::BIGINT, ['notnull' => true]);
			// The run identity: the claim already counts the attempt, so the
			// pair names exactly one run of exactly one job.
			$table->addColumn('attempts', Types::SMALLINT, ['notnull' => true]);
			// Pages: an estimate at reserve, the real count at settle.
			$table->addColumn('units', Types::INTEGER, ['notnull' => true]);
			// reserved | spent | released
			$table->addColumn('state', Types::STRING, ['length' => 12, 'notnull' => true, 'default' => 'reserved']);
			// Epoch seconds, not DATETIME: a timestamp type invites timezone
			// drift between PHP, the worker and a reader in another session.
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('settled_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->setPrimaryKey(['id']);
			// One row per run, ever: the constraint that makes double-booking
			// impossible rather than unlikely.
			$table->addUniqueIndex(['job_id', 'attempts'], 'ocrflow_spend_run');
			$table->addIndex(['uid', 'day', 'state'], 'ocrflow_spend_uidday');
			$table->addIndex(['state', 'created_at'], 'ocrflow_spend_sweep');
			$changed = true;
		}

		if ($schema->hasTable('ocrflow_spend')) {
			$schema->dropTable('ocrflow_spend');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
