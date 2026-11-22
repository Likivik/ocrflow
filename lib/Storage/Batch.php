<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Storage;

use OCP\IConfig;

/**
 * Who the "process N files" button may take, and in what order.
 *
 * A candidate is a file on disk with no readable outcome and no live job: not
 * the block count (that includes files still settling), and not a re-OCR — a
 * file whose result merely went missing is Retry material, not batch material.
 *
 * Read-only. The queueing itself is the caller's, because a selector that
 * writes is a selector you cannot test without a database.
 */
class Batch
{
	public function __construct(
		private DiskFiles $disk,
		private Ledger $ledger,
		private JobStore $jobs,
		private ?IConfig $config = null,
	) {
	}

	public function neverProcessedFor(string $uid, int $limit): array
	{
		$limit = max(1, $limit);
		$ids = array_keys($this->disk->ids($uid));
		if ($ids === []) {
			return ['ready' => [], 'readyTotal' => 0, 'young' => 0];
		}

		$ledger = $this->ledger->ledgerRowsFor($ids);
		$live = $this->jobs->liveQueueFor($ids);

		$ghosts = [];
		$plain = [];
		foreach ($ids as $fileId) {
			if (isset($live[$fileId])) {
				continue;
			}
			if (!isset($ledger[$fileId])) {
				$plain[$fileId] = true;
				continue;
			}
			// A ledger row means settled — except `file_gone`, which means the
			// file was not there to read and is here now.
			if (($ledger[$fileId]['reason'] ?? '') === 'error_worker_file_gone') {
				$ghosts[$fileId] = true;
			}
		}

		$candidates = array_keys($plain + $ghosts);
		if ($candidates === []) {
			return ['ready' => [], 'readyTotal' => 0, 'young' => 0];
		}

		$rows = $this->disk->rows($candidates);
		$cutoff = time() - $this->scanMinAgeSeconds();

		$ready = [];
		$young = 0;
		foreach ($rows as $row) {
			if ((int) $row['mtime'] > $cutoff) {
				$young++;
				continue;
			}
			$ready[] = $row;
		}
		usort($ready, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

		$readyTotal = count($ready);
		$ready = array_slice($ready, 0, $limit);

		$jobIds = [];
		$ghostIds = array_values(array_intersect(array_column($ready, 'fileId'), array_keys($ghosts)));
		if ($ghostIds !== []) {
			$jobIds = $this->jobs->terminalJobIdsFor($ghostIds);
		}

		$out = [];
		foreach ($ready as $row) {
			$fileId = (int) $row['fileId'];
			$out[] = [
				'fileId' => $fileId,
				'jobId' => $jobIds[$fileId] ?? null,
				'path' => (string) $row['path'],
				'size' => (int) $row['size'],
				'etag' => (string) $row['etag'],
			];
		}

		return ['ready' => $out, 'readyTotal' => $readyTotal, 'young' => $young];
	}

	protected function scanMinAgeSeconds(): int
	{
		if ($this->config === null) {
			return 120;
		}
		$raw = trim((string) $this->config->getAppValue('ocrflow', 'scan_min_age_seconds', '120'));
		return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : 120;
	}
}
