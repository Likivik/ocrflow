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
 * Machine-readable outcomes: `reason_code` (closed vocabulary) and `retryable`
 * (the app's decision, computed from that vocabulary).
 *
 * Why a new column and not the existing `reason`: `reason` is a skip reason the
 * app has written since the first release and its values are free text from the
 * worker's point of view ("born_digital_text_based", "monthly_budget_reached",
 * "mobilenet", "stamped_good_by_us"). Retry policy cannot be keyed on free text
 * whose next value nobody has enumerated. `reason_code` is a closed set the app
 * owns (see OCA\OcrFlow\Service\RetryPolicy) and the worker may only report
 * values from it; anything else is recorded as `unknown` and logged.
 *
 * Both columns are nullable: every row that predates this migration keeps NULL,
 * and a NULL code means "no policy decision recorded", never "not retryable".
 */
class Version001002Date20261107000000 extends SimpleMigrationStep
{
	/**
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocrflow_jobs')) {
			// Nothing to upgrade; a fresh install runs this step right after
			// Version001000 created the table, so the columns are added below.
			return null;
		}

		$table = $schema->getTable('ocrflow_jobs');
		$changed = false;

		if (!$table->hasColumn('reason_code')) {
			$table->addColumn('reason_code', Types::STRING, [
				'length' => 64,
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}
		if (!$table->hasColumn('retryable')) {
			// The app's decision, not the worker's hint. NULL = no decision
			// recorded (rows from before this migration, and successful jobs).
			$table->addColumn('retryable', Types::BOOLEAN, [
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}
		if (!$table->hasIndex('ocrflow_jobs_reason_code_idx')) {
			$table->addIndex(['reason_code'], 'ocrflow_jobs_reason_code_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
