<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Contract;

use OCA\OcrFlow\Report\FileRow;
use OCA\OcrFlow\Service\Attention;
use OCA\OcrFlow\Service\Mine;
use OCA\OcrFlow\Service\OutcomeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * [contract] The same file says the same thing through every door.
 *
 * "Not processed yet 10 vs 30" was two doors computing two answers. This pins
 * the other half of that bug: the same raw row, routed through the worklist
 * (`/mine`) and the admin attention list, must come out with the same key set,
 * the same code and the same sentence. Blocks are NOT compared to files — they
 * are different types on purpose (see FileRowTest).
 */
class FileRowParityTest extends TestCase
{
	/**
	 * @return array<string, mixed>
	 */
	private function raw(): array
	{
		return [
			'id' => 42,
			'file_id' => 7,
			'path' => '/alice/files/scan.pdf',
			'reason_code' => 'error_file_broken',
			'reason' => 'broken_pdf',
			'error' => 'xref table is gone',
			'attempts' => 2,
			'finished_at' => 1700000000,
			'verdict' => null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function throughMine(): array
	{
		$instance = (new \ReflectionClass(Mine::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(Mine::class, 'row');

		return $method->invoke($instance, $this->raw(), 'mine', true);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function throughAttention(): array
	{
		$instance = (new \ReflectionClass(Attention::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(Attention::class, 'toApi');

		return $method->invoke($instance, $this->raw());
	}

	public function testBothDoorsProduceTheSameShape(): void
	{
		$mine = $this->throughMine();
		$attention = $this->throughAttention();

		$this->assertSame(
			array_keys($mine),
			array_keys($attention),
			'the worklist and the attention list must agree on what a row is',
		);
	}

	public function testBothDoorsSayTheSameThingAboutTheSameFile(): void
	{
		$mine = $this->throughMine();
		$attention = $this->throughAttention();

		foreach (['id', 'file_id', 'path', 'code', 'text', 'attempts'] as $key) {
			$this->assertSame($mine[$key], $attention[$key], "the two doors disagree about $key");
		}

		$this->assertSame('Damaged file', OutcomeCatalog::for($mine['code'])['label']);
		$this->assertSame('This file is damaged and cannot be read.', $mine['text']);
	}

	public function testTheDrillDownReadsTheSameRowShape(): void
	{
		// A block's drill-down is a file list: same shape, no third variant.
		$drillDown = FileRow::of($this->raw());

		$this->assertSame(
			array_keys($this->throughMine()),
			array_keys($drillDown),
			'the drill-down must not grow a shape of its own',
		);
	}
}
