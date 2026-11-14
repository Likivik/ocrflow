<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * [unit] A fix hint diagnoses; it does not instruct.
 *
 * The personal panel shows the hint under the file's path, next to buttons that
 * actually do things. A hint that says "Retry — the engine was down" reads as a
 * second button that does nothing when clicked, and one that says "the owner
 * must re-upload" tells the person reading it to go and find an owner. Both are
 * the same defect: the hint is doing the UI's job in prose, and the prose is
 * then wrong for whoever is reading it (the owner, an admin, or neither).
 *
 * So the rule this test enforces is mechanical, not stylistic:
 *   * ONE sentence — two sentences in a hint is a hint plus an instruction.
 *   * No second person, no owner, no administrator: the row does not know who
 *     is looking at it.
 *   * No action verbs: the buttons are the actions.
 *
 * The codes below are read from the vocabulary itself, so a code added later
 * without honest prose fails here rather than in production.
 */
class RetryPolicyWordingTest extends TestCase
{
	/**
	 * Words that turn a diagnosis into an instruction. Matched on word
	 * boundaries, so "reported" does not trip "report" and "cannot be read"
	 * does not trip "read the log".
	 */
	private const FORBIDDEN = [
		// second person
		'you', 'your', 'yours', 'yourself',
		// people the row cannot know about
		'owner', 'owners', 'admin', 'administrator', 'administrators',
		// politeness that hides an instruction
		'ask', 'please',
		// modal instructions
		'should', 'must', 'need to', 'try',
		// verbs that belong on a button
		'open', 'upload', 'reupload', 're-upload', 'delete', 'retry', 'requeue',
		'click', 'configure', 'install', 'update', 'upgrade', 'contact',
		'repair', 'fix', 'check', 'report',
	];

	private RetryPolicy $policy;

	protected function setUp(): void
	{
		$this->policy = new RetryPolicy();
	}

	/**
	 * @return list<string>
	 */
	private function codes(): array
	{
		return array_keys($this->policy->vocabulary());
	}

	/**
	 * Split a hint into sentences. Abbreviations that carry a dot are masked
	 * first, so "e.g. this" is not read as two sentences.
	 *
	 * @return list<string>
	 */
	private function sentences(string $text): array
	{
		$scrubbed = (string) preg_replace('/\b(e\.g|i\.e|etc|vs)\./i', '$1', trim($text));
		$parts = preg_split('/(?<=[.!?])\s+/u', $scrubbed, -1, PREG_SPLIT_NO_EMPTY);

		return $parts === false ? [] : array_values($parts);
	}

	public function testThereIsSomethingToRead(): void
	{
		// A guard on the guard: an empty vocabulary would make every
		// assertion below vacuously true.
		$this->assertGreaterThan(20, count($this->codes()));
	}

	public function testEveryHintIsExactlyOneSentence(): void
	{
		foreach ($this->codes() as $code) {
			$text = (string) ($this->policy->entry($code)['text'] ?? '');
			$this->assertNotSame('', trim($text), $code . ': a hint is required');

			$sentences = $this->sentences($text);
			$this->assertCount(1, $sentences, $code . ': a hint is ONE sentence, got: ' . $text);
			$this->assertSame(trim($text), $sentences[0], $code . ': no leading or trailing prose');
			$this->assertStringEndsWith('.', trim($text), $code . ': a sentence ends with a full stop');
		}
	}

	public function testNoHintInstructsTheReader(): void
	{
		foreach ($this->codes() as $code) {
			$text = (string) ($this->policy->entry($code)['text'] ?? '');
			$lower = mb_strtolower($text);
			foreach (self::FORBIDDEN as $word) {
				$this->assertDoesNotMatchRegularExpression(
					'/\b' . preg_quote($word, '/') . '\b/u',
					$lower,
					$code . ': the hint must diagnose, not instruct — "' . $word . '" in: ' . $text,
				);
			}
		}
	}

	/**
	 * The hints that were reviewed word by word. Pinning them here keeps a
	 * later "small wording tweak" from quietly reinstating instructions.
	 */
	public function testTheReviewedHintsReadExactlyAsAgreed(): void
	{
		$expected = [
			'error_file_broken' => 'This file is damaged and cannot be read.',
			'error_source_file_zero_bytes' => 'This file is empty (0 bytes).',
			'error_worker_unclassified' => 'OCR failed without saying why.',
			'error_provider_unsupported_file' => 'This file type is not supported.',
			'error_provider_timed_out' => 'The OCR service took too long to answer.',
		];
		foreach ($expected as $code => $text) {
			$this->assertSame($text, $this->policy->entry($code)['text'], $code);
		}

		// The quota code says the file is fine — the reassurance IS the
		// diagnosis here — but it still may not tell anyone to do anything.
		$quota = (string) $this->policy->entry('error_provider_quota_exhausted')['text'];
		$this->assertStringContainsStringIgnoringCase('quota', $quota);
		$this->assertStringContainsStringIgnoringCase('file itself is fine', $quota);
	}

}
