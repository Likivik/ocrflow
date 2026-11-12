<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The guard allow-list. Every entry is a finding a structural guard
 * reported and a HUMAN decided to keep; weaken a guard elsewhere, never
 * here by accident.
 *
 * Key format: "<relative path>:<line of the catch>" (SilentCatchTest) —
 * line-anchored because the guards report file:line. When a listed line
 * moves, the guard fails again and forces the entry to be re-reviewed:
 * that is the point.
 *
 * Value: one line saying WHY the case is legitimate and who decided.
 */
return [
	'SilentCatch' => [
		// 'lib/Path/File.php:123' => 'reason — decided in #NN',
	],
];
