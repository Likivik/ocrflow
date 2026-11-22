<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Report;

use OCA\OcrFlow\Report\PanelReport;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The arithmetic of the panel report, without a database.
 *
 * The report's numbers have been wrong twice, both times in the arithmetic
 * rather than in the query: a ghost file counted as settled, and a queue that
 * counted files twice. Those are decisions about sets, so they can be tested as
 * sets.
 */
class PanelReportTest extends TestCase
{
	/**
	 * @param list<array{reason: ?string, engine: ?string, version: ?string, count: int}> $settled
	 * @return array<string, mixed>
	 */
	private function report(int $total, array $settled, int $queued = 0, int $claimed = 0, int $empty = 0, int $unreadable = 0): array
	{
		return PanelReport::assemble($total, $empty, $unreadable, ['queued' => $queued, 'claimed' => $claimed], $settled);
	}

	public function testAGhostIsNotSettled(): void
	{
		$report = $this->report(10, [
			['reason' => PanelReport::GHOST_REASON, 'engine' => null, 'version' => null, 'count' => 2],
			['reason' => null, 'engine' => 'google', 'version' => '3.16.0', 'count' => 5],
		]);

		$this->assertSame(5, $report['never_processed'], 'the two ghosts are unprocessed, not read');
		$this->assertArrayNotHasKey(PanelReport::GHOST_REASON, $report['by_code'], 'a ghost gets no row of its own');
	}

	public function testLiveFilesAreSubtractedOnceEach(): void
	{
		$report = $this->report(
			10,
			[['reason' => null, 'engine' => 'google', 'version' => '3.16.0', 'count' => 3]],
			queued: 2,
			claimed: 1,
		);

		$this->assertSame(3, $report['queue']['queued'] + $report['queue']['claimed']);
		$this->assertSame(4, $report['never_processed'], '10 − 3 settled − 3 live');
	}

	public function testNeverProcessedIsNeverNegative(): void
	{
		// A database mid-deploy can briefly describe more settled files than the
		// disk admits. A negative count in the panel is worse than a zero.
		$report = $this->report(
			2,
			[['reason' => null, 'engine' => 'google', 'version' => '3.16.0', 'count' => 3]],
			claimed: 1,
		);

		$this->assertSame(0, $report['never_processed']);
	}

	public function testTheBlocksAccountForTheWholeDisk(): void
	{
		$report = $this->report(
			100,
			[
				['reason' => null, 'engine' => 'google', 'version' => '3.16.0', 'count' => 40],
				['reason' => 'skip_born_digital', 'engine' => null, 'version' => null, 'count' => 30],
				['reason' => 'error_worker_format_unreadable', 'engine' => null, 'version' => null, 'count' => 5],
			],
			queued: 7,
			claimed: 3,
			empty: 4,
			unreadable: 2,
		);

		$settledRows = array_sum($report['by_code']);

		$this->assertSame(75, $settledRows, 'the by_code rows sum to the settled files');
		$this->assertSame(
			$report['total'],
			$settledRows + $report['queue']['queued'] + $report['queue']['claimed'] + $report['never_processed'],
			'every processable file is settled, live, or unprocessed — and counted once',
		);
		$this->assertSame(4, $report['empty'], 'empty is outside the processable set, and says so');
		$this->assertSame(2, $report['unreadable_format'], 'so is unreadable');
	}

	public function testReadRowsAreSplitByEngineAndVersionLargestFirst(): void
	{
		$report = $this->report(10, [
			['reason' => null, 'engine' => 'google', 'version' => '3.16.0', 'count' => 2],
			['reason' => 'skip_ocred_by_ocrflow', 'engine' => 'google', 'version' => '3.16.0', 'count' => 5],
			['reason' => 'skip_ocred_by_ocrflow', 'engine' => 'google', 'version' => null, 'count' => 1],
		]);

		$this->assertSame([
			['engine' => 'google', 'version' => '3.16.0', 'count' => 7],
			['engine' => 'google', 'version' => null, 'count' => 1],
		], $report['read_by_engine'], 'a read is a read whether or not a code was recorded, and version not recorded is its own row');
	}

	public function testNoTextRowsNameTheReaderNotTheVersion(): void
	{
		$report = $this->report(10, [
			['reason' => 'skip_ocred_by_ocrflow_found_nothing', 'engine' => 'google', 'version' => '3.16.0', 'count' => 2],
			['reason' => 'skip_ocred_by_ocrflow_found_nothing', 'engine' => 'google', 'version' => '3.15.0', 'count' => 3],
		]);

		$this->assertSame([
			['engine' => 'google', 'version' => null, 'count' => 5],
		], $report['no_text_by_engine'], 'two versions of one reader are one row');
	}

	public function testAStaleJobReferenceStaysVisible(): void
	{
		// `error_worker_job_reference_stale` means the row lost its job, not
		// that the file is fine. It stays an error the owner can see.
		$report = $this->report(10, [
			['reason' => 'error_worker_job_reference_stale', 'engine' => null, 'version' => null, 'count' => 1],
		]);

		$this->assertSame(1, $report['by_code']['error_worker_job_reference_stale'] ?? 0);
		$this->assertSame(9, $report['never_processed']);
	}
}
