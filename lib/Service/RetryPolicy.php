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
	 * The closed vocabulary: one entry per code, one verdict per code.
	 *
	 * verdict — the decision; label — one sentence a human reads;
	 * fix — one sentence that names the PROBLEM and stops there; actor —
	 * who is expected to act, for the admin surfaces.
	 *
	 * The fix hint diagnoses, never instructs. It sits in the personal panel
	 * beside buttons that really do things, so "Retry — the engine was down"
	 * reads as a second, dead button, and "the owner must re-upload" tells the
	 * reader to go and find an owner. The hint also cannot know who is reading
	 * it: the same row is an owner's worklist entry and an operator's. So no
	 * second person, no owner, no administrator, no verbs — the UI owns the
	 * actions. `tests/Unit/Service/RetryPolicyWordingTest.php` enforces it.
	 *
	 * @var array<string, array{verdict: string, label: string, fix: string, actor: string}>
	 */
	private const VOCAB = [
		// -- work ------------------------------------------------------------
		'needs_ocr' => [
			'verdict' => 'work',
			'text' => 'The file has no text layer yet.',
			'actor' => 'worker',
		],

		// -- nothing_to_do ---------------------------------------------------
		'skip_text_native' => [
			'verdict' => 'nothing_to_do',
			'text' => 'The PDF already carries typed text of its own.',
			'actor' => 'nobody',
		],
		'skip_ocred_by_ocrflow' => [
			'verdict' => 'nothing_to_do',
			'text' => 'This file was already read and its result is at or above the engine bar.',
			'actor' => 'nobody',
		],
		'skip_classifier_says_photo' => [
			'verdict' => 'nothing_to_do',
			'text' => 'The classifier decided this image is a photo of a scene, not a document.',
			'actor' => 'nobody',
		],
		'skip_file_type_unsupported' => [
			'verdict' => 'nothing_to_do',
			'text' => 'This file type is not one the pipeline can read.',
			'actor' => 'nobody',
		],
		'skip_ocred_by_ocrflow_found_nothing' => [
			'verdict' => 'nothing_to_do',
			'text' => 'The file was read and genuinely contains no text.',
			'actor' => 'nobody',
		],
		'error_provider_unsupported_file' => [
			'verdict' => 'nothing_to_do',
			'text' => 'This file type is not supported.',
			'actor' => 'nobody',
		],

		// -- redo (never automatic) -------------------------------------------
		'skip_ocred_by_suspected_foreign' => [
			'verdict' => 'redo',
			'text' => 'The text layer on this file was written by another tool and is not trusted.',
			'actor' => 'owner',
		],

		// -- wait --------------------------------------------------------------
		'error_app_answered_5xx' => [
			'verdict' => 'wait',
			'text' => 'The Nextcloud app failed with a server error while the worker was talking to it.',
			'actor' => 'worker',
		],
		'error_app_did_not_answer' => [
			'verdict' => 'wait',
			'text' => 'The request to the Nextcloud app got no answer at all.',
			'actor' => 'worker',
		],
		'error_provider_unavailable' => [
			'verdict' => 'wait',
			'text' => 'The OCR service answered 5xx.',
			'actor' => 'worker',
		],
		'error_provider_timed_out' => [
			'verdict' => 'wait',
			'text' => 'The OCR service took too long to answer.',
			'actor' => 'worker',
		],
		'error_provider_rate_limited' => [
			'verdict' => 'wait',
			'text' => 'The OCR service rate-limited the request.',
			'actor' => 'worker',
		],
		'error_provider_quota_exhausted' => [
			'verdict' => 'wait',
			'text' => 'The OCR service is out of quota, and the file itself is fine.',
			'actor' => 'deployment',
		],
		'error_provider_budget_exhausted' => [
			'verdict' => 'wait',
			'text' => 'The OCR budget for this engine is spent.',
			'actor' => 'deployment',
		],
		'error_provider_local_quota_exhausted' => [
			'verdict' => 'wait',
			'text' => 'The local engine is out of quota.',
			'actor' => 'deployment',
		],
		'error_provider_local_budget_exhausted' => [
			'verdict' => 'wait',
			'text' => 'The local engine\'s budget is spent.',
			'actor' => 'deployment',
		],
		'error_worker_storage_failed' => [
			'verdict' => 'wait',
			'text' => 'A storage fault interrupted the worker before it could read the file.',
			'actor' => 'worker',
		],
		'error_worker_got_empty_file' => [
			'verdict' => 'wait',
			'text' => 'The file arrived at the worker with no bytes.',
			'actor' => 'worker',
		],

		// -- drop ------------------------------------------------------------
		// The file was deleted or moved after it was queued. No retry can
		// read a file that is not there, and nothing needs fixing: the work
		// is moot, and saying so is the honest answer.
		'error_worker_file_gone' => [
			'verdict' => 'drop',
			'text' => 'The file was deleted or moved after it was queued.',
			'actor' => 'nobody',
		],

		// -- needs_fix ----------------------------------------------------------
		'error_worker_secret_rejected' => [
			'verdict' => 'needs_fix',
			'text' => 'The app does not accept the secret the worker presents.',
			'actor' => 'deployment',
		],
		'error_provider_permission_denied' => [
			'verdict' => 'needs_fix',
			'text' => 'The engine account lacks the permission the request needed.',
			'actor' => 'deployment',
		],
		'error_provider_credentials_missing' => [
			'verdict' => 'needs_fix',
			'text' => 'The engine has no credentials.',
			'actor' => 'deployment',
		],
		'error_worker_request_malformed' => [
			'verdict' => 'needs_fix',
			'text' => 'The app refused the worker request as malformed (4xx).',
			'actor' => 'deployment',
		],
		'error_classifier_unavailable' => [
			'verdict' => 'needs_fix',
			'text' => 'The document classifier is not available, so images cannot be checked.',
			'actor' => 'deployment',
		],
		'error_worker_unclassified' => [
			'verdict' => 'needs_fix',
			'text' => 'OCR failed without saying why.',
			'actor' => 'deployment',
		],
		'error_worker_sent_unknown_code' => [
			'verdict' => 'needs_fix',
			'text' => 'The worker reported a code this version of the app does not know.',
			'actor' => 'deployment',
		],
		'error_source_file_zero_bytes' => [
			'verdict' => 'needs_fix',
			'text' => 'This file is empty (0 bytes).',
			'actor' => 'owner',
		],
		'error_result_write_failed' => [
			'verdict' => 'needs_fix',
			'text' => 'The result could not be saved back over the file.',
			'actor' => 'deployment',
		],
		'error_engine_output_unreadable' => [
			'verdict' => 'needs_fix',
			'text' => 'The parser could not make sense of what the engine returned.',
			'actor' => 'deployment',
		],
		'error_file_broken' => [
			'verdict' => 'needs_fix',
			'text' => 'This file is damaged and cannot be read.',
			'actor' => 'owner',
		],
		'error_image_too_large' => [
			'verdict' => 'needs_fix',
			'text' => 'This image is too large to analyse.',
			'actor' => 'owner',
		],
		'error_queued_twice_for_same_bytes' => [
			'verdict' => 'needs_fix',
			'text' => 'The scan met this exact file version a second time, and the first job left no verdict.',
			'actor' => 'deployment',
		],

		// -- redo ----------------------------------------------------------------
		'skip_source_changed_result_saved' => [
			'verdict' => 'redo',
			'text' => 'The source changed while OCR ran, so the result was kept aside.',
			'actor' => 'owner',
		],

		// -- drop ----------------------------------------------------------------
		'error_worker_job_reference_stale' => [
			'verdict' => 'drop',
			'text' => 'A result arrived for a job that no longer exists.',
			'actor' => 'worker',
		],
	];

	/**
	 * Old name → new name, for reading rows written before 0.1.5. The ledger
	 * keeps its old names (no bulk UPDATE); a code written today resolves to
	 * itself, so this map is safe to leave on every read path.
	 *
	 * @var array<string, string>
	 */
	private const ALIASES = [
		'born_digital_text_based' => 'skip_text_native',
		'stamped_good_by_us' => 'skip_ocred_by_ocrflow',
		'mobilenet' => 'skip_classifier_says_photo',
		'unsupported_ext' => 'skip_file_type_unsupported',
		'bad_format' => 'skip_file_type_unsupported',
		'no_text_detected' => 'skip_ocred_by_ocrflow_found_nothing',
		'empty_source' => 'error_source_file_zero_bytes',
		'broken_pdf' => 'error_file_broken',
		'save_refused' => 'error_result_write_failed',
		'parse_produced_nothing' => 'error_engine_output_unreadable',
		'empty_stream' => 'error_worker_got_empty_file',
		'file_gone' => 'error_worker_file_gone',
		'classifier_unavailable' => 'error_classifier_unavailable',
		'image_too_large' => 'error_image_too_large',
		'credentials_missing' => 'error_provider_credentials_missing',
		'app_4xx' => 'error_worker_request_malformed',
		'app_5xx' => 'error_app_answered_5xx',
		'engine_4xx' => 'error_provider_unsupported_file',
		'engine_5xx' => 'error_provider_unavailable',
		'engine_timeout' => 'error_provider_timed_out',
		'storage_error' => 'error_worker_storage_failed',
		'worker_error' => 'error_worker_unclassified',
		'unknown' => 'error_worker_sent_unknown_code',
		'source_changed' => 'skip_source_changed_result_saved',
	];

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
		foreach (self::VOCAB as $code => $entry) {
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
		foreach (self::ALIASES as $old => $new) {
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
			&& (array_key_exists($code, self::VOCAB) || array_key_exists($code, self::ALIASES));
	}

	/**
	 * The canonical (new) name for a code: old rows read through the alias
	 * map, new rows read as themselves, and a name nobody knows is recorded
	 * under the code that says so (version skew is a needs_fix, not a
	 * silence).
	 */
	public function canonical(?string $code): string
	{
		if ($code === null) {
			return 'error_worker_sent_unknown_code';
		}
		if (array_key_exists($code, self::ALIASES)) {
			return self::ALIASES[$code];
		}
		if (array_key_exists($code, self::VOCAB)) {
			return $code;
		}
		return 'error_worker_sent_unknown_code';
	}

	/**
	 * The verdict for a code, or null when there is no code. Unknown codes
	 * get the verdict of `error_worker_sent_unknown_code`.
	 */
	public function verdict(?string $code): ?string
	{
		if ($code === null) {
			return null;
		}
		return self::VOCAB[$this->canonical($code)]['verdict'] ?? null;
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
	 * Codes whose block cannot be read off the `error_` prefix.
	 *
	 * @var array<string, string>
	 */
	private const CODE_BLOCKS = [
		'skip_text_native' => 'born_digital',
		'skip_classifier_says_photo' => 'image',
		'skip_ocred_by_ocrflow' => 'read',
		'skip_ocred_by_ocrflow_found_nothing' => 'read',
		'skip_file_type_unsupported' => 'error',
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
		if ($code === null || $code === '') {
			return 'read';
		}
		$canonical = $this->canonical($code);
		if (array_key_exists($canonical, self::CODE_BLOCKS)) {
			return self::CODE_BLOCKS[$canonical];
		}
		return 'error';
	}

	/**
	 * The whole entry: verdict, label, fix hint, actor.
	 *
	 * @return array{verdict: string, label: string, fix: string, actor: string}|null
	 */
	public function entry(?string $code): ?array
	{
		if ($code === null) {
			return null;
		}
		return self::VOCAB[$this->canonical($code)] ?? null;
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
		foreach (self::VOCAB as $code => $entry) {
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
