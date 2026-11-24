<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Storage;

use OCA\OcrFlow\Storage\SpendJournal;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The spend journal: one row per paid engine run.
 *
 * Money leaves when the engine runs, so the row IS the run — `(job_id,
 * attempts)` booked once, at claim. The bookkeeping invariant is conservation:
 * a run's units go from reserved to spent (the real number) or to released
 * (the run died before spending), and only released gives the day its units
 * back. Whatever happens to the run, exactly one of the three happens to its
 * booking.
 */
class SpendJournalTest extends TestCase
{
	private function journal(int $cap = 2000): StoreJournal
	{
		return new StoreJournal($this->createMock(IDBConnection::class), $cap);
	}

	public function testAReserveIsRefusedWhenTheDayIsSpent(): void
	{
		$journal = $this->journal(2000);
		$journal->reserveForRun('alice', 1, 1, 1995);
		$this->assertFalse($journal->reserveForRun('alice', 2, 1, 10), '1995 + 10 is over a 2000-page day');

		$rows = $journal->all();
		$this->assertCount(1, $rows, 'the refused run books nothing');
	}

	public function testTheSameRunCannotBeBookedTwice(): void
	{
		$journal = $this->journal();
		$this->assertTrue($journal->reserveForRun('alice', 1, 1, 50));
		$this->assertTrue($journal->reserveForRun('alice', 1, 1, 50), 'already booked is not an error');

		$rows = $journal->all();
		$this->assertCount(1, $rows, 'one row per run, ever');
		$this->assertSame(50, $journal->todayUnits('alice'), 'the second call must not add units');
	}

	public function testSettleRecordsTheActualNumber(): void
	{
		$journal = $this->journal();
		$journal->reserveForRun('alice', 1, 1, 50);
		$journal->settleForRun(1, 1, 10);

		$rows = $journal->all();
		$this->assertSame('spent', $rows[0]['state']);
		$this->assertSame(10, $rows[0]['units'], 'the books record what the engine really read, not the estimate');
		$this->assertSame(10, $journal->todayUnits('alice'));
	}

	public function testSettleAfterReleaseDoesNothing(): void
	{
		$journal = $this->journal();
		$journal->reserveForRun('alice', 1, 1, 50);
		$journal->releaseForRun(1, 1);
		$journal->settleForRun(1, 1, 10);

		$rows = $journal->all();
		$this->assertSame('released', $rows[0]['state'], 'a released run cannot be spent later');
		$this->assertSame(0, $journal->todayUnits('alice'));
	}

	public function testReleaseGivesTheUnitsBack(): void
	{
		$journal = $this->journal();
		$journal->reserveForRun('alice', 1, 1, 50);
		$journal->releaseForRun(1, 1);

		$this->assertSame(0, $journal->todayUnits('alice'), 'a run that never spent returns the day its units');
	}

	public function testBothStatesCountAgainstTheDay(): void
	{
		// The race window's guard: a reserved run is money about to leave and
		// must not be spendable twice.
		$journal = $this->journal(100);
		$journal->reserveForRun('alice', 1, 1, 40);
		$journal->settleForRun(1, 1, 40);
		$journal->reserveForRun('alice', 2, 1, 40);

		$this->assertFalse($journal->reserveForRun('alice', 3, 1, 40), 'spent + reserved both count: 40 + 40 + 40 is over 100');
		$this->assertSame(80, $journal->todayUnits('alice'));
	}

	public function testSettleWithUnknownPagesKeepsTheBookedEstimate(): void
	{
		// A run whose worker died may have read its pages before it went.
		// Unknown is not zero: charging the hope that nothing was spent is how
		// a cap becomes a suggestion.
		$journal = $this->journal();
		$journal->reserveForRun('alice', 1, 1, 50);
		$journal->settleForRun(1, 1, null);

		$rows = $journal->all();
		$this->assertSame('spent', $rows[0]['state']);
		$this->assertSame(50, $rows[0]['units'], 'the booked estimate stands');
		$this->assertSame(50, $journal->todayUnits('alice'));
	}

	public function testSweepReleasesOnlyStaleReservations(): void
	{
		$journal = $this->journal();
		$journal->reserveForRun('alice', 1, 1, 50);
		$journal->reserveForRun('alice', 2, 1, 50);
		$journal->ageRun(1, 1, time() - 90000);

		$released = $journal->sweep(86400);

		$this->assertSame(1, $released, 'only the stale booking is swept');
		$this->assertSame(50, $journal->todayUnits('alice'), 'the fresh run still counts');
	}

	public function testPurgeDropsOldSettledRowsButNeverAReservedOne(): void
	{
		$journal = $this->journal();
		$journal->reserveForRun('alice', 1, 1, 50);
		$journal->settleForRun(1, 1, 50);
		$journal->reserveForRun('alice', 2, 1, 50);
		$journal->ageRun(1, 1, time() - 9000000);

		$dropped = $journal->purge(90);

		$this->assertSame(1, $dropped, 'the old settled row goes; the live reservation stays');
		$this->assertCount(1, $journal->all());
	}

	public function testEveryTransitionIsOneStatement(): void
	{
		// The SQL shape is the point: transitions are single guarded UPDATEs,
		// never read-then-write. Pin it so a refactor cannot demote a guard.
		$stmts = 0;
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1 = 1');
		$expr->method('gt')->willReturn('1 = 1');
		$expr->method('neq')->willReturn('1 = 1');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('createFunction')->willReturn('0');
		$qb->method('update')->willReturnSelf();
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('setValue')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('executeStatement')->willReturnCallback(function () use (&$stmts): int {
			$stmts++;

			return 1;
		});
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(['n' => 0]);
		$result->method('fetchAll')->willReturn([]);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$journal = new SpendJournal($db, 2000);
		$journal->settleForRun(9, 1, 10);

		$this->assertSame(1, $stmts, 'a settle is one guarded UPDATE');
	}
}
