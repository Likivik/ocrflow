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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Bounded retention for the job tables: a terminal `ocrflow_jobs` row older
 * than the cutoff goes, its `ocrflow_stage_events` rows go with it, and the
 * `ocrflow_files` ledger is never touched.
 *
 * The row-selection semantics (which statuses, which cutoff, which cap) are
 * what this test pins, and they need real rows to bite against — not a mock
 * that merely replays the query we just wrote. So the test subclasses Queue
 * and overrides the four SQL seam methods with an in-memory store that
 * applies the SAME predicates the production SQL applies; the production SQL
 * itself (WHERE shape, params, never the files table) is pinned separately
 * by the builder-mock tests at the bottom of this class.
 */
class QueueRetentionTest extends TestCase
{
	private const NOW = 1700000000;

	private StoreQueue $queue;

	protected function setUp(): void
	{
		$this->queue = new StoreQueue($this->createMock(IDBConnection::class));
	}

	/**
	 * @param int $ageSeconds seconds before self::NOW the row finished
	 */
	private function job(int $id, string $status, ?int $ageSeconds): void
	{
		$this->queue->jobs[$id] = [
			'id' => $id,
			'status' => $status,
			'finished_at' => $ageSeconds === null ? null : self::NOW - $ageSeconds,
		];
	}

	private function stage(int $id, int $jobId, int $ageSeconds): void
	{
		$this->queue->stage[$id] = [
			'id' => $id,
			'job_id' => $jobId,
			'created_at' => (new \DateTimeImmutable('@' . (self::NOW - $ageSeconds))),
		];
	}

	/** 90 days, the documented default. */
	private function sweep(int $cap = 1000): array
	{
		return $this->queue->purgeTerminal(self::NOW - 90 * 86400, $cap);
	}

	public function testOnlyTerminalRowsPastTheCutoffGo(): void
	{
		$this->job(1, Queue::STATUS_DONE, 91 * 86400);    // terminal, past
		$this->job(2, Queue::STATUS_DONE, 89 * 86400);    // terminal, recent
		$this->job(3, Queue::STATUS_QUEUED, 365 * 86400); // non-terminal, any age
		$this->job(4, Queue::STATUS_CLAIMED, 365 * 86400);
		$this->job(5, Queue::STATUS_SKIPPED, 91 * 86400); // terminal, past
		$this->job(6, Queue::STATUS_ERROR, 91 * 86400);   // terminal, past

		$deleted = $this->sweep();

		$this->assertSame(3, $deleted['jobs'], 'done/skipped/error past the cutoff, nothing else');
		$this->assertSameCanonical([2, 3, 4], array_keys($this->queue->jobs));
	}

	public function testATerminalRowWithoutFinishedAtNeverGoes(): void
	{
		// Defensive: every terminal row written today carries finished_at, but
		// a null timestamp must not be treated as "older than everything".
		$this->job(1, Queue::STATUS_DONE, null);

		$this->assertSame(0, $this->sweep()['jobs']);
		$this->assertSameCanonical([1], array_keys($this->queue->jobs));
	}

	public function testStageRowsGoWithTheirJobAndStayWithTheirJob(): void
	{
		$this->job(1, Queue::STATUS_DONE, 91 * 86400);
		$this->job(2, Queue::STATUS_DONE, 89 * 86400);
		$this->stage(10, 1, 91 * 86400); // goes: its job goes
		$this->stage(11, 1, 10);         // goes: same job, recent row dies with it
		$this->stage(12, 2, 91 * 86400);  // stays: its job stays

		$deleted = $this->sweep();

		$this->assertSame(2, $deleted['stage_events']);
		$this->assertSameCanonical([12], array_keys($this->queue->stage));
	}

	public function testOrphanStageRowsPastTheCutoffGoRecentOnesStay(): void
	{
		// Stated rule: a stage row whose job no longer exists (a crash between
		// the two deletes of some earlier run, or manual surgery) is an orphan;
		// orphans are swept by their own age, not their job's.
		$this->stage(10, 999, 91 * 86400); // orphan, past the cutoff
		$this->stage(11, 999, 10);         // orphan, recent — kept

		$deleted = $this->sweep();

		$this->assertSame(1, $deleted['stage_events']);
		$this->assertSameCanonical([11], array_keys($this->queue->stage));
	}

	public function testTheFilesLedgerIsNeverTouched(): void
	{
		// oc_ocrflow_files is the permanent ledger that keeps dedupe working
		// (`isDoneForVersion()`); deleting from it re-opens the corpus for
		// paid re-OCR. It must be byte-for-byte identical after a sweep.
		$this->queue->files = [
			['file_id' => 7, 'etag' => 'a', 'status' => 'done', 'stamp_version' => 'v2'],
			['file_id' => 8, 'etag' => 'b', 'status' => 'skipped', 'stamp_version' => null],
		];
		$this->job(1, Queue::STATUS_DONE, 400 * 86400);
		$this->stage(10, 1, 400 * 86400);

		$this->sweep();

		$this->assertSame(
			[
				['file_id' => 7, 'etag' => 'a', 'status' => 'done', 'stamp_version' => 'v2'],
				['file_id' => 8, 'etag' => 'b', 'status' => 'skipped', 'stamp_version' => null],
			],
			$this->queue->files,
			'no sweep path may delete from ocrflow_files',
		);
		$this->assertNotContains('ocrflow_files', $this->queue->deletedTables, 'no DELETE ever names ocrflow_files');
	}

	public function testThePerRunCapBoundsOneRunAndTheRemainderWaits(): void
	{
		// cap + 50 rows: the first run deletes exactly the cap, the rest wait
		// for the next tick — one run must not lock the table for an hour.
		$cap = 10;
		for ($i = 1; $i <= $cap + 50; $i++) {
			$this->job($i, Queue::STATUS_DONE, 400 * 86400);
		}

		$deleted = $this->sweep($cap);
		$this->assertSame($cap, $deleted['jobs'], 'exactly the cap, never more');
		$this->assertCount(50, $this->queue->jobs, 'the remainder is still there');

		// Five more capped runs drain the rest.
		for ($run = 0; $run < 5; $run++) {
			$this->sweep($cap);
		}
		$this->assertSame([], $this->queue->jobs, 'the queue drains across runs');
	}

	public function testTheOrphanPassAlsoRespectsTheCap(): void
	{
		for ($i = 1; $i <= 12; $i++) {
			$this->stage($i, 999, 91 * 86400);
		}

		$deleted = $this->sweep(10);

		$this->assertSame(10, $deleted['stage_events']);
		$this->assertCount(2, $this->queue->stage);
	}

	public function testAnEmptySweepReportsZeros(): void
	{
		$this->job(1, Queue::STATUS_DONE, 10);

		$this->assertSame(['jobs' => 0, 'stage_events' => 0], $this->sweep());
	}

	public function testTheProductionSelectIsBoundedAndNeverNamesFiles(): void
	{
		// The SQL half: the select that feeds the loop must filter terminal
		// statuses, require finished_at, respect the limit, and never look at
		// ocrflow_files.
		$calls = [];
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('status_in');
		$expr->method('lt')->willReturn('finished_lt');
		$expr->method('isNotNull')->willReturn('finished_not_null');
		$expr->method('eq')->willReturn('1=1');
		$expr->method('isNull')->willReturn('null');

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$result->method('fetch')->willReturn(false);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnCallback(function (string $table) use ($qb, &$calls) {
			$calls['from'][] = $table;
			return $qb;
		});
		$qb->method('leftJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnCallback(function (int $limit) use ($qb, &$calls) {
			$calls['limit'] = $limit;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null) use (&$calls) {
				$calls['params'][] = [$value, $type];
				return ':p' . count($calls['params']);
			}
		);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$queue = new Queue(
			$db,
			new StageEvents(new RetryPolicy(), $this->createMock(LoggerInterface::class)),
		);

		$queue->purgeTerminal(self::NOW, 100);

		$this->assertContains('ocrflow_jobs', $calls['from']);
		$this->assertNotContains('ocrflow_files', $calls['from']);
		$this->assertSame(100, $calls['limit'], 'the select is limited to the remaining cap');
		$statuses = array_map(
			static fn (array $p) => $p[0],
			array_values(array_filter($calls['params'], static fn (array $p) => is_array($p[0])))
		);
		$this->assertContains([Queue::STATUS_DONE, Queue::STATUS_SKIPPED, Queue::STATUS_ERROR], $statuses);
		$this->assertContains(self::NOW, array_map(static fn (array $p) => $p[0], $calls['params']));
	}

	public function testTheProductionDeleteNeverNamesFiles(): void
	{
		$calls = [];
		$result = $this->createMock(IResult::class);
		// One id per SELECT: the job pass selects job 1, its stage pass
		// selects stage 1, the orphan pass then sees nothing new.
		$result->method('fetchAll')->willReturn([['id' => 1]]);
		$result->method('fetch')->willReturn(false);

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1=1');
		$expr->method('in')->willReturn('id_in');
		$expr->method('lt')->willReturn('lt');
		$expr->method('isNotNull')->willReturn('nn');
		$expr->method('isNull')->willReturn('null');
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'delete', 'set'] as $m) {
			$qb->method($m)->willReturnCallback(function (...$a) use ($qb, &$calls, $m) {
				$calls[$m][] = $a;
				return $qb;
			});
		}
		$qb->method('leftJoin')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = null) use (&$calls) {
			$calls['params'][] = $value;
			return ':p' . count($calls['params']);
		});
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$queue = new Queue(
			$db,
			new StageEvents(new RetryPolicy(), $this->createMock(LoggerInterface::class)),
		);

		$queue->purgeTerminal(self::NOW, 100);

		$deletedFrom = [];
		foreach ($calls['delete'] ?? [] as $args) {
			// delete($table, ?string $alias): the alias arg is always null here.
			foreach ($args as $arg) {
				if (is_string($arg)) {
					$deletedFrom[] = $arg;
				}
			}
		}
		$this->assertNotContains('ocrflow_files', $deletedFrom, 'no DELETE may ever name ocrflow_files');
	}

	/**
	 * @param list<int> $expected
	 * @param list<int> $actual
	 */
	private function assertSameCanonical(array $expected, array $actual): void
	{
		sort($expected);
		sort($actual);
		$this->assertSame($expected, $actual);
	}
}

/**
 * Queue with the four SQL seams replaced by an in-memory store that applies
 * the same predicates the production SQL applies. Subclassing is deliberate:
 * the loop, the cap, the deletion order and the orphan rule stay production
 * code — only the row I/O is faked.
 */
class StoreQueue extends Queue
{
	/** @var array<int, array<string, mixed>> */
	public array $jobs = [];
	/** @var array<int, array<string, mixed>> */
	public array $stage = [];
	/** @var list<array<string, mixed>> */
	public array $files = [];
	/** @var list<string> */
	public array $deletedTables = [];

	public function __construct(IDBConnection $db)
	{
		parent::__construct(
			$db,
			new StageEvents(new RetryPolicy(), new class implements LoggerInterface {
				public function emergency($message, array $context = []): void
				{
				}
				public function alert($message, array $context = []): void
				{
				}
				public function critical($message, array $context = []): void
				{
				}
				public function error($message, array $context = []): void
				{
				}
				public function warning($message, array $context = []): void
				{
				}
				public function notice($message, array $context = []): void
				{
				}
				public function info($message, array $context = []): void
				{
				}
				public function debug($message, array $context = []): void
				{
				}
				public function log($level, $message, array $context = []): void
				{
				}
			}),
		);
	}

	protected function selectExpiredTerminalIds(int $cutoff, int $limit): array
	{
		$ids = [];
		foreach ($this->jobs as $row) {
			$status = (string) $row['status'];
			$terminal = in_array($status, [Queue::STATUS_DONE, Queue::STATUS_SKIPPED, Queue::STATUS_ERROR], true);
			$finishedAt = $row['finished_at'];
			if ($terminal && $finishedAt !== null && $finishedAt < $cutoff) {
				$ids[] = (int) $row['id'];
			}
		}
		sort($ids);
		return array_slice($ids, 0, $limit);
	}

	protected function selectOrphanStageIds(int $cutoff, int $limit): array
	{
		$ids = [];
		foreach ($this->stage as $row) {
			$jobId = (int) $row['job_id'];
			/** @var \DateTimeImmutable $createdAt */
			$createdAt = $row['created_at'];
			if (!isset($this->jobs[$jobId]) && $createdAt->getTimestamp() < $cutoff) {
				$ids[] = (int) $row['id'];
			}
		}
		sort($ids);
		return array_slice($ids, 0, $limit);
	}

	protected function selectStageIdsForJobs(array $jobIds): array
	{
		$ids = [];
		foreach ($this->stage as $id => $row) {
			if (in_array((int) $row['job_id'], $jobIds, true)) {
				$ids[] = (int) $id;
			}
		}
		sort($ids);
		return $ids;
	}

	protected function deleteJobsById(array $ids): int
	{
		$this->deletedTables[] = 'ocrflow_jobs';
		$count = 0;
		foreach ($ids as $id) {
			if (isset($this->jobs[(int) $id])) {
				unset($this->jobs[(int) $id]);
				$count++;
			}
		}
		return $count;
	}

	protected function deleteStageRowsById(array $ids): int
	{
		$this->deletedTables[] = 'ocrflow_stage_events';
		$count = 0;
		foreach ($ids as $id) {
			if (isset($this->stage[(int) $id])) {
				unset($this->stage[(int) $id]);
				$count++;
			}
		}
		return $count;
	}
}
