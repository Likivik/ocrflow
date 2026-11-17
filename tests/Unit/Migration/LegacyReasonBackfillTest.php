<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Migration;

use OCA\OcrFlow\Migration\Version001011Date20261117180000;
use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The backfill must not invent a second vocabulary.
 *
 * 5,453 production rows carry their verdict in `reason` as a legacy word and
 * nothing in `reason_code`. The migration copies the word to the code, and the
 * only safe way to do that is to use the alias table the app already trusts —
 * if the two ever drift, a row would be relabelled into something the worker
 * never meant.
 */
class LegacyReasonBackfillTest extends TestCase
{
	public function testEveryLegacyWordIsAlreadyAnAliasTheAppKnows(): void
	{
		$policy = new RetryPolicy();

		foreach (Version001011Date20261117180000::LEGACY_REASONS as $word => $code) {
			$this->assertTrue($policy->isKnown($code), $word . ' points at an unknown code: ' . $code);
			$this->assertSame(
				$code,
				$policy->canonical($word),
				'the migration\'s spelling of "' . $word . '" must match RetryPolicy',
			);
		}
	}

	public function testTheSixWordsThatProductionActuallyHasAreCovered(): void
	{
		// Measured on the live queue: born_digital_text_based 2510, mobilenet
		// 1910, stamped_good_by_us 1013, no_text_detected 13, broken_pdf 5,
		// unsupported_ext 2.
		$this->assertSame([
			'born_digital_text_based',
			'mobilenet',
			'stamped_good_by_us',
			'no_text_detected',
			'broken_pdf',
			'unsupported_ext',
		], array_keys(Version001011Date20261117180000::LEGACY_REASONS));
	}
}
