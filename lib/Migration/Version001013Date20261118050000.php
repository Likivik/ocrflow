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
 * Bring the ledger's vocabulary up to the one the job rows already speak.
 *
 * 0.1.31 renamed the outcome codes in `ocrflow_jobs` (mobilenet →
 * skip_classifier_says_photo, born_digital_text_based → skip_text_native, …)
 * but the ledger rows written before that kept the old words. The report reads
 * the ledger now, so 353 files were about to be described in a vocabulary the
 * panel no longer has a block for.
 *
 * Rather than hardcode the map a second time, this copies status and reason
 * from the file's last settled job row — the same source 0.1.31 used. Only
 * rows that actually disagree are touched.
 */
class Version001013Date20261118050000 extends SimpleMigrationStep
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
		$settled = "(SELECT MAX(j2.`id`) FROM `*PREFIX*ocrflow_jobs` j2
			WHERE j2.`file_id` = `*PREFIX*ocrflow_files`.`file_id`
				AND j2.`status` IN ('done', 'skipped', 'error'))";

		$changed = $this->connection->executeStatement(
			'UPDATE `*PREFIX*ocrflow_files` SET
				`status` = (SELECT j.`status` FROM `*PREFIX*ocrflow_jobs` j WHERE j.`id` = ' . $settled . '),
				`reason` = (SELECT j.`reason_code` FROM `*PREFIX*ocrflow_jobs` j WHERE j.`id` = ' . $settled . ')
			WHERE EXISTS (SELECT 1 FROM `*PREFIX*ocrflow_jobs` j3 WHERE j3.`id` = ' . $settled . ')
				AND (
					`status` <> (SELECT j.`status` FROM `*PREFIX*ocrflow_jobs` j WHERE j.`id` = ' . $settled . ')
					OR COALESCE(`reason`, \'\') <> COALESCE(
						(SELECT j.`reason_code` FROM `*PREFIX*ocrflow_jobs` j WHERE j.`id` = ' . $settled . '), \'\'
					)
				)',
		);

		$output->info('ocrflow: ' . $changed . ' ledger row(s) took the vocabulary of their last settled job row');
	}
}
