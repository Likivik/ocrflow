<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Quality;

use PHPUnit\Framework\TestCase;

/**
 * No silent catch.
 *
 * A silent discard is how a wrong worker's result was once accepted with
 * a 200 and no trace: the catch swallowed the mismatch and the request
 * went on as if nothing had happened. Every catch block under lib/ must
 * therefore do at least one of:
 *
 *  - log (any *Logger call: ->warning/->info/->error/->debug/... on a
 *    logger-typed receiver);
 *  - rethrow (a `throw` inside the block);
 *  - return an explicitly-named degraded result DOCUMENTED by a comment
 *    inside the catch block — the comment is what makes the degradation
 *    a decision instead of an accident.
 *
 * Legitimate exceptions live in tests/Unit/Quality/guard-allowlist.php,
 * one reason per entry; there is no other escape hatch.
 */
final class SilentCatchTest extends TestCase
{
	public function testNoCatchBlockDiscardsSilently(): void
	{
		$allow = $this->allowlist();
		$offenders = [];

		foreach ($this->libFiles() as $file) {
			$offenders = array_merge($offenders, $this->silentCatches($file, $allow));
		}

		$this->assertSame([], $offenders,
			"These catch blocks neither log, rethrow, nor name their degraded result:\n  "
			. implode("\n  ", $offenders) . "\n"
			. "Log it, rethrow it, or return a degraded result with a comment saying why that\n"
			. "is correct. If it is genuinely fine, it goes in guard-allowlist.php with a reason.");
	}

	// -------------------------------------------------------------------

	/**
	 * @param array<string, string> $allow "path:line" => reason
	 * @return list<string> "path:line — what is wrong — how to fix"
	 */
	private function silentCatches(string $file, array $allow): array
	{
		$source = (string) file_get_contents($file);
		$rel = $this->relative($file);
		$tokens = token_get_all($source);
		$offenders = [];

		for ($i = 0, $n = count($tokens); $i < $n; $i++) {
			if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) {
				continue;
			}
			$line = $tokens[$i][2];

			// The catch clause runs to its `{`; the body runs to its match.
			$j = $i;
			$depth = 0;
			$bodyStart = null;
			while ($j < $n) {
				if ($tokens[$j] === '{') {
					if ($bodyStart === null) {
						$bodyStart = $j;
					}
					$depth++;
				} elseif ($tokens[$j] === '}') {
					$depth--;
					if ($depth === 0) {
						break;
					}
				}
				$j++;
			}
			if ($bodyStart === null) {
				continue;
			}
			$body = array_slice($tokens, $bodyStart, $j - $bodyStart + 1);

			$logs = false;
			$throws = false;
			$documents = false;
			foreach ($body as $k => $tok) {
				if (!is_array($tok)) {
					continue;
				}
				if ($tok[0] === T_THROW) {
					$throws = true;
				}
				if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
					$documents = true;
				}
				// A logger call: <anything>->warning/info/error/debug/notice/
				// critical/alert/emergency/log( right after `catch` handling.
				if (in_array($tok[1], ['warning', 'info', 'error', 'debug', 'notice', 'critical', 'alert', 'emergency', 'log'], true)
					&& is_array($body[$k - 1] ?? null)
					&& in_array($body[$k - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
					&& isset($body[$k + 1]) && $body[$k + 1] === '(') {
					$logs = true;
				}
			}

			if ($logs || $throws || $documents) {
				continue;
			}
			$key = $rel . ':' . $line;
			if (isset($allow[$key])) {
				continue;
			}
			$offenders[] = sprintf('%s:%d — catch block neither logs, rethrows, nor documents its degraded result — log it, rethrow it, or comment WHY the discard is correct (or add it to guard-allowlist.php with a reason)',
				$rel, $line);
		}
		return $offenders;
	}

	/**
	 * @return array<string, string>
	 */
	private function allowlist(): array
	{
		$path = dirname(__DIR__) . '/guard-allowlist.php';
		if (!is_file($path)) {
			return [];
		}
		$allow = include $path;
		self::assertIsArray($allow, 'guard-allowlist.php must return an array');
		return $allow['SilentCatch'] ?? [];
	}

	/**
	 * @return list<string> absolute paths of every PHP file under lib/
	 */
	private function libFiles(): array
	{
		$root = dirname(__DIR__, 3); // tests/Unit/Quality -> repo root
		$files = [];
		$stack = [$root . '/lib'];
		while ($stack !== []) {
			$dir = array_pop($stack);
			foreach (scandir($dir) ?: [] as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				$path = $dir . '/' . $entry;
				if (is_dir($path)) {
					$stack[] = $path;
				} elseif (str_ends_with($path, '.php')) {
					$files[] = $path;
				}
			}
		}
		sort($files);
		return $files;
	}

	private function relative(string $file): string
	{
		return ltrim(substr($file, strlen(dirname(__DIR__, 3)) + 1), '/');
	}
}
