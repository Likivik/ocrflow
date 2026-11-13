<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\EnginePolicy;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] The settled verdict at enqueue: facts plus the CURRENT bar.
 *
 * Catches: a produced file being re-queued after its job row is purged —
 * the 8135 loop, where a scan that saw no verdict billed a second OCR of
 * bytes this app had already written. The ledger row for the same etag
 * with `reason = skip_ocred_by_ocrflow` must refuse the enqueue when the
 * engine facts clear the bar, and must NOT be rewritten when they do not:
 * the bar that judges a row can move tomorrow, so freezing a verdict into
 * the row would make today's config tomorrow's lie.
 *
 * The real-SQL versions of the same assertions run in the Nextcloud
 * integration job (tests/integration/app-smoke.sh): this harness cannot
 * run a database.
 */
class QueueSettledEnqueueTest extends TestCase
{
	/** @var list<array<string, mixed>> */
	private array $jobs = [];

	/** @var list<array<string, mixed>> */
	private array $files = [];

	private int $nextId = 1;

	private int $lastInsertId = 0;

	/**
	 * A small in-memory database over `ocrflow_jobs` and `ocrflow_files`.
	 * Same shape as the one in QueuePermanentFailureTest.
	 */
	private function builder(): IQueryBuilder
	{
		$table = '';
		$mode = 'select';
		$conditions = [];
		$values = [];
		$sets = [];
		$params = [];

		$qb = $this->createMock(IQueryBuilder::class);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, mixed $value) use (&$conditions, &$params): string {
				$conditions[$column] = $params[ltrim((string) $value, ':')] ?? null;
				return $column . ' = ?';
			}
		);
		$expr->method('in')->willReturnCallback(
			function (string $column, mixed $value) use (&$conditions, &$params): string {
				$conditions[$column] = $params[ltrim((string) $value, ':')] ?? null;
				return $column . ' IN ?';
			}
		);
		$qb->method('expr')->willReturn($expr);

		$qb->method('select')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null) use (&$params) {
				$name = 'p' . count($params);
				$params[$name] = $value;
				return ':' . $name;
			}
		);

		$qb->method('from')->willReturnCallback(
			function (string $name) use (&$table, &$qb): IQueryBuilder {
				$table = $name;
				return $qb;
			}
		);
		$qb->method('insert')->willReturnCallback(
			function (string $name) use (&$table, &$mode, &$qb): IQueryBuilder {
				$table = $name;
				$mode = 'insert';
				return $qb;
			}
		);
		$qb->method('update')->willReturnCallback(
			function (string $name) use (&$table, &$mode, &$qb): IQueryBuilder {
				$table = $name;
				$mode = 'update';
				return $qb;
			}
		);
		$qb->method('values')->willReturnCallback(
			function (array $row) use (&$values, &$params, &$qb): IQueryBuilder {
				foreach ($row as $column => $placeholder) {
					$values[$column] = $params[ltrim((string) $placeholder, ':')] ?? null;
				}
				return $qb;
			}
		);
		$qb->method('set')->willReturnCallback(
			function (string $column, mixed $placeholder) use (&$sets, &$params, &$qb): IQueryBuilder {
				$sets[$column] = $params[ltrim((string) $placeholder, ':')] ?? null;
				return $qb;
			}
		);

		$qb->method('getLastInsertId')->willReturnCallback(function (): int {
			return $this->lastInsertId;
		});

		$matchesRow = function (array $row) use (&$conditions): bool {
			foreach ($conditions as $column => $value) {
				if (!array_key_exists($column, $row)) {
					return false;
				}
				if (is_array($value)) {
					if (!in_array($row[$column], $value, true)) {
						return false;
					}
					continue;
				}
				if ((string) $row[$column] !== (string) $value) {
					return false;
				}
			}
			return true;
		};

		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$table, &$mode, &$values, &$sets, &$conditions, $matchesRow): int {
				if ($mode === 'insert') {
					$row = $values;
					if ($table === 'ocrflow_jobs') {
						$row['id'] = $this->nextId++;
						$this->jobs[] = $row;
					} elseif ($table === 'ocrflow_files') {
						$this->files[] = $row;
					}
					$this->lastInsertId = (int) ($row['id'] ?? $this->lastInsertId);
					return 1;
				}
				if ($mode === 'update' && $table === 'ocrflow_files') {
					$touched = 0;
					foreach ($this->files as $index => $row) {
						if ($matchesRow($row)) {
							$this->files[$index] = array_merge($row, $sets);
							$touched++;
						}
					}
					return $touched;
				}
				return 0;
			}
		);

		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$table, &$conditions, $matchesRow): IResult {
				$rows = match ($table) {
					'ocrflow_jobs' => $this->jobs,
					'ocrflow_files' => $this->files,
					default => [],
				};
				$matches = array_values(array_filter($rows, $matchesRow));
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($matches);
				$result->method('fetch')->willReturn($matches === [] ? false : $matches[0]);
				return $result;
			}
		);

		return $qb;
	}

	private function db(): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
		return $db;
	}

	/**
	 * The bar as config would give it: engine → minimum version.
	 */
	private function enginePolicy(string $bar): EnginePolicy
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === EnginePolicy::ACCEPTED_ENGINES_KEY ? $bar : $default,
		);
		return new EnginePolicy($config);
	}

	private function queue(IDBConnection $db, string $bar = '{"google": "3.15.0"}'): Queue
	{
		return new Queue(
			$db,
			new StageEvents(new RetryPolicy(), $this->createMock(LoggerInterface::class)),
			null,
			null,
			$this->enginePolicy($bar),
		);
	}

	/**
	 * Seed the ledger the way a write-back settle left it: skipped, our own
	 * reason, the engine facts as observed at write time.
	 */
	private function seedSettled(string $etag, string $engine, ?string $engineVersion): void
	{
		$this->files[] = [
			'id' => 1,
			'file_id' => 7,
			'path' => '/alice/files/scan.pdf',
			'status' => 'skipped',
			'reason' => 'skip_ocred_by_ocrflow',
			'engine' => $engine,
			'engine_version' => $engineVersion,
			'stamp_version' => '2',
			'size' => 123,
			'etag' => $etag,
			'updated_at' => 1,
		];
	}

	public function testASettledRowAtOrAboveTheBarIsNotEnqueued(): void
	{
		$this->seedSettled('e1', 'google', '3.15.0');

		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'google');

		$this->assertNull($id, 'bytes this app already wrote at or above the bar must not be queued again');
		$this->assertSame([], $this->jobs, 'no job row may be written for a settled file');
	}

	public function testASettledRowWithNewBytesIsEnqueued(): void
	{
		$this->seedSettled('e1', 'google', '3.15.0');

		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e2', 'google');

		$this->assertNotNull($id, 'a settled verdict is about specific bytes: new bytes earn a fresh job');
	}

	public function testASettledRowBelowTheBarIsNotEnqueuedAndNothingIsWritten(): void
	{
		$this->seedSettled('e1', 'google', '3.9.0');

		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'google');

		$this->assertNull($id, 'a redo is a candidate the owner picks from, never an automatic re-OCR');
		$this->assertSame([], $this->jobs, 'no job row: below the bar is still not queued');
		// No row rewrite: the bar that judged this row may move again, and
		// rewriting the verdict would freeze today's config into history.
		$this->assertSame('skipped', $this->files[0]['status']);
		$this->assertSame('skip_ocred_by_ocrflow', $this->files[0]['reason']);
		$this->assertSame('e1', $this->files[0]['etag']);
	}

	public function testLoweringTheBarChangesOnlyTheRefusalNotTheRow(): void
	{
		$this->seedSettled('e1', 'google', '3.15.0');
		// The same facts, judged against a bar raised past them (from the
		// panel's side: the bar was lowered below the stamp).
		$id = $this->queue($this->db(), '{"google": "9.9.9"}')->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'google');

		$this->assertNull($id, 'below the (moved) bar the file is still not re-OCR\'d automatically');
		$this->assertSame([], $this->jobs);
		$this->assertSame('skip_ocred_by_ocrflow', $this->files[0]['reason'], 'the verdict must not be frozen into the row');
		$this->assertSame('e1', $this->files[0]['etag']);
		$this->assertSame('3.15.0', $this->files[0]['engine_version'], 'the facts stay exactly as observed at write time');
	}

	public function testASettledRowFromTheLegacySpellingIsAlsoRefused(): void
	{
		// Pre-0.1.5 rows spell the code differently; the facts are the same.
		$this->files[] = [
			'id' => 1,
			'file_id' => 7,
			'path' => '/alice/files/scan.pdf',
			'status' => 'skipped',
			'reason' => 'stamped_good_by_us',
			'engine' => 'google',
			'engine_version' => '3.15.0',
			'stamp_version' => null,
			'size' => 123,
			'etag' => 'e1',
			'updated_at' => 1,
		];

		$id = $this->queue($this->db())->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'google');

		$this->assertNull($id, 'the settle check reads the code\'s spellings, not one string');
	}

	public function testASettledRowWithoutAnEnginePolicyFailsOpen(): void
	{
		// A Queue constructed without the bar (only the unit harnesses do
		// this) cannot judge a settle row, so it must not invent a verdict.
		$this->seedSettled('e1', 'google', '3.15.0');

		$id = (new Queue(
			$this->db(),
			new StageEvents(new RetryPolicy(), $this->createMock(LoggerInterface::class)),
		))->enqueue('/alice/files/scan.pdf', 7, 123, 'e1', 'google');

		$this->assertNotNull($id, 'without a bar there is no comparison, so today\'s behaviour stands');
	}

	public function testSettleProducedWritesFactsOnly(): void
	{
		$this->queue($this->db())->settleProduced(7, '/alice/files/scan.pdf', 123, 'e9', 'google', '3.15.0');

		$this->assertCount(1, $this->files);
		$this->assertSame('skipped', $this->files[0]['status']);
		$this->assertSame('skip_ocred_by_ocrflow', $this->files[0]['reason']);
		$this->assertSame('google', $this->files[0]['engine']);
		$this->assertSame('3.15.0', $this->files[0]['engine_version']);
		$this->assertSame('e9', $this->files[0]['etag']);
	}
}
