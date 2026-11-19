<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * [unit] A row label is not a sentence.
 *
 * The panel prints these in a column next to a count. The long text explains
 * the code in prose and belongs in a tooltip; the short form is what a person
 * reads while scanning six blocks on a phone. It therefore has to be a label:
 * capitalised, no full stop, and no parentheses around a unit — parentheses in
 * that column mean "count", and a label that borrows them reads as a number.
 */
class VocabCopyTest extends TestCase
{
	public function testEveryCodeHasAShortFormThatIsARowLabel(): void
	{
		$codes = RetryPolicy::codes();
		$this->assertCount(35, $codes, 'the vocabulary is the list this test promises over');

		$policy = new RetryPolicy();
		foreach ($codes as $code) {
			$entry = $policy->entry($code);
			$this->assertIsArray($entry, "$code must resolve to an entry");

			$short = $entry['short'] ?? null;
			$this->assertIsString($short, "$code has no short form");
			$this->assertNotSame('', $short, "$code has an empty short form");
			$this->assertStringEndsNotWith('.', $short, "$code is a sentence, not a row");
			$this->assertSame(
				mb_strtoupper(mb_substr($short, 0, 1)),
				mb_substr($short, 0, 1),
				"$code must start with a capital",
			);
			$this->assertStringNotContainsString(
				'(',
				$short,
				"$code uses parentheses, which mean a count in this column",
			);
		}
	}

	public function testTheShortFormDoesNotReplaceTheSentence(): void
	{
		$policy = new RetryPolicy();
		$entry = $policy->entry('error_file_broken');

		$this->assertSame('Damaged file', $entry['short']);
		$this->assertSame('This file is damaged and cannot be read.', $entry['text'], 'the tooltip text is untouched');
	}

	public function testAnUnknownCodeFallsBackToTheUnknownCodeEntry(): void
	{
		// `canonical()` maps anything it does not recognise onto
		// `error_worker_sent_unknown_code`, so a code from a newer worker still
		// renders a row instead of vanishing or fataling. Only a null code
		// yields null.
		$policy = new RetryPolicy();
		$this->assertSame('Unknown worker code', $policy->entry('no_such_code')['short']);
		$this->assertNull($policy->entry(null));
	}
}
