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
 * The recovery fence and the durable "desired work" row.
 *
 * `claim_revision` changes on every successful lease renewal. Recovery selects
 * a claim snapshot and may requeue or doom it only while id, token and revision
 * still match. A token alone is not enough: renewal keeps the token.
 *
 * `ocrflow_pending_files` is the latest observation that has not yet become a
 * queued job. Its row is separate from the settled ledger, so "next version"
 * never overwrites settled identity. The revision guards a slow drain from
 * deleting an observation that arrived while it was working.
 */
class Version001018Date20261127000000 extends SimpleMigrationStep
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
		if (!$jobs->hasColumn('claim_revision')) {
			$jobs->addColumn('claim_revision', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
		}

		if (!$schema->hasTable('ocrflow_pending_files')) {
			$pending = $schema->createTable('ocrflow_pending_files');
			$pending->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$pending->addColumn('path', Types::STRING, [
				'notnull' => true,
				'length' => 4000,
			]);
			$pending->addColumn('etag', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$pending->addColumn('size', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$pending->addColumn('revision', Types::BIGINT, [
				'notnull' => true,
				'default' => 1,
			]);
			$pending->addColumn('observed_at', Types::INTEGER, [
				'notnull' => true,
			]);
			$pending->setPrimaryKey(['file_id']);
		}

		return $schema;
	}
}
