<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Many deciders, one recorder."
 *
 * The app decides by itself that a zero-byte source needs no work. That
 * decision used to live in the job row alone, so the stage_events table -- the
 * one place a decision is supposed to be recorded with a reason code -- said
 * nothing about it. The worker never learns the file existed; nobody else
 * writes the row either. So the decision was unrecorded, and an operator
 * asking "why is this job skipped" had to read a column, not a stage.
 *
 * This pins the other half of the doctrine: every terminal decision carries
 * a reason code, in the stage_events table, exactly once.
 *
 * The fake below is a small in-memory database, not a stub that answers
 * "nothing found" to every SELECT: the dedup check only means something if
 * the SELECT can actually find the row that was written.
 */
class QueueEmptySourceTest extends TestCase
{
	/** @var list<array<string, mixed>> */
	private array $jobs = [];

	/** @var list<array<string, mixed>> */
	private array $stageRows = [];

	private int $nextId = 1;

	private int $lastInsertId = 0;

	/**
	 * A query builder over the in-memory tables.
	 *
	 * It records the table it reads and the equality conditions it was given,
	 * and answers from the arrays above.
	 */
	private function builder(): IQueryBuilder
	{
		$table = '';
		$mode = 'select';
		$conditions = [];
		$values = [];
		$sets = [];
		$params = [];

		$qb = null;
		$qb = $this->createMock(IQueryBuilder::class);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, mixed $value) use (&$conditions, &$params): string {
				$conditions[$column] = $params[ltrim((string) $value, ':')] ?? null;
				return $column . ' = ?';
			}
		);
		$qb->method('expr')->willReturn($expr);

		$qb->method('select')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null) use (&$params): string {
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

		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$table, &$mode, &$values, &$sets, &$conditions): int {
				if ($mode === 'insert') {
					$row = $values;
					if ($table === 'ocrflow_jobs') {
						$row['id'] = $this->nextId++;
						// The table's other columns are nullable and default to
						// NULL; the fake has to say so, or Job::fromRow reads
						// keys that were never written.
						$row += [
							'engine' => null,
							'claimed_by' => null,
							'pages' => null,
							'chars' => null,
							'error' => null,
							'claimed_at' => null,
							'reason' => null,
							'retryable' => null,
						];
						$this->jobs[] = $row;
					} else {
						$this->stageRows[] = $row;
					}
					$this->lastInsertId = (int) ($row['id'] ?? $this->lastInsertId);
					return 1;
				}
				if ($mode === 'update' && $table === 'ocrflow_jobs') {
					foreach ($this->jobs as $index => $job) {
						if ((int) $job['id'] === (int) ($conditions['id'] ?? -1)) {
							$this->jobs[$index] = array_merge($job, $sets);
							return 1;
						}
					}
				}
				return 0;
			}
		);

		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$table, &$conditions): IResult {
				$rows = ($table === 'ocrflow_stage_events') ? $this->stageRows : $this->jobs;
				$matches = array_values(array_filter(
					$rows,
					function (array $row) use (&$conditions): bool {
						foreach ($conditions as $column => $value) {
							if (!array_key_exists($column, $row)) {
								return false;
							}
							if ((string) $row[$column] !== (string) $value) {
								return false;
							}
						}
						return true;
					}
				));
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

	private function queue(IDBConnection $db): Queue
	{
		return new Queue($db, new StageEvents(new RetryPolicy(), $this->createMock(LoggerInterface::class)));
	}

	public function testAnEmptySourceIsRecordedAsASkippedJobWithACode(): void
	{
		$this->queue($this->db())->enqueue('/u/files/empty.pdf', null, 0, 'e1');

		$this->assertCount(1, $this->jobs);
		$this->assertSame('skipped', $this->jobs[0]['status']);
		$this->assertSame('error_source_file_zero_bytes', $this->jobs[0]['reason_code']);
		$this->assertFalse($this->jobs[0]['retryable']);
	}

	public function testAnEmptySourceAlsoWritesAnIntakeStageRow(): void
	{
		$this->queue($this->db())->enqueue('/u/files/empty.pdf', null, 0, 'e1');

		$this->assertCount(1, $this->stageRows, 'the decision must be recorded exactly once');
		$row = $this->stageRows[0];
		$this->assertSame('intake', $row['stage']);
		$this->assertSame('error_source_file_zero_bytes', $row['reason_code']);
		$this->assertSame(0, $row['duration_ms']);
		$this->assertSame(0, $row['cost_pages']);
		$this->assertSame($this->jobs[0]['id'], $row['job_id'], 'the stage row must point at the job it belongs to');
	}

	/**
	 * The invariant the whole change exists for: a terminal decision carries a
	 * reason code. A NULL code means "nobody decided", and nobody decided is
	 * indistinguishable from "the recorder forgot" -- which is the bug.
	 */
	public function testATerminalDecisionNeverCarriesANullReasonCode(): void
	{
		$this->queue($this->db())->enqueue('/u/files/empty.pdf', null, 0, 'e1');

		$this->assertNotEmpty($this->stageRows, 'the decision must be recorded at all');
		foreach ($this->stageRows as $row) {
			$this->assertNotNull($row['reason_code'], 'a terminal stage row must carry a code');
		}
	}

	/**
	 * A second enqueue of the same empty file must not double the row.
	 *
	 * The decision is the same one, so one row says it. A rescan that
	 * reaches the same verdict twice is one decision, not two.
	 */
	public function testTheSameDecisionIsNotRecordedTwice(): void
	{
		$queue = $this->queue($this->db());
		$queue->enqueue('/u/files/empty.pdf', null, 0, 'e1');
		$queue->enqueue('/u/files/empty.pdf', null, 0, 'e1');

		$this->assertCount(1, $this->stageRows);
	}
}
