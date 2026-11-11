<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Migration;

use Closure;
use OCA\OcrFlow\AppInfo\Application;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rename the per-user key `auto_ocr_disabled` to `auto_ocr_enabled` in
 * `oc_preferences`, inverting the value exactly once.
 *
 * A setting whose default is "on" must not be named after its exception; the
 * negative name forced a negation into every reader (`!isOptedOut()`) and the
 * API payload (`auto_ocr_disabled: false` meaning "OCR is on").
 *
 * The row is UPDATED in place — new key, inverted value — so the old key
 * cannot survive the move. No old rows means no-op, which makes the migration
 * idempotent and safe to re-run.
 *
 * `down()` is not called by Nextcloud's migrator (it has no down-migrations);
 * it exists so an operator reverting the app can restore the old key inverted
 * back, instead of leaving every user's choice stranded on a key the old code
 * no longer reads.
 */
class Version001005Date20261111000000 extends SimpleMigrationStep
{
	private const OLD_KEY = 'auto_ocr_disabled';
	private const NEW_KEY = 'auto_ocr_enabled';

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$this->move(self::OLD_KEY, self::NEW_KEY);
	}

	/**
	 * Rollback path, invoked by hand (or a test): move the rows back and
	 * invert the value back into the old negative convention.
	 *
	 * @param array<string, mixed> $options
	 */
	public function down(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$this->move(self::NEW_KEY, self::OLD_KEY);
	}

	/**
	 * Rename `oc_preferences` rows for this app from `$from` to `$to`,
	 * inverting the stored '1'/'0' once. The inversion is the same rule in
	 * both directions ('0' becomes '1', everything else '0'): forward, old
	 * '0' — "not disabled" — is enabled '1'; back, new '0' — disabled — is
	 * old-style disabled '1'.
	 *
	 * @param array<string, mixed> $options unused
	 */
	private function move(string $from, string $to): void
	{
		$select = $this->connection->executeQuery(
			'SELECT `userid`, `configvalue` FROM `*PREFIX*preferences`'
			. ' WHERE `appid` = ? AND `configkey` = ?',
			[Application::APP_ID, $from],
		);

		$update = 'UPDATE `*PREFIX*preferences` SET `configkey` = ?, `configvalue` = ?'
			. ' WHERE `userid` = ? AND `appid` = ? AND `configkey` = ?';

		while (is_array($row = $select->fetch())) {
			$old = (string) ($row['configvalue'] ?? '');
			$this->connection->executeStatement($update, [
				$to,
				$old === '0' ? '1' : '0',
				(string) $row['userid'],
				Application::APP_ID,
				$from,
			]);
		}
		$select->closeCursor();
	}
}
