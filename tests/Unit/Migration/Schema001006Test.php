<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\OcrFlow\Migration\Version001006Date20261112000000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The 0.1.5 schema: the engine bar needs `engine_version` on the ledger, the
 * worker registry and the claim columns are the ground PR 3 builds on (added
 * now, unused now — a column nobody writes is safer than a rename in the PR
 * that starts using it), and `claimed_by` must survive this PR untouched.
 */
class Schema001006Test extends TestCase
{
	/** @var array<string, Table> */
	private array $tables = [];

	/**
	 * The ledger as 0.1.4 leaves it: the columns the (status, updated_at)
	 * index is built on already exist, so the migration only adds to them.
	 */
	private static function filesTable(): Table
	{
		$table = new Table('ocrflow_files');
		$table->addColumn('status', 'string', ['length' => 16, 'notnull' => true, 'default' => 'unknown']);
		$table->addColumn('updated_at', 'integer', ['notnull' => true]);
		$table->addColumn('engine', 'string', ['length' => 16, 'notnull' => false, 'default' => null]);
		return $table;
	}

	protected function setUp(): void
	{
		// The tables the 0.1.5 migration alters already exist.
		$this->tables = [
			'ocrflow_files' => self::filesTable(),
			'ocrflow_jobs' => new Table('ocrflow_jobs'),
		];
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			fn (string $name): bool => array_key_exists($name, $this->tables),
		);
		$schema->method('getTable')->willReturnCallback(
			fn (string $name): Table => $this->tables[$name],
		);
		$schema->method('createTable')->willReturnCallback(function (string $name): Table {
			$table = new Table($name);
			$this->tables[$name] = $table;
			return $table;
		});

		$migration = new Version001006Date20261112000000($this->createMock(IDBConnection::class));
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $schema,
			[],
		);
		$this->assertNotNull($result, 'the migration must report a schema change');
	}

	public function testTheLedgerRecordsTheEngineVersion(): void
	{
		$column = $this->tables['ocrflow_files']->getColumn('engine_version');
		$this->assertFalse($column->getNotnull(), 'a stamp without a version is allowed');
		$this->assertGreaterThanOrEqual(16, $column->getLength() ?? 0, 'wide enough for a semver');
	}

	public function testTheWorkerRegistryExists(): void
	{
		$this->assertArrayHasKey('ocrflow_workers', $this->tables);
		$workers = $this->tables['ocrflow_workers'];
		foreach (['id', 'name', 'version', 'started_at', 'last_seen_at'] as $column) {
			$this->assertTrue($workers->hasColumn($column), $column . ' is missing from ocrflow_workers');
		}
		$this->assertSame(['id'], $workers->getPrimaryKey()?->getColumns() ?? [], 'the registry is keyed by its own id');
		$this->assertTrue($workers->getColumn('name')->getNotnull());
		$this->assertTrue($workers->getColumn('started_at')->getNotnull());
		$this->assertTrue($workers->getColumn('last_seen_at')->getNotnull());
	}

	public function testTheClaimColumnsArriveWithoutTouchingClaimedBy(): void
	{
		$jobs = $this->tables['ocrflow_jobs'];
		$this->assertTrue($jobs->hasColumn('claim_token'));
		$this->assertGreaterThanOrEqual(64, $jobs->getColumn('claim_token')->getLength() ?? 0);
		$this->assertFalse($jobs->getColumn('claim_token')->getNotnull(), 'old rows have no token');
		$this->assertTrue($jobs->hasColumn('claimed_by_worker_id'));
		$this->assertFalse($jobs->getColumn('claimed_by_worker_id')->getNotnull(), 'unused until PR 3');

		// `claimed_by` is dropped in PR 3, not here: this migration must be
		// deployable while the code that still writes `claimed_by` is live.
		$this->assertFalse($jobs->hasColumn('claimed_by'), 'PR 1 must not drop claimed_by');
	}

	public function testTheNeedsFixListingHasItsIndex(): void
	{
		$indexed = array_map(
			static fn ($index): array => array_map('strtolower', $index->getColumns()),
			array_values($this->tables['ocrflow_files']->getIndexes()),
		);
		$this->assertContains(['status', 'updated_at'], $indexed, 'the worklist lists by (status, updated_at)');
	}
}
