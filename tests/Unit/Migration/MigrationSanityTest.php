<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Migration;

use OCP\Migration\SimpleMigrationStep;
use PHPUnit\Framework\TestCase;

/**
 * Migration sanity and ordering.
 *
 * A version number reused for different work is exactly the failure mode
 * this app has already lived through once (the `engine` column was added
 * by editing an already-shipped migration): an installed instance has
 * recorded the version and never runs the new work. The guard family:
 *
 *  - filenames must match their class name (Nextcloud resolves the class
 *    FROM the filename);
 *  - versions must be well-formed (Version<NNNNNN>Date<YYYYMMDDHHMMSS>);
 *  - versions must be unique — two migrations with one version number
 *    mean one of them never runs;
 *  - versions must be strictly increasing in filename order — a step that
 *    sorts before its predecessor is skipped by the repair step runner.
 */
final class MigrationSanityTest extends TestCase
{
	public function testMigrationsAreSaneAndOrdered(): void
	{
		$root = dirname(__DIR__, 3); // tests/Unit/Migration -> repo root
		$files = glob($root . '/lib/Migration/Version*.php') ?: [];
		$this->assertNotSame([], $files, 'no migrations found — the app cannot install');

		$offenders = [];
		$seen = [];
		$previous = null;

		foreach ($files as $file) {
			$base = basename($file, '.php');
			$rel = 'lib/Migration/' . basename($file);

			if (!preg_match('/^Version(\d{6})Date(\d{14})$/', $base, $m)) {
				$offenders[] = sprintf('%s — filename is not Version<NNNNNN>Date<YYYYMMDDHHMMSS> — Nextcloud resolves the class from the filename, and this one cannot', $rel);
				continue;
			}
			$version = $m[1];
			$date = $m[2];
			if (strtotime(substr($date, 0, 8)) === false) {
				$offenders[] = sprintf('%s — date part %s is not a real date — fix the name before shipping', $rel, $date);
			}

			$class = 'OCA\\OcrFlow\\Migration\\' . $base;
			if (!class_exists($class)) {
				$offenders[] = sprintf('%s — class %s does not exist — the filename and the class name must match exactly', $rel, $class);
				continue;
			}
			$reflection = new \ReflectionClass($class);
			if ($reflection->getName() !== $class) {
				$offenders[] = sprintf('%s — the file declares %s, not %s — rename the class to match the filename', $rel, $reflection->getName(), $class);
			}
			if (!$reflection->isSubclassOf(SimpleMigrationStep::class)) {
				$offenders[] = sprintf('%s — %s does not extend SimpleMigrationStep — the repair step runner will not execute it', $rel, $class);
			}

			if (isset($seen[$version])) {
				$offenders[] = sprintf('%s — version %s is already taken by %s — a reused version NEVER runs on an installed instance; take the next free number instead', $rel, $version, $seen[$version]);
			}
			$seen[$version] = $rel;

			$key = $version . '.' . $date;
			if ($previous !== null && strcmp($key, $previous) <= 0) {
				$offenders[] = sprintf('%s — sorts at or before the previous migration (%s) — migrations must run in strictly increasing version order', $rel, $previousRel ?? '');
			}
			$previous = $key;
			$previousRel = $rel;
		}

		$this->assertSame([], $offenders,
			"The migration set is not sane:\n  " . implode("\n  ", $offenders));
	}
}
