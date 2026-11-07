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
}
