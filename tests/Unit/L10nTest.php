<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * [unit] A panel that is half English is a formatting defect, not a missing
 * feature.
 *
 * The panel is written in English and translated by key. The Russian file had
 * 29 entries while the panel asked for 66, so a Russian user saw English
 * sentences and buttons in the middle of Russian ones. This test makes that a
 * build failure: every literal `t()` key in `Panel.vue`, plus the filter names,
 * must have a translation.
 */
class L10nTest extends TestCase
{
	public function testEveryPanelStringHasARussianTranslation(): void
	{
		$root = dirname(__DIR__, 2);
		$vue = (string) file_get_contents($root . '/src/Panel.vue');
		$ru = json_decode((string) file_get_contents($root . '/l10n/ru.json'), true, 512, JSON_THROW_ON_ERROR);

		$this->assertIsArray($ru['translations'] ?? null, 'ru.json has a translations map');

		$keys = [];
		preg_match_all("/\\bt\\('([^']+)'/", $vue, $matches);
		foreach ($matches[1] as $key) {
			$keys[$key] = true;
		}
		// The filter names are data, not literals in the template.
		preg_match_all("/label: '([^']+)'/", $vue, $labels);
		foreach ($labels[1] as $label) {
			$keys[$label] = true;
		}

		$this->assertNotEmpty($keys, 'the panel does use the translation function');

		$missing = [];
		foreach (array_keys($keys) as $key) {
			if (!array_key_exists($key, $ru['translations'])) {
				$missing[] = $key;
			}
		}

		$this->assertSame([], $missing, "these panel strings have no Russian: \n" . implode("\n", $missing));
	}
}
