<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Worker ↔ app vocabulary drift.
 *
 * A worker must never emit a reason code the app has never heard of: the
 * app records anything it does not know as `unknown` and the row's verdict
 * then decides its fate, so one invented spelling silently changes policy.
 * This guard reads every snake_case token the worker passes as a
 * `reason` / `reason_code` (call-site keyword arguments, `"reason": …`
 * payload dicts, the failure classifier's return values, the
 * VERDICT_CODES table and the REASON_* constants) and refuses any token
 * that is in neither RetryPolicy::VOCAB nor RetryPolicy::ALIASES nor the
 * explicit worker-only transport list below.
 *
 * Dependency-free: plain regex over the Python source text, anchored on
 * the call sites, never on every string in the file.
 */
final class WorkerVocabularyTest extends TestCase
{
	/**
	 * Values the worker legitimately passes as `reason` that are NOT policy
	 * codes. Every entry needs its one-line reason and its file:line.
	 */
	private const WORKER_ONLY_TRANSPORT = [
		// runner.py: the fallback free-text reason for a skip the engine did
		// not explain; the app stores free text in `reason`, policy rides on
		// `reason_code`, which is never this value.
		'skipped' => 'runner.py — free-text fallback for `reason`, never a reason_code',
		// webhook_server.py keeps a LOCAL index of its own (the legacy
		// standalone mode); these are its own labels for rows in that index
		// and are never sent to this app as a code.
		'foreign_user' => 'webhook_server.py — local index label, legacy standalone mode, never sent as a code',
		'recently_processed' => 'webhook_server.py — local index label, never sent as a code',
		'versions' => 'webhook_server.py — local index label, never sent as a code',
		'file_removed' => 'webhook_server.py — local index label for a file gone from WebDAV, never sent as a code',
		'uninteresting_event' => 'webhook_server.py — webhook response label for an event the worker ignores, never sent as a code',
		'no_path' => 'webhook_server.py — webhook response label for an event without a path, never sent as a code',
		'trashbin' => 'webhook_server.py — webhook response label for a trashbin path, never sent as a code',
		'foreign_rewrite' => 'webhook_server.py — REASON_FOREIGN_REWRITE, the fingerprint guard\'s label in the worker\'s own index, never sent as a code',
	];

	public function testEveryWorkerReasonCodeIsKnownToTheApp(): void
	{
		$known = array_merge(
			$this->constant('VOCAB'),
			$this->constant('ALIASES'),
			[RetryPolicy::CODE_UNKNOWN, RetryPolicy::CODE_WORKER_SENT_UNKNOWN],
			array_keys(self::WORKER_ONLY_TRANSPORT),
		);

		$offenders = [];
		foreach ($this->emittedCodes() as $code => $where) {
			if (!in_array($code, $known, true)) {
				$offenders[] = sprintf('%s — "%s" is not in RetryPolicy::VOCAB or ALIASES — add the code (with a verdict) to RetryPolicy, map it in ALIASES, or stop the worker emitting it',
					$where, $code);
			}
		}

		$this->assertSame([], $offenders,
			"The worker emits reason codes the app has never heard of:\n  "
			. implode("\n  ", $offenders) . "\n"
			. "The app records an unknown code as `unknown` and its verdict — not the\n"
			. "worker's intent — then decides the row's fate. Keep the two sides in lockstep.");
	}

	/**
	 * code => "python/file.py:line (how it was found)"
	 *
	 * @return array<string, string>
	 */
	private function emittedCodes(): array
	{
		$root = dirname(__DIR__, 3); // tests/Unit/Service -> repo root
		$found = [];

		$files = [];
		$stack = [$root . '/worker/lib/nc_ocr_flow'];
		while ($stack !== []) {
			$dir = array_pop($stack);
			foreach (scandir($dir) ?: [] as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				$path = $dir . '/' . $entry;
				if (is_dir($path)) {
					$stack[] = $path;
				} elseif (str_ends_with($path, '.py')) {
					$files[] = $path;
				}
			}
		}
		sort($files);

		foreach ($files as $file) {
			$source = (string) file_get_contents($file);
			// Comment-only lines (# and SQL's --) are prose, not emissions:
			// jobstore.py discusses reason='foreign_rewrite' in a comment.
			$source = preg_replace('/^\s*(#|--).*$/m', '', $source) ?? $source;
			$rel = ltrim(substr($file, strlen($root) + 1), '/');
			$lines = explode("\n", $source);
			$lineOf = static function (int $offset) use ($lines, $source): int {
				return substr_count(substr($source, 0, $offset), "\n") + 1;
			};

			$patterns = [
				// reason="code" / reason_code='code' keyword arguments
				'/(?<![\w"\'])(?:reason|reason_code)\s*=\s*["\']([a-z][a-z0-9]*(?:_[a-z0-9]+)*)["\']/',
				// {"reason": "code"} payload dicts (webhook responses)
				'/["\']reason(?:_code)?["\']\s*:\s*["\']([a-z][a-z0-9]*(?:_[a-z0-9]+)*)["\']/',
			];
			foreach ($patterns as $pattern) {
				preg_match_all($pattern, $source, $m, PREG_OFFSET_CAPTURE);
				foreach ($m[1] as $match) {
					$found[$match[0]] ??= sprintf('%s:%d (reason argument)', $rel, $lineOf($match[1]));
				}
			}

			// REASON_* module constants
			if (preg_match_all('/^(REASON_[A-Z0-9_]*)\s*=\s*["\']([a-z][a-z0-9]*(?:_[a-z0-9]+)*)["\']$/m', $source, $m, PREG_OFFSET_CAPTURE)) {
				foreach ($m[2] as $i => $match) {
					$found[$match[0]] ??= sprintf('%s:%d (constant %s)', $rel, $lineOf($match[1]), $m[1][$i][0]);
				}
			}

			// The failure classifier: every returned literal is a code.
			foreach (['classify_failure', '_classify_by_text'] as $fn) {
				if (preg_match('/def\s+' . $fn . '\s*\(.*?\):(.*?)(?=^def\s|^class\s|\Z)/ms', $source, $body, PREG_OFFSET_CAPTURE)) {
					if (preg_match_all('/["\']([a-z][a-z0-9]*(?:_[a-z0-9]+)*)["\']/', $body[1][0], $returns, PREG_OFFSET_CAPTURE)) {
						foreach ($returns[1] as $match) {
							$found[$match[0]] ??= sprintf('%s:%d (%s return)', $rel, $lineOf($body[1][1] + $match[1]), $fn);
						}
					}
				}
			}

			// The verdict table: both the old and the new spelling are codes
			// a worker (of some vintage) may emit.
			if (preg_match('/VERDICT_CODES[^{]*\{(.*?)\n\}/ms', $source, $table, PREG_OFFSET_CAPTURE)) {
				if (preg_match_all('/["\']([a-z][a-z0-9]*(?:_[a-z0-9]+)*)["\']/', $table[1][0], $codes, PREG_OFFSET_CAPTURE)) {
					foreach ($codes[1] as $match) {
						$found[$match[0]] ??= sprintf('%s:%d (VERDICT_CODES)', $rel, $lineOf($table[1][1] + $match[1]));
					}
				}
			}
		}

		return $found;
	}

	/**
	 * The keys of a private RetryPolicy constant.
	 *
	 * @return list<string>
	 */
	private function constant(string $name): array
	{
		$reflection = new \ReflectionClass(RetryPolicy::class);
		$value = $reflection->getConstant($name);
		$this->assertIsArray($value, "RetryPolicy::$name must be an array");
		return array_keys($value);
	}
}
