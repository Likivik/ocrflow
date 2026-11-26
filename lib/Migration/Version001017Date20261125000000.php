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
 * Two identities the lock-aware run needs.
 *
 * `ocrflow_jobs.run_etag` is the file's etag as it stood *after* the run lock
 * was taken. `files_lock` rewrites the etag when it locks, so the intake etag
 * no longer describes the bytes the worker will read; the write ladder compares
 * against this value instead.
 *
 * `ocrflow_files.settled_sha256` is the hash of the file **as the run left it**
 * — the produced bytes for an in-place write, the untouched source for a skip
 * or a stash — and `settled_under_lock` says our own lock bump may be the
 * reason the etag moved. Together they let the enqueue check answer "already
 * processed" without trusting an etag that the lock moves on purpose.
 * `ocrflow_files.mtime` is the third cheap signal for the same question.
 */
class Version001017Date20261125000000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		$schema = $schemaClosure();
		if (!$schema->hasTable('ocrflow_jobs') || !$schema->hasTable('ocrflow_files')) {
			return null;
		}

		$jobs = $schema->getTable('ocrflow_jobs');
		if (!$jobs->hasColumn('run_etag')) {
			$jobs->addColumn('run_etag', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
		}

		$files = $schema->getTable('ocrflow_files');
		if (!$files->hasColumn('settled_sha256')) {
			$files->addColumn('settled_sha256', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
		}
		if (!$files->hasColumn('settled_under_lock')) {
			$files->addColumn('settled_under_lock', Types::BOOLEAN, ['notnull' => false, 'default' => null]);
		}
		if (!$files->hasColumn('mtime')) {
			$files->addColumn('mtime', Types::BIGINT, ['notnull' => false, 'default' => null]);
		}

		return $schema;
	}
}
