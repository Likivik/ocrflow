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
 * The write-time ladder and the stash (0.1.7 → 0.1.8).
 *
 * `oc_ocrflow_jobs` gains:
 *  - `source_sha256` — the hash of the exact bytes `file()` served the
 *    worker, computed while streaming the download. The ladder's second
 *    rung compares it with the file's content at write time, so a file that
 *    only moved its etag (a metadata rewrite, or our own earlier write) is
 *    told apart from one whose bytes the user actually changed.
 *  - `written_etag` — the etag our own successful `putFile` write left
 *    behind. Re-armed after every write, it is how the ladder recognises
 *    its own handiwork instead of treating a retried upload as a conflict.
 *
 * `oc_ocrflow_pending_results` holds the stash: results whose source
 * changed while the job ran, saved aside in the app data directory instead
 * of being written over the user's newer version. `UNIQUE (file_id, etag)`
 * means one artifact per file version — a repeat stash supersedes the old
 * artifact. Expiry (ReconcileJob, `pending_results_retention_days`,
 * default 30) deletes the bytes and leaves the row behind as `discarded`.
 */
class Version001009Date20261113000000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;

		if ($schema->hasTable('ocrflow_jobs')) {
			$jobs = $schema->getTable('ocrflow_jobs');
			if (!$jobs->hasColumn('source_sha256')) {
				$jobs->addColumn('source_sha256', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);
				$changed = true;
			}
			if (!$jobs->hasColumn('written_etag')) {
				$jobs->addColumn('written_etag', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);
				$changed = true;
			}
		}

		if (!$schema->hasTable('ocrflow_pending_results')) {
			$pending = $schema->createTable('ocrflow_pending_results');
			$pending->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
				'unsigned' => true,
			]);
			$pending->addColumn('file_id', Types::BIGINT, [
				'notnull' => false,
			]);
			$pending->addColumn('etag', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$pending->addColumn('artifact_path', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$pending->addColumn('engine', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$pending->addColumn('engine_version', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$pending->addColumn('pages', Types::INTEGER, [
				'notnull' => false,
			]);
			$pending->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
				'default' => 0,
			]);
			$pending->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'pending',
			]);
			$pending->setPrimaryKey(['id']);
			$pending->addUniqueIndex(['file_id', 'etag'], 'ocrflow_pending_fileid_etag');
			$pending->addIndex(['status', 'created_at'], 'ocrflow_pending_status_created');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
