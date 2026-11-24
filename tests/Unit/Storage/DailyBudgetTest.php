<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Storage;

use OCA\OcrFlow\Storage\DailyBudget;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The daily budget: a grant cannot walk past the cap.
 *
 * The old counter lived in `oc_preferences`, written read-modify-write: two
 * tabs both read the same number, both passed the gate, and one write clobbered
 * the other. Every grant here is one conditional `used = used + n WHERE
 * used + n <= cap`, so the sum of grants can never exceed the cap — and the
 * grant is clamped to what the day still allows, which the old gate never was
 * (195 of 200 used, one click queued 200 more).
 */
class DailyBudgetTest extends TestCase
{
	private function budget(int $cap = 200, int $perClick = 200, int $used = 0): StoreBudget
	{
		$budget = new StoreBudget($this->createMock(IDBConnection::class), $cap, $perClick);
		$budget->setUsed('alice', $used);

		return $budget;
	}

	public function testAFullAskUnderTheCapIsGrantedWhole(): void
	{
		$grant = $this->budget()->reserve('alice', 25);

		$this->assertSame(25, $grant['granted']);
		$this->assertSame(25, $grant['used']);
		$this->assertSame(200, $grant['cap']);
	}

	public function testTheGrantIsClampedToWhatTheDayStillAllows(): void
	{
		// The old gate: 195 of 200 used, ask 25 -> it queued 25 and the day
		// ended at 220. The budget grants the five that exist.
		$grant = $this->budget(used: 195)->reserve('alice', 25);

		$this->assertSame(5, $grant['granted']);
		$this->assertSame(200, $grant['used'], 'the day is exactly at the cap, never over');
	}

	public function testAnExhaustedDayGrantsNothing(): void
	{
		$grant = $this->budget(used: 200)->reserve('alice', 25);

		$this->assertSame(0, $grant['granted']);
		$this->assertSame(200, $grant['used']);
	}

	public function testEveryClickAddsUpExactly(): void
	{
		// The lost-update bug: two clicks, each 25, with 30 left. The old
		// counter wrote both sums and kept one. Here the day ends at the cap.
		$budget = $this->budget(used: 170);

		$first = $budget->reserve('alice', 25);
		$second = $budget->reserve('alice', 25);

		$this->assertSame(25, $first['granted']);
		$this->assertSame(5, $second['granted'], 'the second click gets only what the first left');
		$this->assertSame(200, $second['used']);
	}

	public function testOneClickCannotOutrunItsOwnCap(): void
	{
		$grant = $this->budget(perClick: 25)->reserve('alice', 900);

		$this->assertSame(25, $grant['granted']);
	}

	public function testUsersDoNotShareABudget(): void
	{
		$budget = $this->budget();
		$budget->reserve('alice', 25);
		$grant = $budget->reserve('bob', 25);

		$this->assertSame(25, $grant['granted']);
		$this->assertSame(25, $budget->used('alice'));
		$this->assertSame(25, $budget->used('bob'));
	}

	public function testTheGuardIsOneConditionalUpdate(): void
	{
		// The SQL shape is the whole point: `used + n <= cap` must be IN the
		// UPDATE, not a read beside it. Pin it so a refactor cannot quietly
		// demote the guard to a check.
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1 = 1');
		$guardCalls = 0;
		$expr->method('lte')->willReturnCallback(function () use (&$guardCalls) {
			$guardCalls++;

			return 'used + :p <= :cap';
		});

		$exe = 0;
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('createFunction')->willReturn('used + :p');
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('executeStatement')->willReturnCallback(function () use (&$exe) {
			$exe++;

			return 1;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('insertIfNotExist')->willReturn(1);

		$budget = new class($db) extends DailyBudget {
			protected function usedFor(string $uid, string $day): int
			{
				return 0;
			}
		};
		$budget->reserve('alice', 1);

		$this->assertSame(1, $guardCalls, 'exactly one conditional guard: the one inside the UPDATE');
		$this->assertSame(1, $exe, 'the full ask is one statement');
	}
}
