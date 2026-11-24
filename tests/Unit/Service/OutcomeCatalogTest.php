<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\OutcomeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The one vocabulary: 36 codes, one sentence each, one label each.
 *
 * A row label is not a sentence. The panel prints these in a column next to a
 * count. The long text explains the code and belongs in a tooltip; the short
 * form is what a person reads while scanning six blocks on a phone. It
 * therefore has to be a label: capitalised, no full stop, no parentheses —
 * parentheses in that column mean "count", and a label that borrows them reads
 * as a number.
 *
 * Carries every assertion the old `VocabCopyTest` held against `RetryPolicy`.
 */
class OutcomeCatalogTest extends TestCase
{
	public function testEveryCodeHasAShortFormThatIsARowLabel(): void
	{
		$codes = OutcomeCatalog::codes();
		$this->assertCount(36, $codes, 'the vocabulary is the list this test promises over');

		foreach ($codes as $code) {
			$entry = OutcomeCatalog::entry($code);
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
		$entry = OutcomeCatalog::entry('error_file_broken');

		$this->assertSame('Damaged file', $entry['short']);
		$this->assertSame('This file is damaged and cannot be read.', $entry['text'], 'the tooltip text is untouched');
	}

	public function testAnUnknownCodeFallsBackToTheUnknownCodeEntry(): void
	{
		// `canonical()` maps anything it does not recognise onto
		// `error_worker_sent_unknown_code`, so a code from a newer worker still
		// renders a row instead of vanishing or fataling. Only a null code
		// yields null.
		$this->assertSame('Unknown worker code', OutcomeCatalog::entry('no_such_code')['short']);
		$this->assertNull(OutcomeCatalog::entry(null));
	}

	public function testForCarriesTheWordsAndTheBlock(): void
	{
		$row = OutcomeCatalog::for('error_file_broken');

		$this->assertSame('error_file_broken', $row['code']);
		$this->assertSame('needs_fix', $row['verdict']);
		$this->assertSame('Damaged file', $row['label']);
		$this->assertSame('This file is damaged and cannot be read.', $row['sentence']);
		$this->assertSame('error', $row['block']);
	}

	public function testAFileWithNoCodeIsReadNotError(): void
	{
		// No code means a `done` row: the file was read. Claiming "not
		// processed" for it would be a lie in the one place that must not lie.
		$this->assertSame('read', OutcomeCatalog::for(null)['block']);
	}

	public function testTheEngineLabelIsOneImplementationForEveryRow(): void
	{
		$this->assertSame('Google 3.16.0', OutcomeCatalog::engine('google', '3.16.0'));
		$this->assertSame('Google, version not recorded', OutcomeCatalog::engine('google', null));
		$this->assertSame('Engine not recorded, version not recorded', OutcomeCatalog::engine(null, null), 'no engine and no version: both facts are stated');
		$this->assertSame('Engine not recorded 1.0', OutcomeCatalog::engine(null, '1.0'), 'a version is stated even when the engine is not');
		$this->assertSame('MiniMax', OutcomeCatalog::reader('minimax'));
		$this->assertSame('Tesseract', OutcomeCatalog::reader('tesseract'));
	}
}
