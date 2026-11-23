<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

/**
 * The one place a code's words live.
 *
 * A code is a machine token; every surface that shows it to a person — the
 * panel blocks, the worklists, the drill-downs, the CLI — needs the same
 * sentence for it. Written down once here, because a word that is written in
 * three places becomes three words the first time one of them is edited.
 */
class OutcomeCatalog
{
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
	 * @var array<string, array{verdict: string, text: string, short: string, actor: string, fix?: string}>
	 */
	private const VOCAB = [
		// -- work ------------------------------------------------------------
		'needs_ocr' => [
			'verdict' => 'work',
			'text' => 'The file has no text layer yet.',
			'short' => 'Needs OCR',
			'actor' => 'worker',
		],

		// -- nothing_to_do ---------------------------------------------------
		'skip_text_native' => [
			'verdict' => 'nothing_to_do',
			'text' => 'The PDF already carries typed text of its own.',
			'short' => 'Has its own text layer',
			'actor' => 'nobody',
		],
		'skip_ocred_by_ocrflow' => [
			'verdict' => 'nothing_to_do',
			'text' => 'This file was already read and its result is at or above the engine bar.',
			'short' => 'Read by OcrFlow',
			'actor' => 'nobody',
		],
		'skip_classifier_says_photo' => [
			'verdict' => 'nothing_to_do',
			'text' => 'The classifier decided this image is a photo of a scene, not a document.',
			'short' => 'Photo, not a document',
			'actor' => 'nobody',
		],
		'skip_file_type_unsupported' => [
			'verdict' => 'nothing_to_do',
			'text' => 'This file type is not one the pipeline can read.',
			'short' => 'Format not read',
			'actor' => 'nobody',
		],
		'skip_ocred_by_ocrflow_found_nothing' => [
			'verdict' => 'nothing_to_do',
			'text' => 'The file was read and genuinely contains no text.',
			'short' => 'No text found',
			'actor' => 'nobody',
		],
		'error_provider_unsupported_file' => [
			'verdict' => 'nothing_to_do',
			'text' => 'This file type is not supported.',
			'short' => 'Format not supported',
			'actor' => 'nobody',
		],

		// -- redo (never automatic) -------------------------------------------
		'skip_ocred_by_suspected_foreign' => [
			'verdict' => 'redo',
			'text' => 'The text layer on this file was written by another tool and is not trusted.',
			'short' => 'Text by another tool',
			'actor' => 'owner',
		],

		// -- wait --------------------------------------------------------------
		'error_app_answered_5xx' => [
			'verdict' => 'wait',
			'text' => 'The Nextcloud app failed with a server error while the worker was talking to it.',
			'short' => 'App server error',
			'actor' => 'worker',
		],
		'error_app_did_not_answer' => [
			'verdict' => 'wait',
			'text' => 'The request to the Nextcloud app got no answer at all.',
			'short' => 'App did not answer',
			'actor' => 'worker',
		],
		'error_provider_unavailable' => [
			'verdict' => 'wait',
			'text' => 'The OCR service answered 5xx.',
			'short' => 'Engine unavailable',
			'actor' => 'worker',
		],
		'error_provider_timed_out' => [
			'verdict' => 'wait',
			'text' => 'The OCR service took too long to answer.',
			'short' => 'Engine timed out',
			'actor' => 'worker',
		],
		'error_provider_rate_limited' => [
			'verdict' => 'wait',
			'text' => 'The OCR service rate-limited the request.',
			'short' => 'Engine rate-limited',
			'actor' => 'worker',
		],
		'error_provider_quota_exhausted' => [
			'verdict' => 'wait',
			'text' => 'The OCR service is out of quota, and the file itself is fine.',
			'short' => 'Engine quota spent',
			'actor' => 'deployment',
		],
		'error_provider_budget_exhausted' => [
			'verdict' => 'wait',
			'text' => 'The OCR budget for this engine is spent.',
			'short' => 'Engine budget spent',
			'actor' => 'deployment',
		],
		'error_provider_local_quota_exhausted' => [
			'verdict' => 'wait',
			'text' => 'The local engine is out of quota.',
			'short' => 'Local engine quota spent',
			'actor' => 'deployment',
		],
		'error_provider_local_budget_exhausted' => [
			'verdict' => 'wait',
			'text' => 'The local engine\'s budget is spent.',
			'short' => 'Local engine budget spent',
			'actor' => 'deployment',
		],
		'error_worker_storage_failed' => [
			'verdict' => 'wait',
			'text' => 'A storage fault interrupted the worker before it could read the file.',
			'short' => 'Storage fault',
			'actor' => 'worker',
		],
		'error_worker_got_empty_file' => [
			'verdict' => 'wait',
			'text' => 'The file arrived at the worker with no bytes.',
			'short' => 'No bytes received',
			'actor' => 'worker',
		],

		// -- drop ------------------------------------------------------------
		// The file was deleted or moved after it was queued. No retry can
		// read a file that is not there, and nothing needs fixing: the work
		// is moot, and saying so is the honest answer.
		'error_worker_file_gone' => [
			'verdict' => 'drop',
			'text' => 'The file was deleted or moved after it was queued.',
			'short' => 'File moved or deleted',
			'actor' => 'nobody',
		],

		// -- needs_fix ----------------------------------------------------------
		'error_worker_secret_rejected' => [
			'verdict' => 'needs_fix',
			'text' => 'The app does not accept the secret the worker presents.',
			'short' => 'Worker secret rejected',
			'actor' => 'deployment',
		],
		'error_provider_permission_denied' => [
			'verdict' => 'needs_fix',
			'text' => 'The engine account lacks the permission the request needed.',
			'short' => 'Engine permission denied',
			'actor' => 'deployment',
		],
		'error_provider_credentials_missing' => [
			'verdict' => 'needs_fix',
			'text' => 'The engine has no credentials.',
			'short' => 'Engine credentials missing',
			'actor' => 'deployment',
		],
		'error_worker_request_malformed' => [
			'verdict' => 'needs_fix',
			'text' => 'The app refused the worker request as malformed (4xx).',
			'short' => 'Malformed worker request',
			'actor' => 'deployment',
		],
		'error_classifier_unavailable' => [
			'verdict' => 'needs_fix',
			'text' => 'The document classifier is not available, so images cannot be checked.',
			'short' => 'Classifier unavailable',
			'actor' => 'deployment',
		],
		'error_worker_unclassified' => [
			'verdict' => 'needs_fix',
			'text' => 'OCR failed without saying why.',
			'short' => 'OCR failed',
			'actor' => 'deployment',
		],
		'error_worker_sent_unknown_code' => [
			'verdict' => 'needs_fix',
			'text' => 'The worker reported a code this version of the app does not know.',
			'short' => 'Unknown worker code',
			'actor' => 'deployment',
		],
		'error_source_file_zero_bytes' => [
			'verdict' => 'needs_fix',
			'text' => 'This file is empty (0 bytes).',
			'short' => 'Empty file',
			'actor' => 'owner',
		],
		'error_result_write_failed' => [
			'verdict' => 'needs_fix',
			'text' => 'The result could not be saved back over the file.',
			'short' => 'Result not saved',
			'actor' => 'deployment',
		],
		'error_engine_output_unreadable' => [
			'verdict' => 'needs_fix',
			'text' => 'The parser could not make sense of what the engine returned.',
			'short' => 'Engine output unreadable',
			'actor' => 'deployment',
		],
		'error_file_broken' => [
			'verdict' => 'needs_fix',
			'text' => 'This file is damaged and cannot be read.',
			'short' => 'Damaged file',
			'actor' => 'owner',
		],
		'error_image_too_large' => [
			'verdict' => 'needs_fix',
			'text' => 'This image is too large to analyse.',
			'short' => 'Image too large',
			'actor' => 'owner',
		],
		'error_queued_twice_for_same_bytes' => [
			'verdict' => 'needs_fix',
			'text' => 'The scan met this exact file version a second time, and the first job left no verdict.',
			'short' => 'Queued twice for the same bytes',
			'actor' => 'deployment',
		],

		// -- redo ----------------------------------------------------------------
		'skip_source_changed_result_saved' => [
			'verdict' => 'redo',
			'text' => 'The source changed while OCR ran, so the result was kept aside.',
			'short' => 'Source changed mid-run',
			'actor' => 'owner',
		],

		// -- drop ----------------------------------------------------------------
		'error_worker_job_reference_stale' => [
			'verdict' => 'drop',
			'text' => 'A result arrived for a job that no longer exists.',
			'short' => 'Result for a missing job',
			'actor' => 'worker',
		],
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


	public const VERDICT_WORK = 'work';
	public const VERDICT_NOTHING_TO_DO = 'nothing_to_do';
	public const VERDICT_REDO = 'redo';
	public const VERDICT_WAIT = 'wait';
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
		return array_keys(self::VOCAB);
	}


/**
	 * The canonical (new) name for a code: old rows read through the alias
	 * map, new rows read as themselves, and a name nobody knows is recorded
	 * under the code that says so (version skew is a needs_fix, not a
	 * silence).
	 */
	public static function canonical(?string $code): string
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
	 * Which block a code belongs to.
	 *
	 * No code means a `done` row: the file was read. An `error_` code means the
	 * file was not read. Anything else unknown is `error` too — claiming "OCRed
	 * by us" for a word the app does not understand would be a lie in the one
	 * place that must not lie.
	 */
	public static function blockOf(?string $code): string
	{
		if ($code === null || $code === '') {
			return 'read';
		}
		$canonical = self::canonical($code);
		if (array_key_exists($canonical, self::CODE_BLOCKS)) {
			return self::CODE_BLOCKS[$canonical];
		}
		return 'error';
	}


	/**
	 * Everything a surface needs to say about one code.
	 *
	 * @return array{code: ?string, verdict: ?string, label: string, short: string, sentence: string, tooltip: string, block: string}
	 */
	public static function for(?string $code): array
	{
		$entry = $code === null ? null : (self::VOCAB[self::canonical($code)] ?? self::VOCAB['error_worker_sent_unknown_code']);
		return [
			'code' => $code,
			'verdict' => $entry['verdict'] ?? null,
			'label' => $entry['short'] ?? 'Unknown worker code',
			'short' => $entry['short'] ?? 'Unknown worker code',
			'sentence' => $entry['text'] ?? '',
			'tooltip' => $entry['text'] ?? '',
			'block' => self::blockOf($code),
		];
	}

	/**
	 * Who read the file, and with which version — one implementation for the
	 * read rows and the no-text rows, so they cannot drift apart in wording.
	 */
	public static function engine(?string $engine, ?string $version): string
	{
		$who = self::reader($engine);
		if ($version === null) {
			return $who . ', version not recorded';
		}

		return $who . ' ' . $version;
	}

	/**
	 * Who read the file, with no claim about the version.
	 */
	public static function reader(?string $engine): string
	{
		if ($engine === null) {
			return 'Engine not recorded';
		}

		return match (strtolower($engine)) {
			'google' => 'Google',
			'minimax' => 'MiniMax',
			'tesseract' => 'Tesseract',
			default => mb_strtoupper(mb_substr($engine, 0, 1)) . mb_substr($engine, 1),
		};
	}

	/**
	 * The verdict for a code, or null when there is no code. Unknown codes get
	 * the verdict of `error_worker_sent_unknown_code`.
	 */
	public static function verdict(?string $code): ?string
	{
		if ($code === null) {
			return null;
		}
		return self::VOCAB[self::canonical($code)]['verdict'] ?? null;
	}

	/**
	 * The raw vocabulary entry for a code, or null.
	 *
	 * @return array{verdict: string, text: string, short: string, actor: string}|null
	 */
	public static function entry(?string $code): ?array
	{
		if ($code === null) {
			return null;
		}
		return self::VOCAB[self::canonical($code)] ?? null;
	}

	/** The whole vocabulary, in declaration order. */
	public static function all(): array
	{
		return self::VOCAB;
	}

	/** Old name → new name, for rows written before 0.1.5. */
	public static function aliases(): array
	{
		return self::ALIASES;
	}
}
