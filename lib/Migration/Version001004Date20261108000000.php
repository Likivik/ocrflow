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
 * Per-stage events: one row per pipeline stage per job, carrying the outcome
 * code, the wall time and the billable pages that stage spent.
 *
 * Why a table and not more columns on `ocrflow_jobs`: a job has several
 * stages and each can fail differently. The old answer was a single `error`
 * string, which forced every acceptance query into a `LIKE '%...%'` over free
 * text -- the query changes meaning whenever a message is reworded. With this
 * table the same question is `GROUP BY stage, reason_code`, and a stage that
 * never ran is visibly absent instead of silently folded into the job status.
 */
class Version001004Date20261108000000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ocrflow_stage_events')) {
			return null;
		}

		$table = $schema->createTable('ocrflow_stage_events');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('job_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('stage', Types::STRING, ['length' => 32, 'notnull' => true]);
		// NULL = the stage finished without a notable outcome (success), or no
		// code was reported -- never "failed silently".
		$table->addColumn('reason_code', Types::STRING, [
			'length' => 64,
			'notnull' => false,
			'default' => null,
		]);
		$table->addColumn('duration_ms', Types::INTEGER, [
			'notnull' => false,
			'default' => null,
		]);
		// Billable pages this stage consumed: the unit the provider charges in.
		$table->addColumn('cost_pages', Types::INTEGER, [
			'notnull' => false,
			'default' => null,
		]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['job_id'], 'ocrflow_stage_events_job_idx');
		$table->addIndex(['stage', 'reason_code'], 'ocrflow_stage_events_stage_code_idx');

		return $schema;
	}
}
