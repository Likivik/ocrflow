<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\OutcomeCatalog;
use OCA\OcrFlow\Service\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Worker ↔ app vocabulary drift.
 *
 * A worker must never emit a reason code the app has never heard of: the
 * app records anything it does not know as `unknown` and the row's verdict
 * then decides its fate, so one invented spelling silently changes policy.
 *
 * It must also never emit an OLD spelling as a code. That is the defect
 * this guard was widened for: `broken_pdf` was a legitimate `ALIASES` entry,
 * so a worker sending it with no `reason_code` passed the old check while
 * `jobs.reason_code` stayed NULL, and only a read-time alias knew what the
 * row meant. A code in a job row is a machine fact; it cannot be a word
 * that exists only in another class's translation table.
 *
 * So the rule is: every token the worker can put on the wire is either
 *   * a canonical `RetryPolicy::VOCAB` code, or
 *   * a key of the worker's own `REASON_CODES` table (an explicit
 *     word → canonical mapping in stage_events.py), or
 *   * declared prose-only / local-index by name, with a one-line reason.
 * And every `REASON_CODES` value must be canonical, with every old spelling
 * agreeing with `RetryPolicy::ALIASES` so old and new rows read alike.
 *
 * Dependency-free: plain regex over the Python source text, anchored on
 * the call sites, never on every string in the file.
 */
final class WorkerVocabularyTest extends TestCase
{
	/**
	 * Values the worker legitimately passes as `reason` that are NOT policy
	 * codes and carry no canonical meaning. Every entry needs its one-line
	 * reason and its file:line.
	 */
	private const WORKER_ONLY_TRANSPORT = [
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
		// SKIP_REASON_* prose labels. They name a decision the app has no
		// policy for; the free text is the whole answer.
		'source_image_kept' => 'webhook_server.py — SKIP_REASON_SOURCE_KEPT, reported on a SUCCESSFUL image conversion, never as a skip code',
		'born_digital_or_stamped_good' => 'webhook_server.py — SKIP_REASON_LEGACY, pre-0.1.5 spellings, not emitted by this worker',
		'text_density_guard_low' => 'webhook_server.py — SKIP_REASON_TEXT_DENSITY_GUARD, legacy constant, not emitted by this worker',
	];

	public function testEveryWorkerReasonTokenIsCanonicalOrExplicitlyMapped(): void
	{
		$table = $this->reasonCodes();
		$known = array_merge(
			$this->constant('VOCAB'),
			array_keys($table),
			$this->proseOnlyReasons(),
			array_keys(self::WORKER_ONLY_TRANSPORT),
		);

		$offenders = [];
		foreach ($this->emittedCodes() as $code => $where) {
			// A token that is only in ALIASES is the bug: it is a read-time
			// translation being emitted as if it were a code.
			if (!in_array($code, $known, true)) {
				$offenders[] = sprintf('%s — "%s" is neither a canonical RetryPolicy::VOCAB code nor a key of the worker\'s REASON_CODES table nor a declared prose-only label — give it an explicit entry (word => canonical code) in stage_events.py, make it a VOCAB code, or stop the worker emitting it',
					$where, $code);
			}
		}

		$this->assertSame([], $offenders,
			"The worker emits reason tokens the app cannot store as a code:\n  "
			. implode("\n  ", $offenders) . "\n"
			. "A job row must carry a canonical code, not an alias that only a\n"
			. "read path knows how to translate. Keep the two sides in lockstep.");
	}

	/**
	 * The table itself: every mapping lands on a code the app owns, and every
	 * old spelling it accepts agrees with the app's alias map.
	 */
	public function testEveryWorkerMappingLandsOnACanonicalCode(): void
	{
		$vocab = $this->constant('VOCAB');
		$aliases = $this->constantValue('ALIASES');
		$table = $this->reasonCodes();
		$this->assertNotEmpty($table, 'stage_events.py must define REASON_CODES');

		$offenders = [];
		foreach ($table as $word => $code) {
			if (!in_array($code, $vocab, true)) {
				$offenders[] = sprintf('REASON_CODES["%s"] = "%s" — not a canonical RetryPolicy::VOCAB code', $word, $code);
				continue;
			}
			if (in_array($word, $vocab, true)) {
				continue; // already canonical, nothing to translate
			}
			if (!array_key_exists($word, $aliases)) {
				$offenders[] = sprintf('REASON_CODES["%s"] — old spelling with no RetryPolicy::ALIASES entry; a pre-fix row in this spelling would not read as "%s"', $word, $code);
			} elseif ($aliases[$word] !== $code) {
				$offenders[] = sprintf('REASON_CODES["%s"] = "%s" but RetryPolicy::ALIASES maps it to "%s"', $word, $code, $aliases[$word]);
			}
		}

		$this->assertSame([], $offenders,
			"The worker's reason table and the app's vocabulary disagree:\n  " . implode("\n  ", $offenders));
	}

	/**
	 * REASON_CODES and PROSE_ONLY_REASONS, read from the worker source.
	 *
	 * @return array<string, string>
	 */
	private function reasonCodes(): array
	{
		$source = (string) file_get_contents($this->workerFile('stage_events.py'));
		if (!preg_match('/REASON_CODES:\s*dict\[str,\s*str\]\s*=\s*\{(.*?)\n\}/s', $source, $block)) {
			$this->fail('stage_events.py must define REASON_CODES: dict[str, str] = { ... }');
			return [];
		}
		preg_match_all('/"([a-z][a-z0-9_]*)":\s*"([a-z][a-z0-9_]*)"/', $block[1], $pairs);
		$table = [];
		foreach ($pairs[1] as $i => $word) {
			$table[$word] = $pairs[2][$i];
		}
		return $table;
	}

	/**
	 * @return list<string>
	 */
	private function proseOnlyReasons(): array
	{
		$source = (string) file_get_contents($this->workerFile('stage_events.py'));
		if (!preg_match('/PROSE_ONLY_REASONS[^=]*=\s*frozenset\(\s*\{(.*?)\}\s*\)/s', $source, $block)) {
			$this->fail('stage_events.py must declare PROSE_ONLY_REASONS');
			return [];
		}
		preg_match_all('/"([a-z][a-z0-9_]*)"/', $block[1], $m);
		return $m[1];
	}

	private function workerFile(string $name): string
	{
		return dirname(__DIR__, 3) . '/worker/lib/nc_ocr_flow/' . $name;
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

			// REASON_* / SKIP_REASON_* module constants — the pipeline's
			// verdicts and the legacy labels it still carries.
			if (preg_match_all('/^([A-Z][A-Z0-9_]*)\s*=\s*["\']([a-z][a-z0-9]*(?:_[a-z0-9]+)*)["\']$/m', $source, $m, PREG_OFFSET_CAPTURE)) {
				foreach ($m[2] as $i => $match) {
					if (!str_contains($m[1][$i][0], 'REASON')) {
						continue;
					}
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

			// The reason table: keys are the worker's words, values are the
			// codes it sends. Both must be explainable.
			if (preg_match('/REASON_CODES[^{]*\{(.*?)\n\}/ms', $source, $table, PREG_OFFSET_CAPTURE)) {
				if (preg_match_all('/["\']([a-z][a-z0-9]*(?:_[a-z0-9]+)*)["\']/', $table[1][0], $codes, PREG_OFFSET_CAPTURE)) {
					foreach ($codes[1] as $match) {
						$found[$match[0]] ??= sprintf('%s:%d (REASON_CODES)', $rel, $lineOf($table[1][1] + $match[1]));
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
		$reflection = new \ReflectionClass(OutcomeCatalog::class);
		$value = $reflection->getConstant($name);
		$this->assertIsArray($value, "RetryPolicy::$name must be an array");
		return array_keys($value);
	}

	/**
	 * The raw value of a private RetryPolicy constant.
	 *
	 * @return array<string, mixed>
	 */
	private function constantValue(string $name): array
	{
		$reflection = new \ReflectionClass(OutcomeCatalog::class);
		$value = $reflection->getConstant($name);
		$this->assertIsArray($value, "RetryPolicy::$name must be an array");
		return $value;
	}
}
