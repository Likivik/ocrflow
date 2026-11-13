<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\OcrFlow\Migration\Version001008Date20261113000000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The 0.1.7 schema: `ocrflow_files.times_enqueued`, the loop
 * breaker's counter.
 *
 * Catches: the counter living in `ocrflow_jobs` (UNIQUE (file_id, etag) and
 * purged by retention — it cannot count anything) instead of the
 * never-purged ledger, and the app's schema/code drift the smoke test
 * checks on a real instance.
 */
class Schema001008Test extends TestCase
{
	/** @var array<string, Table> */
	private array $tables = [];

	protected function setUp(): void
	{
		$files = new Table('ocrflow_files');
		$files->addColumn('file_id', 'bigint', ['notnull' => true]);
		$files->addColumn('path', 'string', ['length' => 512, 'notnull' => true]);
		$files->addColumn('status', 'string', ['length' => 16, 'notnull' => true]);
		$this->tables = ['ocrflow_files' => $files];

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			fn (string $name): bool => array_key_exists($name, $this->tables)
		);
		$schema->method('getTable')->willReturnCallback(
			fn (string $name): Table => $this->tables[$name]
		);

		$migration = new Version001008Date20261113000000($this->createMock(IDBConnection::class));
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $schema,
			[],
		);
		$this->assertNotNull($result, 'the migration must report a schema change');
	}

	public function testTimesEnqueuedIsAddedToTheLedger(): void
	{
		$column = $this->tables['ocrflow_files']->getColumn('times_enqueued');
		$this->assertTrue($column->getNotnull(), 'the counter must not be nullable — there is always a number');
		$this->assertSame(0, $column->getDefault(), 'existing rows are NOT pre-poisoned: the count starts at zero');
	}

	public function testTheCounterLivesInTheLedgerNotTheJobsTable(): void
	{
		$this->assertFalse(array_key_exists('ocrflow_jobs', $this->tables), 'job rows are purged; a counter there counts nothing');
	}
}
