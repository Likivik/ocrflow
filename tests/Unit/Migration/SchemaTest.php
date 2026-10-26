<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use OCA\OcrFlow\Migration\Version001000Date20261020120000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The schema is where "do the work once" is actually enforced, so the constraint
 * is tested directly rather than inferred from a query's behaviour.
 *
 * A migration that reports success while the constraint is missing is exactly
 * the failure mode this test exists to prevent: a green run, and duplicate OCR
 * work billed to the Vision quota.
 */
class SchemaTest extends TestCase
{
    /** @var array<string, Table> */
    private array $created = [];

    private function runMigration(): ISchemaWrapper
    {
        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->method('createTable')->willReturnCallback(function (string $name): Table {
            $table = new Table($name);
            $this->created[$name] = $table;
            return $table;
        });

        $migration = new Version001000Date20261020120000($this->createMock(IDBConnection::class));
        $result = $migration->changeSchema(
            $this->createMock(IOutput::class),
            fn (): ISchemaWrapper => $schema,
            [],
        );

        $this->assertNotNull($result, 'the migration must report a schema change');
        return $result;
    }

    /**
     * @return list<Index>
     */
    private function uniqueIndexesOn(Table $table): array
    {
        return array_values(array_filter(
            $table->getIndexes(),
            static fn (Index $index): bool => $index->isUnique() && !$index->isPrimary(),
        ));
    }

    public function testJobsAreUniquePerContentVersion(): void
    {
        $this->runMigration();
        $this->assertArrayHasKey('ocrflow_jobs', $this->created);

        $unique = $this->uniqueIndexesOn($this->created['ocrflow_jobs']);
        $columns = array_map(
            static fn (Index $index): array => array_map('strtolower', $index->getColumns()),
            $unique,
        );

        $this->assertContains(
            ['file_id', 'etag'],
            $columns,
            'jobs must be unique on (file_id, etag): a rename or a move must not re-OCR, a content change must',
        );
    }

    public function testJobsAreNotUniqueOnPath(): void
    {
        $this->runMigration();
        $unique = $this->uniqueIndexesOn($this->created['ocrflow_jobs']);
        $columns = array_map(
            static fn (Index $index): array => array_map('strtolower', $index->getColumns()),
            $unique,
        );

        $this->assertNotContains(
            ['path'],
            $columns,
            'a path is a label, not identity: unique(path) re-OCRs every renamed file',
        );
    }

    public function testPathStaysIndexedForLookups(): void
    {
        $this->runMigration();
        $table = $this->created['ocrflow_jobs'];
        $indexed = array_map(
            static fn (Index $index): array => array_map('strtolower', $index->getColumns()),
            array_values($table->getIndexes()),
        );

        $this->assertContains(['path'], $indexed, 'the sidebar looks files up by path and must not table-scan');
    }

    public function testOneFileRowPerFile(): void
    {
        $this->runMigration();
        $this->assertArrayHasKey('ocrflow_files', $this->created);

        $columns = array_map(
            static fn (Index $index): array => array_map('strtolower', $index->getColumns()),
            $this->uniqueIndexesOn($this->created['ocrflow_files']),
        );

        $this->assertContains(['file_id'], $columns, 'one current state row per file');
    }

    public function testEtagIsWideEnoughForADavEtag(): void
    {
        $this->runMigration();
        $table = $this->created['ocrflow_jobs'];
        $column = $table->getColumn('etag');

        $this->assertFalse($column->getNotnull(), 'an unknown version is allowed; it simply never matches');
        $this->assertGreaterThanOrEqual(64, $column->getLength() ?? 0, 'a DAV etag is a quoted hash and must not be truncated');
    }
}
