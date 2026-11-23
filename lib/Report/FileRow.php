<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Report;

use OCA\OcrFlow\Service\OutcomeCatalog;

/**
 * One per-file row, whatever door showed it.
 *
 * The worklists (`/mine`), the attention list and a block's drill-down all say
 * the same thing about the same file: its id, its path, one human sentence, the
 * worker's prose, how many times it was tried. Built in one place so the three
 * doors cannot grow three shapes — which is exactly how "not processed yet 10
 * vs 30" happened.
 *
 * Extra keys are added, never renamed: every surface keeps the keys it reads.
 */
class FileRow
{
	/**
	 * @param array<string, mixed> $raw a job row, a ledger row, or a join of the two
	 * @return array<string, mixed>
	 */
	public static function of(array $raw, ?string $origin = null, ?bool $retryable = null, ?int $errorMax = null): array
	{
		$code = isset($raw['code']) && is_string($raw['code'])
			? $raw['code']
			: self::codeOf($raw);
		$entry = OutcomeCatalog::entry($code);
		$error = isset($raw['error']) && is_string($raw['error']) && $raw['error'] !== '' ? $raw['error'] : null;

		$finished = isset($raw['finished_at']) && $raw['finished_at'] !== null ? (int) $raw['finished_at'] : null;

		return [
			// The id the retry door accepts: a job id for a needs_fix row,
			// a Decide row id for a redo row.
			'id' => (int) ($raw['id'] ?? 0),
			// The FILE the row is about. Open and Delete take this one.
			'file_id' => (int) ($raw['file_id'] ?? 0),
			'path' => (string) ($raw['path'] ?? ''),
			'code' => $code === null ? '' : OutcomeCatalog::canonical($code),
			// One human sentence, never two: the row's own text when the
			// door already computed one, the vocabulary's sentence otherwise.
			'text' => isset($raw['text']) && is_string($raw['text']) && $raw['text'] !== ''
				? $raw['text']
				: ($entry['text'] ?? 'OCR failed; the app recorded no code for it.'),
			// The worker's own words, when there are any: an unclassified
			// failure is not a mystery if the exception text is in the row.
			'error' => self::truncate($error, $errorMax),
			'attempts' => isset($raw['attempts']) && $raw['attempts'] !== null ? (int) $raw['attempts'] : null,
			'finished_at' => $finished,
			'age' => $finished === null ? null : max(0, time() - $finished),
			'origin' => $origin,
			'kind' => (($raw['verdict'] ?? null) === OutcomeCatalog::VERDICT_REDO) ? 'redo' : 'needs_fix',
			'retryable' => $retryable,
		];
	}

	/**
	 * The code a raw row records: `reason_code` where there is one, the old
	 * `reason` spelling where the row predates 0.1.5, and nothing when neither
	 * carries a code.
	 *
	 * @param array<string, mixed> $raw
	 */
	private static function codeOf(array $raw): ?string
	{
		$reasonCode = isset($raw['reason_code']) && is_string($raw['reason_code']) && $raw['reason_code'] !== ''
			? $raw['reason_code']
			: null;
		$reason = isset($raw['reason']) && is_string($raw['reason']) && $raw['reason'] !== ''
			? $raw['reason']
			: null;

		return $reasonCode ?? $reason;
	}

	private static function truncate(?string $error, ?int $errorMax): ?string
	{
		// Prose can be a full exception trace; nobody needs 50 KB of it in a
		// table row. The attention list caps it; the worklists do not.
		if ($error === null || $errorMax === null) {
			return $error;
		}

		return mb_strlen($error) > $errorMax
			? mb_substr($error, 0, $errorMax) . '…'
			: $error;
	}
}
