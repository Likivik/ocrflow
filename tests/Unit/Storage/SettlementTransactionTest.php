<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Storage;

use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\Ledger;
use OCA\OcrFlow\Storage\SettlementService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] One outcome, one transaction — and one attempt counter.
 *
 * The class doc said "the settle transaction: one result, three tables" while
 * every write committed on its own: a crash between them left `ocrflow_jobs`
 * saying one thing and `ocrflow_files` another. And the retry path counted the
 * attempt a second time (the claim already counts it), so a retryable failure
 * ran twice and the row then claimed three attempts.
 */
class SettlementTransactionTest extends TestCase
{
	private function job(int $attempts): Job
	{
		return Job::fromRow([
			'id' => 9,
			'path' => '/alice/files/scan.pdf',
			'file_id' => 7,
			'status' => 'claimed',
			'etag' => 'e1',
			'size' => 123,
			'engine' => 'tesseract',
			'claim_token' => 'w',
			'pages' => null,
			'chars' => null,
			'error' => null,
			'claimed_at' => 1,
			'reason' => null,
			'retryable' => null,
			'finished_at' => null,
			'reason_code' => null,
			'attempts' => $attempts,
			'created_at' => 1,
		]);
	}

	/**
	 * A builder mock that records what each UPDATE set, and reports one row
	 * touched — the guarded settle claim succeeds.
	 *
	 * @param list<string> $setColumns collected column names
	 */
	private function db(array &$setColumns, ?int &$begin, ?int &$commit, ?int &$rollback): IDBConnection
	{
		$begin = $commit = $rollback = 0;

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1 = 1');
		$expr->method('in')->willReturn('1 = 1');
		$expr->method('isNull')->willReturn('1 = 1');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('createFunction')->willReturn('0');
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('update')->willReturnSelf();
		$qb->method('insert')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('setValue')->willReturnSelf();
		$qb->method('set')->willReturnCallback(function (string $column) use (&$setColumns, $qb): IQueryBuilder {
			$setColumns[] = $column;

			return $qb;
		});
		$qb->method('executeStatement')->willReturn(1);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('beginTransaction')->willReturnCallback(function () use (&$begin): void {
			$begin++;
		});
		$db->method('commit')->willReturnCallback(function () use (&$commit): void {
			$commit++;
		});
		$db->method('rollBack')->willReturnCallback(function () use (&$rollback): void {
			$rollback++;
		});

		return $db;
	}

	private function settlement(IDBConnection $db, Job $job, Ledger $ledger): SettlementService
	{
		$jobs = $this->createMock(JobStore::class);
		$jobs->method('getById')->willReturn($job);

		$retryPolicy = new RetryPolicy();

		return new SettlementService($db, $jobs, $ledger, $retryPolicy, $this->createMock(LoggerInterface::class), new StageEvents($retryPolicy, $this->createMock(LoggerInterface::class)));
	}

	public function testAFullSettleIsOneCommit(): void
	{
		$sets = [];
		$db = $this->db($sets, $begin, $commit, $rollback);
		$ledger = $this->createMock(Ledger::class);
		$settlement = $this->settlement($db, $this->job(1), $ledger);

		$settlement->finish(9, ['status' => 'error', 'reason_code' => 'broken_pdf'], 'w');

		$this->assertSame(1, $begin, 'the settle opens one transaction');
		$this->assertSame(1, $commit, 'and closes it once');
		$this->assertSame(0, $rollback);
	}

	public function testALaterWriteFailingRollsTheWholeSettleBack(): void
	{
		$sets = [];
		$db = $this->db($sets, $begin, $commit, $rollback);
		$ledger = $this->createMock(Ledger::class);
		$ledger->method('recordFile')->willThrowException(new \RuntimeException('ledger is down'));
		$settlement = $this->settlement($db, $this->job(1), $ledger);

		try {
			$settlement->finish(9, ['status' => 'error', 'reason_code' => 'broken_pdf'], 'w');
			$this->fail('the failure must propagate: no partial answer is kept');
		} catch (\RuntimeException $e) {
			$this->assertSame('ledger is down', $e->getMessage());
		}

		$this->assertSame(1, $rollback, 'the job update goes back with the ledger');
		$this->assertSame(0, $commit, 'nothing is committed');
	}

	public function testAClaimedAttemptIsNotCountedTwice(): void
	{
		// JobStore::claim() already added one. The retry UPDATE must not add
		// another: the old double count ran every retryable failure twice and
		// then reported three attempts.
		$sets = [];
		$db = $this->db($sets, $begin, $commit, $rollback);
		$ledger = $this->createMock(Ledger::class);
		$settlement = $this->settlement($db, $this->job(1), $ledger);

		$settlement->finish(9, ['status' => 'error', 'reason_code' => 'timeout', 'retryable' => true], 'w');

		$this->assertNotContains('attempts', $sets, 'the claim counts the attempt, the retry must not');
		$this->assertSame(1, $begin, 'the retry requeue is part of the same settle');
	}

	public function testASecondRetryableFailureStillGetsItsThirdTry(): void
	{
		// Two tries counted so far (attempts = 2): a third is owed. The old
		// gate (`attempts + 1 < 3`) refused it.
		$sets = [];
		$db = $this->db($sets, $begin, $commit, $rollback);
		$ledger = $this->createMock(Ledger::class);
		$ledgerCalled = 0;
		$ledger->method('recordFile')->willReturnCallback(function () use (&$ledgerCalled): void {
			$ledgerCalled++;
		});
		$settlement = $this->settlement($db, $this->job(2), $ledger);

		$settlement->finish(9, ['status' => 'error', 'reason_code' => 'timeout', 'retryable' => true], 'w');

		$this->assertNotContains('attempts', $sets, 'still not counted twice');
		$this->assertSame(2, $ledgerCalled, 'the verdict is recorded and the requeue follows: the third try is owed');
	}

	public function testTheFourthTryStaysFailed(): void
	{
		$sets = [];
		$db = $this->db($sets, $begin, $commit, $rollback);
		$ledger = $this->createMock(Ledger::class);
		// attempts = 3 means three tries have been made and paid for.
		$settlement = $this->settlement($db, $this->job(3), $ledger);

		$ledgerCalled = 0;
		$ledger->method('recordFile')->willReturnCallback(function () use (&$ledgerCalled): void {
			$ledgerCalled++;
		});
		$settlement->finish(9, ['status' => 'error', 'reason_code' => 'timeout', 'retryable' => true], 'w');

		$this->assertSame(1, $ledgerCalled, 'the verdict is recorded once; no requeue follows');
	}
}
