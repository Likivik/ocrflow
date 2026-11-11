<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The retry table is policy, so it is tested as policy: every terminal code
 * stays terminal, every transient code stays retryable, and an unknown code is
 * bounded-retryable rather than frozen.
 */
final class RetryPolicyTest extends TestCase
{
	private RetryPolicy $policy;

	protected function setUp(): void
	{
		$this->policy = new RetryPolicy();
	}

	public function testTerminalCodesAreNeverRetried(): void
	{
		foreach (['no_text_detected', 'parse_produced_nothing', 'save_refused', 'broken_pdf',
			'bad_format', 'empty_source', 'empty_stream', 'credentials_missing',
			'app_4xx', 'engine_4xx'] as $code) {
			$this->assertTrue($this->policy->isKnown($code), $code . ' must be in the vocabulary');
			$this->assertFalse($this->policy->isRetryable($code), $code . ' must not be retried');
		}
	}

	public function testTransientCodesAreRetried(): void
	{
		foreach (['app_5xx', 'engine_5xx', 'engine_timeout', 'storage_error'] as $code) {
			$this->assertTrue($this->policy->isKnown($code), $code . ' must be in the vocabulary');
			$this->assertTrue($this->policy->isRetryable($code), $code . ' must be retried');
		}
	}

	public function testAnUnrecognisedCodeIsBoundedRetryableNotFrozen(): void
	{
		$this->assertFalse($this->policy->isKnown('some_new_engine_fault'));
		$this->assertTrue($this->policy->isRetryable('some_new_engine_fault'),
			'silence must not turn a transient fault into a permanent loss');
	}

	public function testNoCodeMeansNoDecisionRatherThanNotRetryable(): void
	{
		$this->assertFalse($this->policy->isRetryable(null));
		$this->assertSame(['retryable' => false, 'agrees' => null], $this->policy->decide(null, null));
	}

	public function testTheWorkerHintIsComparedButThePolicyDecides(): void
	{
		// Worker says retryable, policy says terminal: policy wins, disagreement
		// is surfaced for the log.
		$decision = $this->policy->decide('no_text_detected', true);
		$this->assertFalse($decision['retryable']);
		$this->assertFalse($decision['agrees']);

		// Agreement is recorded as agreement, not as silence.
		$decision = $this->policy->decide('engine_timeout', true);
		$this->assertTrue($decision['retryable']);
		$this->assertTrue($decision['agrees']);

		// No hint: nothing to compare.
		$decision = $this->policy->decide('engine_timeout', null);
		$this->assertTrue($decision['retryable']);
		$this->assertNull($decision['agrees']);
	}

	/**
	 * The permanent set must be a subset of the single closed vocabulary: if
	 * a code is listed as permanent but the vocabulary has never heard of it,
	 * the two lists have drifted and enqueue would block on a code nobody
	 * can ever record. This test is the tripwire.
	 */
	public function testEveryPermanentCodeExistsInTheVocabulary(): void
	{
		$vocabulary = $this->policy->vocabulary();
		foreach ($this->policy->permanentCodes() as $code) {
			$this->assertArrayHasKey($code, $vocabulary,
				'the permanent set and the vocabulary must never drift: ' . $code . ' is not a known code');
		}
	}

	/**
	 * Permanent is a strict subset of terminal: a permanent code must not be
	 * retryable, and it must be a FAILURE code, not a classifier verdict —
	 * a verdict is an answer, not something the file failed at.
	 */
	public function testPermanentCodesAreTerminalFailures(): void
	{
		foreach ($this->policy->permanentCodes() as $code) {
			$this->assertTrue($this->policy->isKnown($code));
			$this->assertFalse($this->policy->isRetryable($code),
				$code . ' cannot be both permanent and retryable');
			$this->assertNull($this->policy->verdictCode($code),
				$code . ' is a failure code, not a classifier verdict');
		}
	}

	/**
	 * The classification itself, pinned: retrying the same bytes can never
	 * succeed for these, so enqueue must refuse them after the purge.
	 */
	public function testTheByteLevelFailuresArePermanent(): void
	{
		foreach (['no_text_detected', 'parse_produced_nothing', 'save_refused',
			'broken_pdf', 'bad_format', 'empty_source'] as $code) {
			$this->assertTrue($this->policy->isPermanent($code), $code . ' is permanent: the same bytes cannot succeed');
		}
	}

	/**
	 * Everything else fails open toward retrying: a code whose diagnosis is
	 * ambiguous (transport-shaped, deploy-shaped, or a 4xx that may be about
	 * credentials rather than the bytes) is NOT permanent.
	 */
	public function testAmbiguousAndTransientCodesAreNotPermanent(): void
	{
		foreach (['empty_stream', 'credentials_missing', 'app_4xx', 'engine_4xx',
			'source_changed', 'born_digital_text_based', 'mobilenet', 'stamped_good_by_us',
			'app_5xx', 'engine_5xx', 'engine_timeout', 'storage_error',
			'some_new_engine_fault', RetryPolicy::CODE_UNKNOWN] as $code) {
			$this->assertFalse($this->policy->isPermanent($code), $code . ' must fail open toward retrying');
		}
	}

	public function testPermanentIsFalseForNullAndUnknown(): void
	{
		$this->assertFalse($this->policy->isPermanent(null));
		$this->assertFalse($this->policy->isPermanent('made_up_code'));
	}
}
