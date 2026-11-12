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
 * The outcomes-vocabulary schema (0.1.4 → 0.1.5).
 *
 * - `ocrflow_files.engine_version` — what the stamp's engine version was, so
 *   the engine bar can decide nothing_to_do vs redo per file instead of
 *   freezing 1,489 tesseract-era files forever.
 * - `ocrflow_workers` — the worker registry claim integrity (PR 3) builds
 *   on. Added now, unused now: a column nobody writes is safer than a
 *   rename in the PR that starts using it.
 * - `ocrflow_jobs.claim_token` + `claimed_by_worker_id` — same: the ground
 *   for PR 3's claim tokens. `claimed_by` is deliberately NOT dropped here —
 *   the code that still writes it is live until PR 3 lands.
 * - index `ocrflow_files(status, updated_at)` — the needs_fix worklist lists
 *   by that pair; one line in a migration we are already writing.
 *
 * A migration without a version bump never runs (lesson 1): info.xml is
 * bumped 0.1.4 → 0.1.5 in this PR.
 */
class Version001006Date20261112000000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		$files = $schema->getTable('ocrflow_files');
		if (!$files->hasColumn('engine_version')) {
			$files->addColumn('engine_version', Types::STRING, [
				'length' => 16,
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}
		if (!$files->hasIndex('ocrflow_files_status_updated')) {
			$files->addIndex(['status', 'updated_at'], 'ocrflow_files_status_updated');
			$changed = true;
		}

		$jobs = $schema->getTable('ocrflow_jobs');
		if (!$jobs->hasColumn('claim_token')) {
			$jobs->addColumn('claim_token', Types::STRING, [
				'length' => 64,
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}
		if (!$jobs->hasColumn('claimed_by_worker_id')) {
			$jobs->addColumn('claimed_by_worker_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}
		// `claimed_by` stays: PR 3 drops it, and only after the code that
		// writes it is gone.

		if (!$schema->hasTable('ocrflow_workers')) {
			$workers = $schema->createTable('ocrflow_workers');
			$workers->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			// The name a worker registers under; uniqueness is not enforced
			// here because two workers may legitimately share a name and
			// must not block each other's heartbeat.
			$workers->addColumn('name', Types::STRING, ['length' => 64, 'notnull' => true]);
			$workers->addColumn('version', Types::STRING, [
				'length' => 32,
				'notnull' => false,
				'default' => null,
			]);
			$workers->addColumn('started_at', Types::DATETIME, ['notnull' => true]);
			$workers->addColumn('last_seen_at', Types::DATETIME, ['notnull' => true]);
			$workers->setPrimaryKey(['id']);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
