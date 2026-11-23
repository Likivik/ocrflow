<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

/**
 * The one outcomes table.
 *
 * The worker reports what happened; this class decides what that means. Every
 * code in the closed vocabulary carries exactly one verdict, one sentence a
 * human can read, one fix hint that says what to do, and the actor expected
 * to do it. The verdicts are the plan's six:
 *
 *   work          — the file needs OCR; queue it.
 *   nothing_to_do — the file is fine as it is; nobody must be told.
 *   redo          — the text layer exists but is not good enough; a redo is
 *                   a candidate the owner picks from, never automatic.
 *   wait          — a transient fault; the retry ceiling bounds it.
 *   needs_fix     — a deployment or worker problem a human must fix; the row
 *                   is the worklist.
 *   drop          — the job itself no longer exists; discard the result.
 *
 * Names come from the approved outcomes plan (2026-11-12); do not invent or
 * rename one — `tests/Unit/Service/RetryPolicyOutcomesTest.php` is the drift
 * guard and fails on any code without a verdict.
 *
 * Old rows keep their old names: the 7,090 ledger rows and 8,131 job rows
 * written before 0.1.5 are read through the alias map and written only in
 * the new vocabulary, with no bulk UPDATE — the audits already measured that
 * history, and rewriting it is not ours to do.
 */
final class RetryPolicy
{
	public const CODE_UNKNOWN = 'unknown';

	/**
	 * The canonical name for the fact that the worker reported a code this
	 * app does not know. The write path records THIS name (never the legacy
	 * `unknown` spelling), so the row's verdict -- needs_fix, not a retry --
	 * decides its fate: a version skew cannot be fixed by a second attempt.
	 */
	public const CODE_WORKER_SENT_UNKNOWN = 'error_worker_sent_unknown_code';

	public const VERDICT_WORK = 'work';
	public const VERDICT_NOTHING_TO_DO = 'nothing_to_do';
	public const VERDICT_REDO = 'redo';
	public const VERDICT_WAIT = 'wait';

	/** Both answers a reader acts on: needs_fix plus wait. */
	public const VERDICT_ACTIONABLE = 'actionable';
	public const VERDICT_NEEDS_FIX = 'needs_fix';
	public const VERDICT_DROP = 'drop';

	
	/**
	 * Every code this version knows, in declaration order.
	 *
	 * `VOCAB` stays private: the codes are a public vocabulary, the table is
	 * not. A test that promises "every code has a short form" needs the list,
	 * and this is the smallest door that gives it one.
	 *
	 * @return list<string>
	 */
	public static function codes(): array
	{
		return OutcomeCatalog::codes();
	}

	
	/**
	 * The retry answers of the pre-0.1.5 table, frozen. `isRetryable()` is
	 * the derived helper the whole retry machinery rides on, and this PR
	 * does not change behaviour — `empty_stream` (now wait) and `unknown`
	 * (now needs_fix) deliberately keep their old retry answers; moving
	 * them is a decision for a PR that changes retry behaviour, not one
	 * that renames codes.
	 *
	 * @var array<string, bool>
	 */
	private const LEGACY_RETRYABLE = [
		'no_text_detected' => false,
		'parse_produced_nothing' => false,
		'save_refused' => false,
		'broken_pdf' => false,
		'bad_format' => false,
		'empty_source' => false,
		'source_changed' => false,
		'born_digital_text_based' => false,
		'mobilenet' => false,
		'stamped_good_by_us' => false,
		'empty_stream' => false,
		'credentials_missing' => false,
		'app_4xx' => false,
		'engine_4xx' => false,
		'app_5xx' => true,
		'engine_5xx' => true,
		'engine_timeout' => true,
		'storage_error' => true,
		self::CODE_UNKNOWN => true,
	];

	/**
	 * Reason codes that are PERMANENT: retrying the SAME BYTES can never
	 * succeed, so after retention purges the job row, `Queue::enqueue()`
	 * refuses to re-queue the file while its etag is unchanged.
	 *
	 * New names (the alias map carries the old spellings still read).
	 * A code is only here when the failure is a property of the bytes
	 * themselves; ambiguous codes fail open toward retrying.
	 *
	 * @var list<string>
	 */
	private const PERMANENT = [
		'skip_ocred_by_ocrflow_found_nothing',
		'error_engine_output_unreadable',
		'error_result_write_failed',
		'error_file_broken',
		'skip_file_type_unsupported',
		'error_source_file_zero_bytes',
	];

	/**
	 * The codes (new names only) whose verdict is $verdict.
	 *
	 * @return list<string>
	 */
	public function codesForVerdict(string $verdict): array
	{
		$codes = [];
		foreach (OutcomeCatalog::all() as $code => $entry) {
			if ($entry['verdict'] === $verdict) {
				$codes[] = $code;
			}
		}
		return $codes;
	}

	/**
	 * Every spelling that reads as one of these codes: the code itself plus
	 * the pre-0.1.5 names that resolve to it. SQL sets are flat lists of
	 * strings, so both eras must be in one list — old rows keep their old
	 * names by design.
	 *
	 * @param list<string> $codes
	 * @return list<string>
	 */
	public function spellingsFor(array $codes): array
	{
		$out = $codes;
		foreach (OutcomeCatalog::aliases() as $old => $new) {
			if (in_array($new, $codes, true)) {
				$out[] = $old;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Every spelling (current name and pre-0.1.5 aliases) of every code whose
	 * verdict is $verdict. The retention sweep and the attention worklist
	 * both need this: one asks "which rows must survive", the other "which
	 * rows are the operator's queue".
	 *
	 * @return list<string>
	 */
	public function spellingsForVerdict(string $verdict): array
	{
		// The panel's one list holds two different answers: rows a human must
		// repair (needs_fix) and rows a retry can still fix (wait). Listing
		// only the first is what made Retry invisible — the button appears on
		// the rows isRetryable() approves, and none of those were listed.
		if ($verdict === self::VERDICT_ACTIONABLE) {
			return array_values(array_unique(array_merge(
				$this->spellingsFor($this->codesForVerdict(self::VERDICT_NEEDS_FIX)),
				$this->spellingsFor($this->codesForVerdict(self::VERDICT_WAIT)),
			)));
		}
		return $this->spellingsFor($this->codesForVerdict($verdict));
	}

	/**
	 * Every spelling of ONE code: the code itself plus its pre-0.1.5
	 * aliases. The worklist filter matches stored spellings, so filtering
	 * by the new name must also find the rows stored under the old one.
	 *
	 * @return list<string>
	 */
	public function spellingsOf(string $code): array
	{
		return $this->spellingsFor([$this->canonical($code)]);
	}

	public function isKnown(?string $code): bool
	{
		return $code !== null
			&& (array_key_exists($code, OutcomeCatalog::all()) || array_key_exists($code, OutcomeCatalog::aliases()));
	}

	/**
	 * The canonical (new) name for a code: old rows read through the alias
	 * map, new rows read as themselves, and a name nobody knows is recorded
	 * under the code that says so (version skew is a needs_fix, not a
	 * silence).
	 */
	public function canonical(?string $code): string
	{
		return OutcomeCatalog::canonical($code);
	}

	/**
	 * The verdict for a code, or null when there is no code. Unknown codes
	 * get the verdict of `error_worker_sent_unknown_code`.
	 */
	public function verdict(?string $code): ?string
	{
		return OutcomeCatalog::verdict($code);
	}

	/**
	 * The report blocks, in the order the panel shows them.
	 *
	 * A block is a statement about the FILE, not about a run: "OCRed by OcrFlow" is
	 * every file we read, whichever row did it and however many rows it took.
	 *
	 * @var array<string, string>
	 */
	public const BLOCKS = [
		'not_processed' => 'Not processed yet',
		'queue' => 'In the queue',
		'read' => 'OCRed by OcrFlow',
		'born_digital' => 'Born digital',
		'image' => 'Classified as image',
		'error' => 'Can\'t read',
	];

	
	/**
	 * Which block a code belongs to.
	 *
	 * No code means a `done` row: the file was read. An `error_` code means the
	 * file was not read. Anything else unknown is `error` too — claiming "OCRed
	 * by us" for a word the app does not understand would be a lie in the one
	 * place that must not lie.
	 */
	public function blockOf(?string $code): string
	{
		return OutcomeCatalog::blockOf($code);
	}

	/**
	 * The whole entry: verdict, label, fix hint, actor.
	 *
	 * @return array{verdict: string, text: string, short: string, actor: string, fix?: string}|null
	 */
	public function entry(?string $code): ?array
	{
		return OutcomeCatalog::entry($code);
	}

	public function isRetryable(?string $code): bool
	{
		if ($code === null) {
			return false;
		}
		// Frozen answers for the codes the old table knew: retry behaviour
		// must not move in the PR that renames codes.
		if (array_key_exists($code, self::LEGACY_RETRYABLE)) {
			return self::LEGACY_RETRYABLE[$code];
		}
		// New codes derive from the verdict: only `wait` plausibly changes
		// with a retry. An unrecognised code is neither in the legacy table
		// nor in the vocabulary: bounded retry, never a permanent freeze
		// (the old fail-open rule, kept).
		return $this->isKnown($code)
			? $this->verdict($code) === self::VERDICT_WAIT
			: true;
	}

	/**
	 * The app's decision, plus whether the worker's hint agreed with it.
	 *
	 * The hint is recorded and logged, never obeyed: policy has one owner, and
	 * two owners is how behaviour drifts between environments.
	 *
	 * @return array{retryable: bool, agrees: ?bool}
	 */
	public function decide(?string $code, ?bool $hint): array
	{
		$retryable = $this->isRetryable($code);
		return [
			'retryable' => $retryable,
			'agrees' => $hint === null ? null : $hint === $retryable,
		];
	}

	/**
	 * The code for a classifier verdict, or null when the text is not one.
	 *
	 * The worker may report a verdict either as a code or as the free-text
	 * reason it already used. Both must land on the same code. Old prose-era
	 * names map to their new codes; new names pass through unchanged.
	 */
	public function verdictCode(?string $reason): ?string
	{
		if ($reason === null) {
			return null;
		}
		return match ($reason) {
			'born_digital_text_based' => 'skip_text_native',
			'mobilenet' => 'skip_classifier_says_photo',
			'stamped_good_by_us' => 'skip_ocred_by_ocrflow',
			'skip_text_native', 'skip_ocred_by_ocrflow', 'skip_classifier_says_photo',
			'skip_ocred_by_suspected_foreign' => $reason,
			default => null,
		};
	}

	/**
	 * The vocabulary, for the drift tripwire in the tests and the admin
	 * surface: code → verdict.
	 *
	 * @return array<string, string>
	 */
	public function vocabulary(): array
	{
		$out = [];
		foreach (OutcomeCatalog::all() as $code => $entry) {
			$out[$code] = $entry['verdict'];
		}
		return $out;
	}

	/**
	 * True when a failure with this code can never succeed for the same
	 * bytes. Old spellings read through the alias map; unknown or absent
	 * codes are never permanent — the block must fail open toward retrying.
	 */
	public function isPermanent(?string $code): bool
	{
		return $code !== null && in_array($this->canonical($code), self::PERMANENT, true);
	}

	/**
	 * The permanent set, for the drift tripwire in the tests.
	 *
	 * @return list<string>
	 */
	public function permanentCodes(): array
	{
		return self::PERMANENT;
	}
}
