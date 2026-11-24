<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\Mine;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The worklist core: merge, order, counts, page.
 *
 * The section lied about its own size three ways: the heading counted a
 * fetched span, the "50 of 50 shown" line counted the page, and the chips
 * counted only the loaded rows — three numbers, three different worlds. The
 * rule this pins: everything the section shows is derivable from ONE answer,
 * and the union of the pages IS the list the totals were taken over.
 */
class MineListTest extends TestCase
{
	/** @return list<array<string, mixed>> */
	private function side(int $count, string $origin, int $startId, int $startAt): array
	{
		$rows = [];
		for ($i = 0; $i < $count; $i++) {
			$rows[] = [
				'id' => $startId + $i,
				'origin' => $origin,
				'path' => 'alice/files/' . $origin . '-' . $i . '.pdf',
				'finished_at' => $startAt - $i,
			];
		}

		return $rows;
	}

	public function testTotalIsTheWorldNotThePage(): void
	{
		// 120 needs_fix + 180 redo = 300 files; a 50-row page must still say
		// 300. The old code said 50 — or 100 — depending on which screen asked.
		$page = Mine::page(
			$this->side(120, 'job', 1, 100000),
			120,
			$this->side(180, 'ledger', 1, 99000),
			180,
			null,
			0,
			50,
		);

		$this->assertSame(300, $page['total'], 'the heading counts the world');
		$this->assertCount(50, $page['rows'], 'the page is a page');
		$this->assertSame(['all' => 300, 'needs_fix' => 120, 'redo' => 180], $page['counts'], 'the chips are derivable from the same totals');
	}

	public function testThePagesTileTheListExactly(): void
	{
		$job = $this->side(120, 'job', 1, 100000);
		$redo = $this->side(180, 'ledger', 1, 99000);

		$seen = [];
		for ($offset = 0; $offset < 300; $offset += 50) {
			$page = Mine::page($job, 120, $redo, 180, null, $offset, 50);
			foreach ($page['rows'] as $row) {
				$key = $row['origin'] . ':' . $row['id'];
				$this->assertArrayNotHasKey($key, $seen, 'a row appears on exactly one page');
				$seen[$key] = true;
			}
		}

		$this->assertCount(300, $seen, 'the pages together are the whole list — nothing hidden past the first page');
	}

	public function testTheOrderIsTotalSoNothingFallsBetweenPages(): void
	{
		// Same timestamp on both sides: without a total order, two rows can
		// swap between fetches and one of them vanishes between page 1 and 2.
		$job = [
			['id' => 7, 'origin' => 'job', 'finished_at' => 500],
			['id' => 3, 'origin' => 'job', 'finished_at' => 500],
		];
		$redo = [
			['id' => 9, 'origin' => 'ledger', 'finished_at' => 500],
			['id' => 4, 'origin' => 'ledger', 'finished_at' => 500],
		];

		$first = Mine::page($job, 2, $redo, 2, null, 0, 2);
		$second = Mine::page($job, 2, $redo, 2, null, 2, 2);

		$keys = array_map(static fn (array $r): string => $r['origin'] . ':' . $r['id'], array_merge($first['rows'], $second['rows']));
		$this->assertSame(['ledger:9', 'job:7', 'ledger:4', 'job:3'], $keys, 'finished_at, then id, then origin — always the same order');
	}

	public function testTheChipsFilterServerSide(): void
	{
		$job = $this->side(30, 'job', 1, 100000);
		$redo = $this->side(20, 'ledger', 1, 99000);

		$needsFix = Mine::page($job, 30, $redo, 20, 'needs_fix', 0, 50);
		$this->assertSame(30, $needsFix['total']);
		$this->assertCount(30, $needsFix['rows']);
		$this->assertSame(['all' => 50, 'needs_fix' => 30, 'redo' => 20], $needsFix['counts'], 'the other chips still count the world');

		$redoOnly = Mine::page($job, 30, $redo, 20, 'redo', 0, 50);
		$this->assertSame(20, $redoOnly['total']);
		$this->assertCount(20, $redoOnly['rows']);
	}

	public function testASearchSeesRowsPastTheFirstPage(): void
	{
		// The search runs before the count and the slice (in the sides'
		// queries), so this is what the answer must look like afterwards:
		// the total is the match count, not the page count.
		$matches = array_values(array_filter(
			$this->side(300, 'job', 1, 100000),
			static fn (array $r): bool => str_contains((string) $r['path'], 'job-29'),
		));
		$page = Mine::page($matches, count($matches), [], 0, null, 0, 50);

		$this->assertSame(count($matches), $page['total']);
		$this->assertGreaterThan(0, count($matches), 'the fixture must match something');
		$this->assertSame($page['total'], count($matches), 'rows and totals are one answer');
	}
}
