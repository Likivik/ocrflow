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
 * `oc_ocrflow_spend` — the daily budget, one row per user per day.
 *
 * The counter used to live in `oc_preferences` as a string, written
 * read-modify-write from the Process button. Two clicks in two tabs read the
 * same number, both passed the cap gate, and one write clobbered the other:
 * the daily cap — the only thing between a trial budget and a stuck finger —
 * was a suggestion. A row with `used = used + n WHERE used + n <= cap` is
 * atomic in every supported database; the database does the serialising, so
 * the guard cannot be raced.
 *
 * The key is (uid, day), so "today" needs no reset job: a new day simply gets
 * a new row. Old rows are a few hundred bytes per user per year.
 */
class Version001015Date20261124000000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ocrflow_spend')) {
			return null;
		}

		$table = $schema->createTable('ocrflow_spend');
		$table->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
		// The UTC day the spending happened: 'YYYY-MM-DD', the shape
		// gmdate('Y-m-d') writes. String, not a date type — no timezone drift
		// between the writer and a reader in another session.
		$table->addColumn('day', Types::STRING, ['length' => 10, 'notnull' => true]);
		$table->addColumn('used', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['uid', 'day']);

		return $schema;
	}
}
