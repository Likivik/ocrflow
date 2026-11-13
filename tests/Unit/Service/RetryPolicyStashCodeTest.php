<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The stashed-result outcome: `skip_source_changed_result_saved`.
 *
 * A genuine write-time conflict is no longer `source_changed` (a silent
 * skip that lost the worker's result). The result is saved aside for the
 * owner to decide on, so the verdict is `redo` — an owner decision, not a
 * retry — and the fix hint must point where the decision is made.
 *
 * Fails on unmodified main: the code does not exist there, and the old
 * `source_changed` mapped to nothing (an unknown code on read).
 */
class RetryPolicyStashCodeTest extends TestCase
{
	public function testTheStashCodeIsCompleteAndRedo(): void
	{
		$policy = new RetryPolicy();

		$this->assertTrue($policy->isKnown('skip_source_changed_result_saved'));
		$this->assertSame('redo', $policy->verdict('skip_source_changed_result_saved'));

		$this->assertFalse(
			$policy->isRetryable('skip_source_changed_result_saved'),
			'a conflict is settled by the owner deciding, never by a retry of the same stale result'
		);
		$this->assertFalse(
			$policy->isPermanent('skip_source_changed_result_saved'),
			'the artifact is recoverable; the row must not be treated as a permanent failure'
		);

		$entry = $policy->entry('skip_source_changed_result_saved');
		$this->assertNotNull($entry);
		$this->assertSame('redo', $entry['verdict']);
		$this->assertSame('owner', $entry['actor']);
		$this->assertSame(1, preg_match('/^[^.!?]+[.]$/', (string) $entry['label']),
			'the label is one sentence, for a worklist row');
		$this->assertStringContainsStringIgnoringCase('decide', (string) $entry['fix'],
			'the fix hint points at the owner decision, not at a retry');
	}

	public function testTheLegacySourceChangedSpellingReadsAsTheStashCode(): void
	{
		$policy = new RetryPolicy();
		$this->assertSame(
			'skip_source_changed_result_saved',
			$policy->canonical('source_changed'),
			'old rows written with source_changed must read as the outcome they actually were'
		);
	}
}
