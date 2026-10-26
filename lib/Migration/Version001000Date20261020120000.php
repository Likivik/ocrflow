<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The queue and per-file state live here, not in a worker-local SQLite file.
 *
 * Why: a container volume is invisible to Nextcloud — it is not in `occ
 * maintenance:backup`, it disappears with the container, and after a lost
 * volume every already-OCRed file looks new. In the database the same rows are
 * part of the instance, and `file_id` lets a row survive a rename or a move.
 */
class Version001000Date20261020120000 extends SimpleMigrationStep
{
	public function __construct(private IDBConnection $db)
	{
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('ocrflow_jobs')) {
			$table = $schema->createTable('ocrflow_jobs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('path', Types::STRING, ['length' => 4000, 'notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'queued']);
			$table->addColumn('reason', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$table->addColumn('engine', Types::STRING, ['length' => 16, 'notnull' => false, 'default' => null]);
			$table->addColumn('claimed_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$table->addColumn('attempts', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('etag', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$table->addColumn('pages', Types::INTEGER, ['notnull' => false, 'default' => null]);
			$table->addColumn('chars', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('error', Types::TEXT, ['notnull' => false, 'default' => null]);
			// Epoch seconds, not DATETIME: a timestamp type invites timezone
			// drift between PHP, the worker and a reader in another session.
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('claimed_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('finished_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['status'], 'ocrflow_jobs_status');
			// One job per content version, not per path: renaming or moving a
			// file must not make it look unprocessed, and a duplicate event
			// delivery must not queue it twice. The database, not a lookup,
			// enforces it. See docs/adr/0001.
			$table->addUniqueIndex(['file_id', 'etag'], 'ocrflow_jobs_fileid_etag');
			$table->addIndex(['path'], 'ocrflow_jobs_path');
			$changed = true;
		}

		if (!$schema->hasTable('ocrflow_files')) {
			$table = $schema->createTable('ocrflow_files');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('path', Types::STRING, ['length' => 4000, 'notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'unknown']);
			$table->addColumn('reason', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$table->addColumn('engine', Types::STRING, ['length' => 16, 'notnull' => false, 'default' => null]);
			$table->addColumn('stamp_version', Types::STRING, ['length' => 16, 'notnull' => false, 'default' => null]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('etag', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['file_id'], 'ocrflow_files_fileid');
			$table->addIndex(['path'], 'ocrflow_files_path');
			$changed = true;
		}

		if (!$schema->hasTable('ocrflow_settings')) {
			$table = $schema->createTable('ocrflow_settings');
			$table->addColumn('name', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('value', Types::STRING, ['length' => 512, 'notnull' => false, 'default' => null]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['name']);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
