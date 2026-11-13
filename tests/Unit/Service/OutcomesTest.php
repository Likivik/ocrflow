<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\Outcomes;
use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The one reader behind the five tabs. Every test here is [unit]: no SQL
 * runs; the mapping from three origins (jobs, ledger, pending stashes) to
 * the five tabs is production code exercised against in-memory rows.
 *
 * The rule set, and the defect each test catches:
 *
 *  - every row lands in EXACTLY ONE tab (a row nobody lists is invisible
 *    work; a row listed twice is a double decision for one file);
 *  - a below-bar stamp is a Decide row with the engine facts and an honest
 *    estimate (catches: settling everything at the bar, hiding redos);
 *  - an at-bar stamp is Settled, never Decide (catches: re-billing a file
 *    the bar already accepts);
 *  - the summary counts agree with the listings, and done rows are counted
 *    separately inside Settled;
 *  - a stash pending row suppresses its duplicate ledger row (catches: the
 *    same conflict being decided twice);
 *  - the estimate never invents pages or money.
 */
class OutcomesTest extends TestCase
{
	/**
	 * @param array<string, ?string> $overrides
	 * @return array<string, mixed>
	 */
	private function ledgerRow(int $fileId, string $reason, array $overrides = []): array
	{
		return array_merge([
			'id' => $fileId,
			'file_id' => $fileId,
			'path' => 'alice/files/file' . $fileId . '.pdf',
			'status' => 'skipped',
			'reason' => $reason,
			'engine' => 'google',
			'engine_version' => '3.15.0',
			'etag' => 'e' . $fileId,
			'updated_at' => 1000 + $fileId,
		], $overrides);
	}

	/**
	 * @param array<string, ?string|int> $overrides
	 * @return array<string, mixed>
	 */
	private function stashRow(int $fileId, array $overrides = []): array
	{
		return array_merge([
			'id' => $fileId,
			'file_id' => $fileId,
			'etag' => 'e' . $fileId,
			'artifact_path' => 'pending-' . $fileId . '-x.pdf',
			'engine' => 'google',
			'engine_version' => '3.15.0',
			'pages' => 4,
			'created_at' => 1000 + $fileId,
			'status' => 'pending',
		], $overrides);
	}

	/**
	 * One fixture of every row class: a job per jobs-verdict, a ledger row
	 * per ledger class, one pending stash (whose ledger twin also exists).
	 */
	private function store(): StoreOutcomes
	{
		$store = new StoreOutcomes([], [
			// jobs: one needs_fix, one wait, one drop
			['id' => 1, 'path' => 'a/nf.pdf', 'reason_code' => 'error_file_broken', 'reason' => 'error_file_broken', 'error' => 'x', 'attempts' => 3, 'finished_at' => 300],
			['id' => 2, 'path' => 'a/wait.pdf', 'reason_code' => 'error_provider_timed_out', 'reason' => 'error_provider_timed_out', 'error' => 'x', 'attempts' => 3, 'finished_at' => 300],
			['id' => 3, 'path' => 'a/drop.pdf', 'reason_code' => 'error_worker_job_reference_stale', 'reason' => 'error_worker_job_reference_stale', 'error' => 'x', 'attempts' => 1, 'finished_at' => 300],
		]);
		$store->ledger = [
			// settled at the bar
			$this->ledgerRow(11, 'skip_ocred_by_ocrflow'),
			// settled: nothing_to_do codes
			$this->ledgerRow(12, 'skip_text_native'),
			// settled: a done row (no reason)
			$this->ledgerRow(13, '', ['status' => 'done', 'reason' => null, 'engine' => null, 'engine_version' => null]),
			// redo: below the bar (3.9.0 < 3.15.0? no: numeric compare, 3.9 < 3.15)
			$this->ledgerRow(14, 'skip_ocred_by_ocrflow', ['engine_version' => '3.9.0']),
			// redo: suspected foreign
			$this->ledgerRow(15, 'skip_ocred_by_suspected_foreign', ['engine' => null, 'engine_version' => null]),
			// redo: stashed source change — the pending twin below is the
			// row that must show, this ledger row must NOT double it
			$this->ledgerRow(16, 'skip_source_changed_result_saved'),
			// ledger row of a needs_fix code: its job row is the listed one
			$this->ledgerRow(17, 'error_queued_twice_for_same_bytes'),
		];
		$store->pending = [
			$this->stashRow(16),
		];
		return $store;
	}

	/**
	 * [unit] Every row lands in exactly one tab: no invisible row, no
	 * double row. Catches a mapping gap (a verdict class nobody reads) and
	 * a double listing (the stash shown as both stash and ledger row).
	 */
	public function testEveryRowLandsInExactlyOneTab(): void
	{
		$store = $this->store();
		$tabs = [];
		foreach (['needs_fix', 'redo', 'wait', 'settled', 'dropped'] as $verdict) {
			$result = $store->list($verdict, 100);
			$this->assertNotSame([], $result['rows'], $verdict . ' listed nothing — a row class is invisible');
			foreach ($result['rows'] as $row) {
				$key = $row['origin'] . ':' . $row['id'];
				$tabs[$key][] = $verdict;
			}
		}

		foreach ($tabs as $key => $owners) {
			$this->assertCount(1, $owners, $key . ' appears in multiple tabs: ' . implode(', ', $owners));
		}

		// The partition: the fixture's 3 jobs + 5 ledger-derived redo/settled
		// rows + 1 stash row = 9 rows. The needs_fix-coded ledger row (17) is
		// represented by its JOB row, and the stashed ledger row (16) by its
		// PENDING row, so neither adds a tab row of its own.
		$this->assertCount(9, $tabs, 'row keys: ' . implode(', ', array_keys($tabs)));
	}

	/**
	 * [unit] The tab each class lands in, by name.
	 */
	public function testTheTabToOriginMapping(): void
	{
		$store = $this->store();

		$needs = array_column($store->list('needs_fix', 100)['rows'], 'path');
		$this->assertSame(['a/nf.pdf'], $needs);

		$wait = array_column($store->list('wait', 100)['rows'], 'path');
		$this->assertSame(['a/wait.pdf'], $wait);

		$dropped = array_column($store->list('dropped', 100)['rows'], 'path');
		$this->assertSame(['a/drop.pdf'], $dropped);

		$redo = $store->list('redo', 100)['rows'];
		$this->assertSame(
			['alice/files/file16.pdf', 'alice/files/file15.pdf', 'alice/files/file14.pdf'],
			array_column($redo, 'path'),
			'redo = below-bar + suspected-foreign + the stash itself (its ledger twin suppressed), newest first',
		);
		$this->assertSame('stash', $redo[0]['origin'], 'the pending row, not the ledger row, carries the stash');
		$this->assertTrue($redo[0]['pending'], 'the stash row must offer Apply/Discard');

		$settled = $store->list('settled', 100)['rows'];
		$this->assertSame(
			['alice/files/file13.pdf', 'alice/files/file12.pdf', 'alice/files/file11.pdf'],
			array_column($settled, 'path'),
		);
		$this->assertFalse($settled[2]['done'], 'a settled skip is not a done row');
		$this->assertTrue($settled[0]['done'], 'a ledger done row is flagged done');
	}

	/**
	 * [unit] A below-bar stamp appears in Decide with the engine facts and
	 * an honest estimate. Catches: a redo row without the facts the owner
	 * needs to decide, or with a fabricated page count.
	 */
	public function testABelowBarStampIsADecideRowWithEngineFactsAndNoInventedPages(): void
	{
		$store = $this->store();

		$redo = $store->list('redo', 100)['rows'];
		$below = $redo[2];

		$this->assertSame('alice/files/file14.pdf', $below['path']);
		$this->assertSame('google', $below['engine']);
		$this->assertSame('3.9.0', $below['engine_version']);
		$this->assertSame('skip_ocred_by_ocrflow', $below['code']);
		// The ledger carries no page count: unknown, never zero.
		$this->assertNull($below['pages']);
	}

	/**
	 * [unit] The bar decides between Decide and Settled at read time: the
	 * same row moves tab when the bar moves, with no row rewrite.
	 */
	public function testMovingTheBarMovesTheRowBetweenDecideAndSettled(): void
	{
		$store = $this->store();
		$this->assertContains('alice/files/file14.pdf', array_column($store->list('redo', 100)['rows'], 'path'));

		// The bar lowered below 3.9.0: the same row is now good enough.
		$moved = new StoreOutcomes(['ocr_accepted_engines' => '{"google":"3.0.0"}']);
		$moved->ledger = $store->ledger;
		$this->assertSame(
			['alice/files/file14.pdf', 'alice/files/file13.pdf', 'alice/files/file12.pdf', 'alice/files/file11.pdf'],
			array_column($moved->list('settled', 100)['rows'], 'path'),
			'file14 moved to Settled when the bar dropped below its stamp',
		);
		$redoPaths = array_column($moved->list('redo', 100)['rows'], 'path');
		$this->assertNotContains('alice/files/file14.pdf', $redoPaths);
	}

	/**
	 * [unit] summary() agrees with the listings, and done rows are counted
	 * separately inside Settled. Catches: a badge count that disagrees with
	 * the tab, and the done rows being silently merged into settled skips.
	 */
	public function testSummaryCountsAgreeWithTheListingsAndDoneIsSeparate(): void
	{
		$store = $this->store();
		$summary = $store->summary();

		$this->assertSame(1, $summary['needs_fix']);
		$this->assertSame(1, $summary['wait']);
		$this->assertSame(1, $summary['dropped']);
		$this->assertSame(3, $summary['redo']);
		$this->assertSame(3, $summary['settled']);
		$this->assertSame(1, $summary['done'], 'the one done row is counted separately');
		$this->assertLessThanOrEqual($summary['settled'], $summary['done'], 'done rows are a subset of settled');
	}

	/**
	 * [unit] The estimate is honest: pages known only when every selected
	 * row has a page count, money only when a price is configured AND every
	 * page count is known. Catches: a fabricated "~$X".
	 */
	public function testTheEstimateNeverInventsPagesOrMoney(): void
	{
		$store = $this->store();

		// The stash (file 16, 4 pages) alone: pages known, but no price is
		// configured, so the cost is unknown.
		$estimate = $store->estimate([16], null);
		$this->assertSame(1, $estimate['files']);
		$this->assertSame(4, $estimate['pages']);
		$this->assertTrue($estimate['pages_known']);
		$this->assertNull($estimate['cost'], 'no price configured: the cost must be unknown, not invented');

		// A below-bar row has no page count: pages_known false, cost null.
		$estimate = $store->estimate([14], null);
		$this->assertFalse($estimate['pages_known']);
		$this->assertNull($estimate['cost']);

		// With a price and only page-known rows: the cost is the arithmetic.
		$priced = new StoreOutcomes(['redo_cost_per_1000_pages' => '1.50']);
		$priced->ledger = $store->ledger;
		$priced->pending = $store->pending;
		$estimate = $priced->estimate([16], null);
		$this->assertSame(0.006, $estimate['cost'], '4 pages at $1.50 per 1000 pages');

		// A mixed selection never gets a cost.
		$estimate = $priced->estimate([14, 16], null);
		$this->assertFalse($estimate['pages_known']);
		$this->assertNull($estimate['cost']);
	}

	/**
	 * [unit] An estimate by code covers every redo row with that code, and
	 * the rows that do not carry it are not counted.
	 */
	public function testTheEstimateByCodeSelectsOnlyThatCode(): void
	{
		$store = $this->store();

		$estimate = $store->estimate([], 'skip_source_changed_result_saved');
		$this->assertSame(1, $estimate['files']);
		$this->assertSame(4, $estimate['pages']);
	}

	/**
	 * [unit] Redo spends one retry per selected file through the SAME retry
	 * implementation requeue uses, capped, and answers with the estimate it
	 * spent. Catches: a second retry code path that skips the loop-breaker
	 * reset, and an uncapped bulk re-OCR.
	 */
	public function testRedoIsCappedAndReportsTheEstimateItSpent(): void
	{
		$queue = $this->createMock(\OCA\OcrFlow\Db\Queue::class);
		$queue->method('deleteTerminalForFile')->willReturn(1);
		$attention = new \OCA\OcrFlow\Service\Attention(
			$queue,
			new RetryPolicy(),
			StoreOutcomes::configWith(['attention_retry_cap' => '2']),
		);

		$store = new StoreOutcomes(['attention_retry_cap' => '2'], [], $attention);
		$store->ledger = $this->store()->ledger;
		$store->pending = $this->store()->pending;

		$queue->expects($this->exactly(2))->method('deleteTerminalForFile');
		$queue->expects($this->exactly(2))->method('clearPermanentVerdict');
		$queue->expects($this->exactly(2))->method('resetTimesEnqueued');

		$result = $store->redo([14, 15, 16], null);

		$this->assertSame(2, $result['retried'], 'the cap, not the selection, bounds one click');
		$this->assertSame(2, $result['cap']);
		$this->assertSame(2, $result['estimate']['files'], 'the estimate describes the rows actually retried');
	}

	/**
	 * [unit] An empty selection spends nothing: no queue call at all.
	 */
	public function testAnEmptySelectionSpendsNothing(): void
	{
		$queue = $this->createMock(\OCA\OcrFlow\Db\Queue::class);
		$attention = new \OCA\OcrFlow\Service\Attention($queue, new RetryPolicy(), StoreOutcomes::configWith([]));
		$store = new StoreOutcomes([], [], $attention);
		$store->ledger = $this->store()->ledger;
		$store->pending = $this->store()->pending;

		$queue->expects($this->never())->method('deleteTerminalForFile');
		$queue->expects($this->never())->method('clearPermanentVerdict');

		$result = $store->redo([], null);

		$this->assertSame(0, $result['retried']);
		$this->assertSame(0, $result['estimate']['files']);
	}

	/**
	 * [unit] Our own stamp with NO engine (written before provenance was
	 * recorded) is Settled — provenance unknown — with an explicit note,
	 * and never a redo candidate. Both spellings, and an empty-string
	 * engine too. Catches: a NULL engine falling through the bar
	 * comparison into Decide, where one click would re-OCR a thousand
	 * files we already OCR'd.
	 */
	public function testAPreProvenanceStampIsSettledUnknownNeverDecide(): void
	{
		$store = new StoreOutcomes([]);
		$store->ledger = [
			// the legacy spelling, engine NULL as the old rows were written
			$this->ledgerRow(21, 'stamped_good_by_us', ['engine' => null, 'engine_version' => null]),
			// the current spelling, engine an empty string (same absence)
			$this->ledgerRow(22, 'skip_ocred_by_ocrflow', ['engine' => '', 'engine_version' => null]),
		];

		$redoPaths = array_column($store->list('redo', 100)['rows'], 'path');
		$this->assertNotContains('alice/files/file21.pdf', $redoPaths, 'a NULL-engine stamp must never be a redo candidate');
		$this->assertNotContains('alice/files/file22.pdf', $redoPaths, 'an empty-engine stamp must never be a redo candidate');

		$settledPaths = array_column($store->list('settled', 100)['rows'], 'path');
		foreach (['alice/files/file21.pdf', 'alice/files/file22.pdf'] as $path) {
			$this->assertContains($path, $settledPaths, $path . ' must be Settled (provenance unknown)');
		}

		$settledRows = $store->list('settled', 100)['rows'];
		foreach ($settledRows as $row) {
			$this->assertSame(
				'engine unknown: stamped before provenance was recorded',
				$row['note'],
				'a settled pre-provenance row must carry the explicit note',
			);
		}

		// An estimate never picks them up: unknown, never fabricated.
		$estimate = $store->estimate([21, 22], null);
		$this->assertSame(0, $estimate['files'], 'a settled row must not enter a redo estimate');
		$this->assertFalse($estimate['pages_known']);
		$this->assertNull($estimate['cost']);
	}

	/**
	 * [unit] Regression guards for the rule being kept: a KNOWN engine
	 * below the bar IS a Decide row; a known engine at or above the bar is
	 * Settled with no note. Only provenance we can compare goes to Decide.
	 */
	public function testKnownEnginesStillSplitByTheBar(): void
	{
		$store = new StoreOutcomes([]);
		$store->ledger = [
			$this->ledgerRow(31, 'skip_ocred_by_ocrflow', ['engine_version' => '3.9.0']),
			$this->ledgerRow(32, 'skip_ocred_by_ocrflow', ['engine_version' => '3.15.0']),
		];

		$redoPaths = array_column($store->list('redo', 100)['rows'], 'path');
		$this->assertContains('alice/files/file31.pdf', $redoPaths, 'a known below-bar engine must stay in Decide');

		$settledRows = $store->list('settled', 100)['rows'];
		$this->assertContains('alice/files/file32.pdf', array_column($settledRows, 'path'), 'a known at-bar engine must stay Settled');
		foreach ($settledRows as $row) {
			$this->assertNotSame('alice/files/file31.pdf', $row['path']);
			$this->assertNull($row['note'], 'a bar-settled row carries no provenance note');
		}
	}

	/**
	 * [unit] list() pages: limit and offset slice the classified rows, and
	 * total is the classified count. Catches: totals counted before the
	 * bar classification (a badge that disagrees with the tab).
	 */
	public function testListPaginatesTheClassifiedRows(): void
	{
		$store = $this->store();

		$page = $store->list('redo', 2, 1);
		$this->assertSame(3, $page['total']);
		$this->assertSame(['alice/files/file15.pdf', 'alice/files/file14.pdf'], array_column($page['rows'], 'path'));
	}

	/**
	 * [unit] An unknown verdict filter answers nothing rather than
	 * everything: a typo in a query string must not dump the whole ledger.
	 */
	public function testAnUnknownVerdictListsNothing(): void
	{
		$store = $this->store();

		$result = $store->list('nonsense', 100);
		$this->assertSame([], $result['rows']);
		$this->assertSame(0, $result['total']);
	}

	/**
	 * [unit] The price is exposed so the panel can show "~$X" BEFORE the
	 * click, from the same config the estimate uses.
	 */
	public function testThePriceIsExposedForThePanel(): void
	{
		$this->assertNull($this->store()->pricing()['per_1000_pages']);

		$priced = new StoreOutcomes(['redo_cost_per_1000_pages' => '1.50']);
		$this->assertSame(1.5, $priced->pricing()['per_1000_pages']);
	}
}
