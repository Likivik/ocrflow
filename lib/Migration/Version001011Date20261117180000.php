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
 * `reason_code` for the rows written before it existed.
 *
 * Every skip decision used to be recorded as a bare word in
 * `oc_ocrflow_jobs.reason`; `reason_code` arrived later and only new rows filled
 * it. On this instance that left 5,453 rows with a real verdict the panel could
 * not show — the biggest bucket of "Skipped" was invisible and the breakdown did
 * not add up to the total.
 *
 * The word was never lost, so nothing has to be re-decided: the same aliases
 * `RetryPolicy` already maps are applied here, once, in the database. No file is
 * re-queued, no page is sent to a provider. This is a copy, not a rescan.
 *
 * Idempotent by construction: it only touches rows where `reason_code` is NULL,
 * so a second run changes nothing.
 */
class Version001011Date20261117180000 extends SimpleMigrationStep
{
	/**
	 * The legacy words and the codes they mean — word for word the alias table
	 * `RetryPolicy` uses. A word the app has never heard of is left alone
	 * rather than guessed at.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_REASONS = [
		'born_digital_text_based' => 'skip_text_native',
		'mobilenet' => 'skip_classifier_says_photo',
		'stamped_good_by_us' => 'skip_ocred_by_ocrflow',
		'no_text_detected' => 'skip_ocred_by_ocrflow_found_nothing',
		'broken_pdf' => 'error_file_broken',
		'unsupported_ext' => 'skip_file_type_unsupported',
	];

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
		$changed = $this->connection->executeStatement(
			'UPDATE `*PREFIX*ocrflow_jobs` SET `reason_code` = CASE `reason`'
			. " WHEN 'born_digital_text_based' THEN 'skip_text_native'"
			. " WHEN 'mobilenet' THEN 'skip_classifier_says_photo'"
			. " WHEN 'stamped_good_by_us' THEN 'skip_ocred_by_ocrflow'"
			. " WHEN 'no_text_detected' THEN 'skip_ocred_by_ocrflow_found_nothing'"
			. " WHEN 'broken_pdf' THEN 'error_file_broken'"
			. " WHEN 'unsupported_ext' THEN 'skip_file_type_unsupported'"
			. ' END WHERE `reason_code` IS NULL AND `reason` IN ('
			. "'born_digital_text_based', 'mobilenet', 'stamped_good_by_us',"
			. " 'no_text_detected', 'broken_pdf', 'unsupported_ext')",
		);

		$output->info('ocrflow: ' . $changed . ' job(s) got the reason code their legacy word already carried');
	}
}
