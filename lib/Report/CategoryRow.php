<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Report;

/**
 * One aggregate row: a count under a label.
 *
 * A block row is NOT a file row — it has no `fileId` and no `path`, because a
 * count is not a file. The types are separate on purpose: the day a block row
 * and a file row share a shape is the day a drill-down is forgotten and the
 * panel counts a block as if it were one file.
 */
class CategoryRow
{
	/**
	 * @return array{key: string, label: string, count: int, title: ?string, below_bar: bool}
	 */
	public static function of(string $key, string $label, int $count, ?string $title = null, bool $belowBar = false): array
	{
		return [
			'key' => $key,
			'label' => $label,
			'count' => $count,
			// An optional longer explanation, shown as a tooltip.
			'title' => $title,
			// A known engine version below the bar is the one case worth
			// re-reading; an unknown version is unknown, not bad.
			'below_bar' => $belowBar,
		];
	}
}
