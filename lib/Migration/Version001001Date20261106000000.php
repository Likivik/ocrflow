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
 * The `engine` column, for instances that installed before the engine became
 * instance policy.
 *
 * The column was first added by *editing* Version001000 — a migration this app
 * had already shipped, and which an installed instance has therefore already
 * run and recorded. Editing it changed nothing for those instances: their
 * `ocrflow_jobs` and `ocrflow_files` tables have no `engine` column, and the
 * first upload after the upgrade dies with an SQL error about an unknown
 * column. Fresh installs never saw the problem, which is exactly how the
 * integration smoke missed it.
 *
 * This step adds the column when the table exists without it, so an upgrade
 * lands in the same state as a fresh install.
 */
class Version001001Date20261106000000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		foreach (['ocrflow_jobs', 'ocrflow_files'] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				// Nothing to upgrade: a fresh database gets the full schema,
				// column included, from Version001000.
				continue;
			}
			$table = $schema->getTable($tableName);
			if ($table->hasColumn('engine')) {
				continue;
			}
			$table->addColumn('engine', Types::STRING, [
				'length' => 16,
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
