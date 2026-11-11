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
 * The stage events a worker reports must land in `ocrflow_stage_events`, and
 * only when the job update really happened.
 *
 * Three properties are worth pinning:
 *
 * 1. A foreign reason code is recorded as `unknown`, never as itself. The
 *    vocabulary is closed; a worker that invents a code must not be able to
 *    put a one-off string into a table acceptance queries group by.
 * 2. A stage name outside the vocabulary is dropped, not stored: a stage
 *    that never ran must be visibly absent.
 * 3. Rows are written only when the claim guard passed. A worker whose claim
 *    was recovered must not leave stage rows behind on a job that now belongs
 *    to somebody else.
 */
class QueueStageEventsTest extends TestCase
{
	/** @var list<array<string, mixed>> */
	private array $rows = [];

	/** @var list<string> */
	private array $sqls = [];

	/** @var list<string> */
	private array $tables = [];

	private function db(bool $claimGuardHolds = true, ?array $jobRow = null): IDBConnection
	{
		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		// The stage rows are written as one multi-row INSERT per chunk on
		// the connection (the QueryBuilder cannot express them), so the
		// connection is where they are captured: each raw INSERT is
		// unpacked back into rows keyed by column, the same shape the
		// builder-based path used to produce.
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = [], array $types = []): int {
				$this->sqls[] = $sql;
				$this->tables[] = 'ocrflow_stage_events';
				$columns = ['job_id', 'stage', 'reason_code', 'duration_ms', 'cost_pages', 'created_at'];
				$width = count($columns);
				for ($offset = 0; $offset + $width <= count($params); $offset += $width) {
					$this->rows[] = array_combine($columns, array_slice($params, $offset, $width));
				}
				return 1;
			}
		);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$calls, $jobRow, $claimGuardHolds): IQueryBuilder {
				$index = ++$calls;
				$qb = $this->createMock(IQueryBuilder::class);
				$expr = $this->createMock(IExpressionBuilder::class);
				$expr->method('eq')->willReturn('1=1');
				$qb->method('expr')->willReturn($expr);
				$qb->method('select')->willReturnSelf();
				$qb->method('from')->willReturnSelf();
				$qb->method('where')->willReturnSelf();
				$qb->method('andWhere')->willReturnSelf();
				$qb->method('setMaxResults')->willReturnSelf();
				$qb->method('update')->willReturnSelf();
				$qb->method('set')->willReturnSelf();
				// The guarded UPDATE is the second statement: the first is the
				// SELECT that loads the job.
				$qb->method('executeStatement')->willReturn(
					$index === 2 && !$claimGuardHolds ? 0 : 1
				);

				// Named parameters belong to the builder that created them, so
				// the placeholder map is per builder and resolved on `values()`.
				$params = [];
				$qb->method('createNamedParameter')->willReturnCallback(
					function ($value, $type = null) use (&$params): string {
						$name = 'p' . count($params);
						$params[$name] = $value;
						return ':' . $name;
					}
				);
				$qb->method('insert')->willReturnCallback(
					function (string $table) use ($qb): IQueryBuilder {
						$this->tables[] = $table;
						return $qb;
					}
				);
				$qb->method('values')->willReturnCallback(
					function (array $values) use ($qb, &$params): IQueryBuilder {
						$row = [];
						foreach ($values as $column => $placeholder) {
							$row[$column] = $params[ltrim((string) $placeholder, ':')] ?? null;
						}
						$this->rows[] = $row;
						return $qb;
					}
				);

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($index === 1 && $jobRow !== null ? [$jobRow] : []);
				$result->method('fetch')->willReturn(false);
				$qb->method('executeQuery')->willReturn($result);
				return $qb;
			}
		);
		return $db;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jobRow(): array
	{
		return [
			'id' => 7,
			'file_id' => null,
			'path' => '/u/files/a.pdf',
			'status' => 'claimed',
			'reason' => null,
			'reason_code' => null,
			'retryable' => null,
			'engine' => 'google',
			'claimed_by' => 'worker-1',
			'attempts' => 1,
			'size' => 100,
			'etag' => 'e1',
			'pages' => null,
			'chars' => null,
			'error' => null,
			'created_at' => 1,
			'claimed_at' => 2,
			'finished_at' => null,
		];
	}

	private function queue(IDBConnection $db, LoggerInterface $logger): Queue
	{
		return new Queue($db, new StageEvents(new RetryPolicy(), $logger));
	}

	public function testFinishPersistsStageRows(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$applied = $this->queue($this->db(true, $this->jobRow()), $logger)->finish(7, [
			'status' => 'done',
			'stage_events' => [
				['stage' => 'recognize', 'reason_code' => null, 'duration_ms' => 1234, 'cost_pages' => 3],
				['stage' => 'compose', 'reason_code' => null, 'duration_ms' => 10],
			],
		], 'worker-1');

		$this->assertTrue($applied);
		$this->assertContains('ocrflow_stage_events', $this->tables);
		$this->assertCount(2, $this->rows);

		$this->assertSame('recognize', $this->rows[0]['stage']);
		$this->assertSame(7, $this->rows[0]['job_id']);
		$this->assertSame(1234, $this->rows[0]['duration_ms']);
		$this->assertSame(3, $this->rows[0]['cost_pages']);
		$this->assertNull($this->rows[0]['reason_code']);
		$this->assertInstanceOf(\DateTimeInterface::class, $this->rows[0]['created_at']);

		// cost_pages belongs to the recognize stage alone.
		$this->assertNull($this->rows[1]['cost_pages']);
		$this->assertSame(10, $this->rows[1]['duration_ms']);
	}

	public function testFinishDowngradesAForeignReasonCodeToUnknown(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->queue($this->db(true, $this->jobRow()), $logger)->finish(7, [
			'status' => 'error',
			'stage_events' => [
				['stage' => 'recognize', 'reason_code' => 'made_up_code', 'duration_ms' => 5],
			],
		], 'worker-1');

		$this->assertCount(1, $this->rows);
		$this->assertSame(RetryPolicy::CODE_UNKNOWN, $this->rows[0]['reason_code']);
	}

	public function testAnUnknownStageNameIsDropped(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->queue($this->db(true, $this->jobRow()), $logger)->finish(7, [
			'status' => 'done',
			'stage_events' => [
				['stage' => 'not_a_stage', 'reason_code' => null, 'duration_ms' => 1],
				['stage' => 'deliver', 'reason_code' => null, 'duration_ms' => 2],
			],
		], 'worker-1');

		$this->assertCount(1, $this->rows);
		$this->assertSame('deliver', $this->rows[0]['stage']);
	}

	public function testNoStageRowsWhenTheClaimGuardFails(): void
	{
		$logger = $this->createMock(LoggerInterface::class);

		$applied = $this->queue($this->db(false, $this->jobRow()), $logger)->finish(7, [
			'status' => 'done',
			'stage_events' => [
				['stage' => 'recognize', 'reason_code' => null, 'duration_ms' => 1],
			],
		], 'worker-1');

		$this->assertFalse($applied);
		$this->assertSame([], $this->rows);
		$this->assertNotContains('ocrflow_stage_events', $this->tables);
	}
}
