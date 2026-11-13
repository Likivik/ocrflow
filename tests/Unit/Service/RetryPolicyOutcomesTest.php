<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The closed outcomes vocabulary: one code, one verdict, one sentence a human
 * can act on. Every name here was chosen in review (plan of 2026-11-12); the
 * test is the drift guard — a code added without a verdict, or a verdict whose
 * set is not disjoint, fails here and not in production prose.
 */
class RetryPolicyOutcomesTest extends TestCase
{
	private RetryPolicy $policy;

	/** The full code list, grouped by verdict exactly as the plan states it. */
	private const BY_VERDICT = [
		'work' => ['needs_ocr'],
		'nothing_to_do' => [
			'skip_text_native',
			'skip_ocred_by_ocrflow',
			'skip_classifier_says_photo',
			'skip_file_type_unsupported',
			'skip_ocred_by_ocrflow_found_nothing',
			'error_provider_unsupported_file',
		],
		'redo' => [
			'skip_ocred_by_suspected_foreign',
			'skip_source_changed_result_saved',
		],
		'wait' => [
			'error_app_answered_5xx',
			'error_app_did_not_answer',
			'error_provider_unavailable',
			'error_provider_timed_out',
			'error_provider_rate_limited',
			'error_provider_quota_exhausted',
			'error_provider_budget_exhausted',
			'error_provider_local_quota_exhausted',
			'error_provider_local_budget_exhausted',
			'error_worker_storage_failed',
			'error_worker_got_empty_file',
		],
		'needs_fix' => [
			'error_worker_secret_rejected',
			'error_provider_permission_denied',
			'error_provider_credentials_missing',
			'error_worker_request_malformed',
			'error_worker_unclassified',
			'error_worker_sent_unknown_code',
			'error_source_file_zero_bytes',
			'error_result_write_failed',
			'error_engine_output_unreadable',
			'error_file_broken',
			'error_queued_twice_for_same_bytes',
		],
		'drop' => ['error_worker_job_reference_stale'],
	];

	protected function setUp(): void
	{
		$this->policy = new RetryPolicy();
	}

	/**
	 * @return list<string>
	 */
	private function allCodes(): array
	{
		$codes = [];
		foreach (self::BY_VERDICT as $list) {
			foreach ($list as $code) {
				$codes[] = $code;
			}
		}
		return $codes;
	}

	public function testEveryPlannedCodeIsKnownAndCarriesItsVerdict(): void
	{
		foreach ($this->allCodes() as $code) {
			$this->assertTrue($this->policy->isKnown($code), $code . ' must be in the vocabulary');
			$this->assertNotNull($this->policy->verdict($code), $code . ' must have a verdict');
		}
	}

	public function testTheVerdictSetsAreDisjointAndComplete(): void
	{
		$codes = $this->allCodes();
		$this->assertSame($codes, array_values(array_unique($codes)), 'no code may appear under two verdicts');
		$this->assertSame(
			sort($codes),
			sort(array_keys($this->policy->vocabulary())),
			'the vocabulary is exactly the planned code list — a code without a verdict fails here',
		);
	}

	public function testEveryEntryCarriesALabelAFixAndAnActor(): void
	{
		foreach ($this->allCodes() as $code) {
			$entry = $this->policy->entry($code);
			$this->assertNotNull($entry, $code);
			$this->assertSame($this->verdictOf($code), $entry['verdict'], $code . ' verdict');
			$this->assertNotSame('', trim((string) $entry['label']), $code . ' needs a one-sentence label');
			$this->assertNotSame('', trim((string) $entry['fix']), $code . ' needs a fix hint');
			$this->assertNotSame('', trim((string) $entry['actor']), $code . ' needs an actor');
			$this->assertContains($entry['verdict'], [
				'work', 'nothing_to_do', 'redo', 'wait', 'needs_fix', 'drop',
			], $code . ' carries an unknown verdict');
		}
	}

	private function verdictOf(string $code): string
	{
		foreach (self::BY_VERDICT as $verdict => $list) {
			if (in_array($code, $list, true)) {
				return $verdict;
			}
		}
		$this->fail($code . ' is not in the planned sets');
	}

	public function testEveryCodeMapsToExactlyThePlannedVerdict(): void
	{
		foreach ($this->allCodes() as $code) {
			$this->assertSame($this->verdictOf($code), $this->policy->verdict($code), $code);
		}
	}

	/**
	 * `isRetryable()` is the derived helper every existing caller rides on:
	 * the retry decision for every code the old table knew must not move in
	 * this PR. `empty_stream` and `unknown` deliberately keep their old answers
	 * even though their new verdicts differ — changing them is a behaviour
	 * change this PR does not make.
	 */
	public function testIsRetryableIsUnchangedForEveryOldCode(): void
	{
		$expected = [
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
			'unknown' => true,
		];
		foreach ($expected as $code => $retryable) {
			$this->assertSame($retryable, $this->policy->isRetryable($code), $code . ' retryable must not move');
		}
	}

	public function testNewCodesDeriveRetryableFromTheVerdict(): void
	{
		foreach (self::BY_VERDICT['wait'] as $code) {
			$this->assertTrue($this->policy->isRetryable($code), $code . ' is wait: the retry is bounded and plausible');
		}
		foreach (self::BY_VERDICT['nothing_to_do'] as $code) {
			$this->assertFalse($this->policy->isRetryable($code), $code . ' is nothing_to_do');
		}
		$this->assertFalse($this->policy->isRetryable('error_worker_job_reference_stale'));
		$this->assertFalse($this->policy->isRetryable('error_source_file_zero_bytes'));
	}

	/**
	 * The 7,090 ledger rows keep their old names: read old, write new. Every
	 * alias must resolve to the planned new code, and a name nobody knows must
	 * resolve safely (never a crash, never a silent pass-through as a verdict).
	 */
	public function testEveryOldAliasResolvesToThePlannedNewCode(): void
	{
		$expected = [
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
			'credentials_missing' => 'error_provider_credentials_missing',
			'app_4xx' => 'error_worker_request_malformed',
			'app_5xx' => 'error_app_answered_5xx',
			'engine_4xx' => 'error_provider_unsupported_file',
			'engine_5xx' => 'error_provider_unavailable',
			'engine_timeout' => 'error_provider_timed_out',
			'storage_error' => 'error_worker_storage_failed',
			'worker_error' => 'error_worker_unclassified',
			'unknown' => 'error_worker_sent_unknown_code',
		];
		foreach ($expected as $old => $new) {
			$this->assertSame($new, $this->policy->canonical($old), $old . ' must read as ' . $new);
			$this->assertTrue($this->policy->isKnown($old), $old . ' must stay known');
		}
	}

	/**
	 * The write path records a freshly-unknown code under its canonical name
	 * `error_worker_sent_unknown_code`, so the verdict -- not the frozen
	 * legacy answer for the `unknown` spelling -- decides the row: a version
	 * skew is a needs_fix, never a retry. Rows that genuinely stored the old
	 * spellings keep their frozen answers.
	 */
	public function testTheCanonicalUnknownCodeIsNotRetryableWhileLegacySpellingsKeepTheirFrozenAnswers(): void
	{
		$this->assertFalse(
			$this->policy->isRetryable('error_worker_sent_unknown_code'),
			'the canonical unknown code carries the needs_fix verdict and must not retry',
		);
		$this->assertSame('needs_fix', $this->policy->verdict('error_worker_sent_unknown_code'));

		// The frozen legacy answers, pinned here as regression guards.
		foreach ([
			'app_5xx' => true,
			'engine_timeout' => true,
			'empty_stream' => false,
			'broken_pdf' => false,
			'save_refused' => false,
			'unknown' => true,
		] as $code => $retryable) {
			$this->assertSame($retryable, $this->policy->isRetryable($code), $code . ' keeps its frozen legacy answer');
		}

		// The read paths for legacy rows are unchanged.
		$this->assertFalse($this->policy->isPermanent('unknown'));
		$this->assertSame('error_worker_sent_unknown_code', $this->policy->canonical('unknown'));
	}

	public function testAnUnknownNameResolvesSafely(): void
	{
		// Unknown: still a bounded retry (the old fail-open rule), but the
		// verdict it is recorded under names the version skew.
		$this->assertFalse($this->policy->isKnown('made_up_fault'));
		$this->assertTrue($this->policy->isRetryable('made_up_fault'));
		$this->assertSame('error_worker_sent_unknown_code', $this->policy->canonical('made_up_fault'));
		$this->assertSame('needs_fix', $this->policy->verdict('made_up_fault'));
	}

	public function testAliasesDoNotShadowNewCodes(): void
	{
		foreach ($this->allCodes() as $code) {
			$this->assertSame($code, $this->policy->canonical($code), $code . ' is already canonical');
		}
	}

	public function testPermanentFailureStillAnswersForOldAndNewNames(): void
	{
		// The byte-level verdicts the enqueue dedupe rides on.
		foreach (['no_text_detected', 'parse_produced_nothing', 'save_refused', 'broken_pdf', 'bad_format', 'empty_source'] as $old) {
			$this->assertTrue($this->policy->isPermanent($old), $old . ' stays permanent under its old name');
		}
		foreach ([
			'skip_ocred_by_ocrflow_found_nothing', 'error_engine_output_unreadable', 'error_result_write_failed',
			'error_file_broken', 'skip_file_type_unsupported', 'error_source_file_zero_bytes',
		] as $new) {
			$this->assertTrue($this->policy->isPermanent($new), $new . ' must be permanent');
		}
		// Ambiguous codes still fail open toward retrying.
		$this->assertFalse($this->policy->isPermanent('empty_stream'));
		$this->assertFalse($this->policy->isPermanent('unknown'));
		$this->assertFalse($this->policy->isPermanent(null));
	}

	public function testTheClassifierVerdictCodesReadNewAndWriteNew(): void
	{
		$this->assertSame('skip_text_native', $this->policy->verdictCode('born_digital_text_based'));
		$this->assertSame('skip_classifier_says_photo', $this->policy->verdictCode('mobilenet'));
		$this->assertSame('skip_ocred_by_ocrflow', $this->policy->verdictCode('stamped_good_by_us'));
		$this->assertSame('skip_text_native', $this->policy->verdictCode('skip_text_native'));
		$this->assertNull($this->policy->verdictCode('no_text_detected'));
		$this->assertNull($this->policy->verdictCode(null));
	}

	/**
	 * [unit] The loop breaker's code: verdict, one sentence, one fix, one
	 * actor. Catches a code added without the prose the attention worklist
	 * shows the operator — a needs_fix nobody can read is a file nobody
	 * can unstick.
	 */
	public function testTheQueuedTwiceCodeCarriesTheFullNeedsFixEntry(): void
	{
		$entry = $this->policy->entry('error_queued_twice_for_same_bytes');

		$this->assertNotNull($entry);
		$this->assertSame('needs_fix', $entry['verdict']);
		$this->assertNotSame('', trim($entry['label']), 'one sentence a human can read');
		$this->assertStringContainsStringIgnoringCase('requeue', $entry['fix'], 'the fix must name the way out');
		$this->assertNotSame('', trim((string) $entry['actor']), 'an actor is expected to do it');
	}
}
