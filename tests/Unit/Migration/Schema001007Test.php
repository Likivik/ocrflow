<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\OcrFlow\Migration\Version001007Date20261112050000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The 0.1.6 schema: `claimed_by` — the worker NAME a claim used to be guarded
 * on — is dropped now that the claim token and `claimed_by_worker_id` are the
 * claim's identity. The code that wrote it is gone in the same PR; the column
 * kept alive by PR 1 for exactly this moment.
 */
class Schema001007Test extends TestCase
{
	/** @var array<string, Table> */
	private array $tables = [];

	protected function setUp(): void
	{
		$jobs = new Table('ocrflow_jobs');
		$jobs->addColumn('claimed_by', 'string', ['length' => 64, 'notnull' => false, 'default' => null]);
		$jobs->addColumn('claim_token', 'string', ['length' => 64, 'notnull' => false, 'default' => null]);
		$jobs->addColumn('claimed_by_worker_id', 'bigint', ['notnull' => false, 'default' => null]);
		$this->tables = ['ocrflow_jobs' => $jobs];

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			fn (string $name): bool => array_key_exists($name, $this->tables)
		);
		$schema->method('getTable')->willReturnCallback(
			fn (string $name): Table => $this->tables[$name]
		);

		$migration = new Version001007Date20261112050000($this->createMock(IDBConnection::class));
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $schema,
			[],
		);
		$this->assertNotNull($result, 'the migration must report a schema change');
	}

	public function testClaimedByIsDropped(): void
	{
		$this->assertFalse($this->tables['ocrflow_jobs']->hasColumn('claimed_by'), 'the name column is gone');
	}

	public function testTheTokenColumnsSurvive(): void
	{
		$jobs = $this->tables['ocrflow_jobs'];
		$this->assertTrue($jobs->hasColumn('claim_token'));
		$this->assertTrue($jobs->hasColumn('claimed_by_worker_id'));
	}
}
