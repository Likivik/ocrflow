<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * A block is a statement about the file, and it comes from the code.
 *
 * `status` was the old grouping, and it put seven live rows in the wrong block:
 * 5 damaged files and 2 empty ones carry `status='skipped'` because the skip
 * path wrote them, while their verdict is "needs a person". The code cannot
 * drift that way — it is the decision itself.
 */
class RetryPolicyBlocksTest extends TestCase
{
	public function testTheCodesThatCarryAFileOutcomeLandInTheirOwnBlock(): void
	{
		$policy = new RetryPolicy();

		$this->assertSame('read', $policy->blockOf('skip_ocred_by_ocrflow'), 'we read it earlier');
		$this->assertSame('read', $policy->blockOf('skip_ocred_by_ocrflow_found_nothing'), 'we read it, there was no text');
		$this->assertSame('born_digital', $policy->blockOf('skip_text_native'));
		$this->assertSame('image', $policy->blockOf('skip_classifier_says_photo'));
		$this->assertSame('error', $policy->blockOf('error_file_broken'));
		$this->assertSame('error', $policy->blockOf('error_source_file_zero_bytes'));
		$this->assertSame('error', $policy->blockOf('error_worker_file_gone'));
		$this->assertSame('error', $policy->blockOf('skip_file_type_unsupported'));
	}

	public function testADoneRowHasNoCodeAndWasThereforeRead(): void
	{
		$this->assertSame('read', (new RetryPolicy())->blockOf(null));
		$this->assertSame('read', (new RetryPolicy())->blockOf(''));
	}

	public function testALegacyWordIsTranslatedBeforeItIsBucketed(): void
	{
		$policy = new RetryPolicy();

		// The 5,453 backfilled rows carry these words in `reason`.
		$this->assertSame('born_digital', $policy->blockOf('born_digital_text_based'));
		$this->assertSame('image', $policy->blockOf('mobilenet'));
		$this->assertSame('read', $policy->blockOf('stamped_good_by_us'));
	}

	public function testAWordTheAppDoesNotKnowIsNeverCalledRead(): void
	{
		// Claiming "OCRed by us" for something we do not understand would be a
		// lie in the one place that must not lie.
		$this->assertSame('error', (new RetryPolicy())->blockOf('made_up_code'));
	}

	public function testEveryBlockHasALabelInTheOrderThePanelShowsThem(): void
	{
		$this->assertSame([
			'not_processed',
			'queue',
			'read',
			'born_digital',
			'image',
			'error',
		], array_keys(RetryPolicy::BLOCKS));
	}
}
