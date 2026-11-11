<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Migration;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Migration\Version001005Date20261111000000;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The `auto_ocr_disabled` → `auto_ocr_enabled` rename is a data migration, not
 * a schema one: the rows already exist and their meaning inverts exactly once,
 * here — never at read time (a read-time inversion re-inverts on every deploy
 * and eventually disagrees with itself).
 *
 * The cases that matter:
 * - both polarities invert correctly ('1' → '0', '0' → '1');
 * - a database with no old rows is a no-op, so the migration is idempotent and
 *   re-runnable;
 * - `down()` puts the old key back, inverted back, so a reverted app reads the
 *   users' choice instead of silently re-enabling OCR for everybody.
 */
class PreferencesMigrationTest extends TestCase
{
	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $statements = [];

	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $queries = [];

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function migration(array $rows): Version001005Date20261111000000
	{
		$queue = $rows;
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(
			static function () use (&$queue): mixed {
				$row = array_shift($queue);
				return $row ?? false;
			},
		);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($result): IResult {
				$this->queries[] = [$sql, $params];
				return $result;
			},
		);
		$connection->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->statements[] = [$sql, $params];
				return 1;
			},
		);

		return new Version001005Date20261111000000($connection);
	}

	private function runStep(Version001005Date20261111000000 $migration, string $method): void
	{
		$migration->{$method}(
			$this->createMock(IOutput::class),
			static fn (): null => null,
			[],
		);
	}

	public function testBothPolaritiesInvertExactlyOnce(): void
	{
		$migration = $this->migration([
			['userid' => 'alice', 'configvalue' => '1'],
			['userid' => 'bob', 'configvalue' => '0'],
		]);

		$this->runStep($migration, 'postSchemaChange');

		$written = [];
		foreach ($this->statements as [$sql, $params]) {
			$this->assertStringContainsString('preferences', $sql, 'the migration only touches oc_preferences');
			$written[$params[2]] = $params;
		}

		$this->assertSame('0', $written['alice'][1], 'disabled (old 1) must become enabled=0');
		$this->assertSame('1', $written['bob'][1], 'enabled (old 0) must become enabled=1');
		foreach ($written as $params) {
			$this->assertSame('auto_ocr_enabled', $params[0], 'the row must move to the new key');
		}
	}

	public function testTheOldKeyIsGoneAfterTheMove(): void
	{
		$migration = $this->migration([['userid' => 'alice', 'configvalue' => '1']]);

		$this->runStep($migration, 'postSchemaChange');

		$this->assertCount(1, $this->statements, 'one row in, one update out');
		[$sql, $params] = $this->statements[0];
		// The UPDATE renames the row in place: it sets the new key and its
		// WHERE still matches the old key, so the old row cannot survive it.
		$this->assertStringContainsStringIgnoringCase('update', $sql);
		$this->assertStringContainsString('`configkey` = ?', $sql);
		$this->assertSame('auto_ocr_disabled', $params[4], 'the WHERE must pin the old key');
		$this->assertSame('auto_ocr_enabled', $params[0]);
	}

	public function testNoOldRowsIsANoOp(): void
	{
		$migration = $this->migration([]);

		$this->runStep($migration, 'postSchemaChange');

		$this->assertSame([], $this->statements, 'nothing to move, nothing to write: idempotent');

		// The select must filter on the old key only, or a re-run after a
		// partial failure would re-invert already-migrated rows.
		$this->assertCount(1, $this->queries);
		[$sql, $params] = $this->queries[0];
		$this->assertStringContainsString('preferences', $sql);
		$this->assertSame(Application::APP_ID, $params[0]);
		$this->assertSame('auto_ocr_disabled', $params[1]);
	}

	public function testDownRestoresTheOldKeyInvertedBack(): void
	{
		$migration = $this->migration([['userid' => 'alice', 'configvalue' => '0']]);

		$this->runStep($migration, 'down');

		$this->assertCount(1, $this->statements);
		[$sql, $params] = $this->statements[0];
		$this->assertSame('auto_ocr_disabled', $params[0], 'the row moves back to the old key');
		$this->assertSame('1', $params[1], 'enabled=0 must become old-style disabled=1');
		$this->assertSame('auto_ocr_enabled', $params[4], 'the WHERE must pin the new key');
	}
}
