<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Copy the engine the job rows already know into the per-file ledger.
 *
 * 405 live files have a `done` row saying `engine=google` and a ledger row with
 * no engine: the ledger learned the engine only later, and the rows written
 * before that never carried it across. The report reads the ledger now, so the
 * fact has to be there — it is already in the database, just not in the right
 * table.
 *
 * Only empty ledger engines are filled; a recorded engine is a fact and is never
 * overwritten. The 605 files whose result rows were purged before this instance
 * recorded engines stay empty on purpose: their stamps carry the legacy
 * `nc-ocr-flow` keyword, which proves "ours" and names no engine, so the honest
 * value is "not recorded" rather than a guess.
 */
class Version001012Date20261118040000 extends SimpleMigrationStep
{
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return null;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		// Portable form: `MAX(id)` per file, then join back for the columns.
		// (Postgres has DISTINCT ON, MySQL and SQLite do not.)
		$changed = $this->connection->executeStatement(
			'UPDATE `*PREFIX*ocrflow_files` SET
				`engine` = (
					SELECT j.`engine` FROM `*PREFIX*ocrflow_jobs` j
					WHERE j.`id` = (
						SELECT MAX(j2.`id`) FROM `*PREFIX*ocrflow_jobs` j2
						WHERE j2.`file_id` = `*PREFIX*ocrflow_files`.`file_id`
							AND j2.`status` = \'done\'
							AND j2.`engine` IS NOT NULL
					)
				),
				`engine_version` = (
					SELECT j.`engine_version` FROM `*PREFIX*ocrflow_jobs` j
					WHERE j.`id` = (
						SELECT MAX(j2.`id`) FROM `*PREFIX*ocrflow_jobs` j2
						WHERE j2.`file_id` = `*PREFIX*ocrflow_files`.`file_id`
							AND j2.`status` = \'done\'
							AND j2.`engine` IS NOT NULL
					)
				)
			WHERE `engine` IS NULL
				AND EXISTS (
					SELECT 1 FROM `*PREFIX*ocrflow_jobs` j3
					WHERE j3.`file_id` = `*PREFIX*ocrflow_files`.`file_id`
						AND j3.`status` = \'done\'
						AND j3.`engine` IS NOT NULL
				)',
		);

		$output->info('ocrflow: ' . $changed . ' ledger row(s) got the engine their done row already recorded');
	}
}
