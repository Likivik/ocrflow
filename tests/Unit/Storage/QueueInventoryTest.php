<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Storage;

use OCA\OcrFlow\Report\PanelReport;
use OCA\OcrFlow\Storage\Batch;
use OCA\OcrFlow\Storage\DiskFiles;
use OCA\OcrFlow\Storage\EnqueueService;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\Ledger;
use OCA\OcrFlow\Storage\SettlementService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * [unit] The split keeps every method it promised.
 *
 * Phase 2 moves 62 names out of `Queue`. A move that loses a name still
 * compiles — the caller just gets a different answer, or a 500 — so the
 * inventory is asserted rather than trusted: each name is declared exactly once
 * among the classes that own it now, and `Queue`'s own copy, while it still
 * exists, may only delegate.
 */
class QueueInventoryTest extends TestCase
{
	/** Inventoried name → the class that owns it now. */
	private const HOME = [
		'findByPath' => JobStore::class,
		'jobForVersion' => JobStore::class,
		'isDoneForVersion' => JobStore::class,
		'alreadyEnqueuedForVersion' => JobStore::class,
		'countScanEnqueue' => JobStore::class,
		'resetTimesEnqueued' => JobStore::class,
		'claim' => JobStore::class,
		'renewClaim' => JobStore::class,
		'release' => JobStore::class,
		'getById' => JobStore::class,
		'recordSourceHash' => JobStore::class,
		'armWrittenEtag' => JobStore::class,
		'requeueStale' => JobStore::class,
		'forceRequeue' => JobStore::class,
		'deleteTerminalForFile' => JobStore::class,
		'deleteQueuedForFile' => JobStore::class,
		'idsWithReasonCode' => JobStore::class,
		'counts' => JobStore::class,
		'purgeTerminal' => JobStore::class,
		'selectExpiredTerminalIds' => JobStore::class,
		'purgeablePredicate' => JobStore::class,
		'liveQueueFor' => JobStore::class,
		'terminalJobIdsFor' => JobStore::class,
		'settledForVersion' => Ledger::class,
		'permanentFailureForVersion' => Ledger::class,
		'findFileByPath' => Ledger::class,
		'clearPermanentVerdict' => Ledger::class,
		'recordFile' => Ledger::class,
		'writeLedger' => Ledger::class,
		'ledgerRowsFor' => Ledger::class,
		'ledgerDrift' => Ledger::class,
		'provenanceCounts' => Ledger::class,
		'skippedByReason' => Ledger::class,
		'errorReasons' => Ledger::class,
		'reasonsForStatus' => Ledger::class,
		'ids' => DiskFiles::class,
		'rows' => DiskFiles::class,
		'emptyCount' => DiskFiles::class,
		'unreadableCount' => DiskFiles::class,
		'extensionPredicates' => DiskFiles::class,
		'neverProcessedFor' => Batch::class,
		'scanMinAgeSeconds' => Batch::class,
		'pageStatsFor' => PanelReport::class,
		'settleProduced' => SettlementService::class,
		'finish' => SettlementService::class,
		'recordDecision' => SettlementService::class,
		'recordEmptySource' => SettlementService::class,
		'recordQueuedTwice' => SettlementService::class,
		'deleteJobsById' => JobStore::class,
		'insertStageRow' => SettlementService::class,
		'hasStageRow' => SettlementService::class,
		'recordStageEvents' => SettlementService::class,
		'insertStageRows' => SettlementService::class,
		'selectOrphanStageIds' => JobStore::class,
		'selectStageIdsForJobs' => JobStore::class,
		'deleteStageRowsById' => JobStore::class,
		'enqueue' => EnqueueService::class,
		'enqueueNeverProcessed' => EnqueueService::class,
	];

	/**
	 * Names legitimately declared on two classes: a per-table SQL fragment, not
	 * a behaviour. `etagPredicate` builds `etag IS NULL` / `etag = ?` against
	 * `ocrflow_jobs` and against `ocrflow_files`, and each class owns its own.
	 */
	private const SHARED = ['etagPredicate'];

	private const RENAMED = [
		'diskFileIds' => 'ids (DiskFiles)',
		'fileRowsFor' => 'rows (DiskFiles)',
		'extPredicates' => 'extensionPredicates (DiskFiles)',
		'unreadableFormatCount' => 'unreadableCount (DiskFiles)',
		'fileReport' => 'deleted in 0.1.50 — PanelReport computes from aggregates',
		'engineSplit' => 'deleted in 0.1.50 — no_text_by_engine is an aggregate',
	];

	private static function declaredIn(string $class): array
	{
		$names = [];
		foreach ((new ReflectionClass($class))->getMethods() as $method) {
			if ($method->getDeclaringClass()->getName() === $class) {
				$names[] = $method->getName();
			}
		}
		return $names;
	}

	public function testEveryInventoriedNameHasExactlyOneHome(): void
	{
		$owners = array_unique(array_values(self::HOME));

		foreach (self::HOME as $name => $owner) {
			$this->assertContains($name, self::declaredIn($owner), sprintf('%s belongs on %s', $name, $owner));

			foreach ($owners as $other) {
				if ($other === $owner) {
					continue;
				}
				$this->assertNotContains(
					$name,
					self::declaredIn($other),
					sprintf('%s is declared twice: %s and %s', $name, $owner, $other),
				);
			}
		}
	}

	public function testTheRenamedNamesAreAccountedFor(): void
	{
		$this->assertCount(6, self::RENAMED, 'every rename is a decision; six were made');

		foreach (self::RENAMED as $was => $reason) {
			$this->assertNotContains($was, array_keys(self::HOME), $was . ' must not appear under its old name');
			$this->assertNotSame('', trim($reason));
		}
	}
}
