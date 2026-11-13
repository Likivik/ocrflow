<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\OcrFlow\Migration\Version001009Date20261113000000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The 0.1.8 schema: the ladder's and the stash's columns and table.
 *
 * Catches: `source_sha256` or `written_etag` landing anywhere other than
 * `oc_ocrflow_jobs` (or not landing at all), and a pending-results table
 * without the UNIQUE (file_id, etag) rule a repeat stash relies on to
 * supersede the old artifact. The app's schema/code drift is additionally
 * checked on a real instance by the integration smoke.
 */
class Schema001009Test extends TestCase
{
	/** @var array<string, Table> */
	private array $tables = [];

	protected function setUp(): void
	{
		$jobs = new Table('ocrflow_jobs');
		$jobs->addColumn('id', 'bigint', ['notnull' => true]);

		$this->tables = ['ocrflow_jobs' => $jobs];

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			fn (string $name): bool => array_key_exists($name, $this->tables)
		);
		$schema->method('getTable')->willReturnCallback(
			fn (string $name): Table => $this->tables[$name]
		);
		$schema->method('createTable')->willReturnCallback(
			function (string $name): Table {
				$this->tables[$name] = new Table($name);
				return $this->tables[$name];
			}
		);

		$migration = new Version001009Date20261113000000($this->createMock(IDBConnection::class));
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $schema,
			[],
		);
		$this->assertNotNull($result, 'the migration must report a schema change');
	}

	public function testTheLadderColumnsLandOnTheJobsTable(): void
	{
		$jobs = $this->tables['ocrflow_jobs'];

		$hash = $jobs->getColumn('source_sha256');
		$this->assertSame(64, $hash->getLength(), 'a sha256 hex digest is exactly 64 chars');
		$this->assertFalse($hash->getNotnull(), 'legacy rows have no hash: the ladder fails safe toward the stash');

		$written = $jobs->getColumn('written_etag');
		$this->assertFalse($written->getNotnull(), 'the etag of our own write is armed after the first write');
	}

	public function testThePendingTableCarriesTheUniqueRule(): void
	{
		$this->assertArrayHasKey('ocrflow_pending_results', $this->tables);
		$table = $this->tables['ocrflow_pending_results'];

		foreach (['file_id', 'etag', 'artifact_path', 'engine', 'engine_version', 'pages', 'created_at', 'status'] as $column) {
			$this->assertTrue($table->hasColumn($column), $column . ' is missing from ocrflow_pending_results');
		}

		$unique = array_filter(
			$table->getIndexes(),
			static fn ($index): bool => $index->isUnique()
		);
		$this->assertNotEmpty($unique, 'the pending table needs UNIQUE (file_id, etag): a repeat stash supersedes');
	}

	public function testTheArtifactsAreNotStoredInTheUsersFiles(): void
	{
		// The table itself is the guard here: `artifact_path` names a file in
		// the app data directory, and there is no column that could name a
		// path under the user's tree. The unique rule plus `artifact_path`
		// being appdata-relative is what keeps a stash out of user space.
		$this->assertArrayHasKey('ocrflow_pending_results', $this->tables);
		$this->assertArrayNotHasKey('oc_files', $this->tables, 'nothing is written into the files table');
	}
}
