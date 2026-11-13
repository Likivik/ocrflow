<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * [unit] Wiring: `result()` and `failure()` no longer decide write-time
 * conflicts, and the trash move lives where the write happens.
 *
 * The measured defect: `result()` called `sourceChanged()` after the bytes
 * had already been written — on the in-place path our own `putFile` write
 * changed the etag first, so the guard fired on our own write — and the
 * trash of a converted source ran there too, before the ladder could prove
 * the produced file actually landed beside the version it was queued for.
 *
 * This is a structural test on purpose: the two methods must stay free of
 * both symbols even when a future edit adds calls back, and the ladder plus
 * the trash must be reachable from both write endpoints.
 */
class QueueControllerWiringTest extends TestCase
{
	private function methodBody(string $name): string
	{
		$source = (string) file_get_contents(__DIR__ . '/../../../lib/Controller/QueueController.php');
		$this->assertNotSame('', $source, 'QueueController.php could not be read');
		$offset = strpos($source, 'function ' . $name . '(');
		$this->assertNotFalse($offset, 'method ' . $name . '() not found in QueueController');

		$open = strpos($source, '{', $offset);
		$this->assertNotFalse($open);
		$depth = 0;
		for ($i = $open, $n = strlen($source); $i < $n; $i++) {
			if ($source[$i] === '{') {
				$depth++;
			} elseif ($source[$i] === '}') {
				$depth--;
				if ($depth === 0) {
					return substr($source, $open, $i - $open + 1);
				}
			}
		}
		$this->fail('unbalanced braces in ' . $name . '()');
	}

	public function testResultRunsNeitherSourceChangedNorTrashSource(): void
	{
		$body = $this->methodBody('result');
		$this->assertStringNotContainsString('sourceChanged', $body, 'result() must not decide write-time conflicts: the ladder owns that');
		$this->assertStringNotContainsString('trashSource', $body, 'result() must not trash: the trash belongs to resultFile(), after a successful createBeside()');
		$this->assertStringNotContainsString('skipSourceChanged', $body, 'the source_changed skip is gone; the ladder stashes instead');
	}

	public function testFailureRunsNeitherSourceChangedNorTrashSource(): void
	{
		$body = $this->methodBody('failure');
		$this->assertStringNotContainsString('sourceChanged', $body);
		$this->assertStringNotContainsString('trashSource', $body);
	}

	public function testBothWriteEndpointsRunTheLadderBeforeWriting(): void
	{
		foreach (['putFile', 'resultFile'] as $endpoint) {
			$body = $this->methodBody($endpoint);
			$this->assertStringContainsString('writeConflict', $body, $endpoint . '() must run the ladder before writing');
		}
	}

	public function testResultFileTrashesAfterASuccessfulCreateBeside(): void
	{
		$body = $this->methodBody('resultFile');
		$this->assertStringContainsString('trashConvertedSource', $body, 'the trash of a converted source moved into resultFile()');
		$this->assertLessThan(
			strpos($body, 'trashConvertedSource'),
			strrpos($body, 'createBeside'),
			'the trash runs only after the produced file was created'
		);
	}
}
