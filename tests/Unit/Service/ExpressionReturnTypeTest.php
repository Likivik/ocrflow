<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\Attention;
use PHPUnit\Framework\TestCase;

/**
 * A class-wide static tripwire for the shipped 0.1.6 bug: a method whose
 * body returns expr()->orX()/andX() — a CompositeExpression — cannot be
 * declared `: string`. That mismatch killed both front doors of the
 * needs_fix worklist (occ ocrflow:errors, GET /api/v1/attention) on the
 * real Nextcloud while the unit suite stayed green, because no test ever
 * built an expression. This walks every PHP file under lib/ and refuses
 * the mismatch anywhere, not just in Attention, so the bug cannot move
 * house.
 *
 * The one legitimate escape is an explicit `(string)` cast on the return
 * statement: if someone really wants the SQL text, they must say so.
 *
 * Dependency-free on purpose: token_get_all over plain file text, no new
 * package, runs in the ordinary PHPUnit suite.
 */
final class ExpressionReturnTypeTest extends TestCase
{
	/** No method anywhere in lib/ returns a composite expression under a `string` declaration. */
	public function testNoMethodReturningACompositeExpressionIsDeclaredString(): void
	{
		$offenders = [];
		foreach ($this->libFiles() as $file) {
			$offenders = array_merge($offenders, $this->stringTypedCompositeReturns($file));
		}
		$this->assertSame([], $offenders,
			"These methods return expr()->orX()/andX() (a CompositeExpression) but are declared `: string`:\n  "
			. implode("\n  ", $offenders) . "\n"
			. "A CompositeExpression cannot satisfy a string return type; declare ICompositeExpression\n"
			. "(see Queue::purgeablePredicate) or cast the return with (string) if SQL text is meant.");
	}

	/** The concrete production failure, pinned by name so it cannot slip back silently. */
	public function testAttentionPredicateIsNotDeclaredString(): void
	{
		$method = new \ReflectionMethod(Attention::class, 'predicate');
		$this->assertNotSame('string', $method->getReturnType()?->getName(),
			'Attention::predicate() must not declare a string return: it returns expr()->orX().');
	}

	/**
	 * @return list<string> absolute paths of every PHP file under lib/
	 */
	private function libFiles(): array
	{
		$root = dirname(__DIR__, 3); // tests/Unit/Service -> repo root
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

	/**
	 * Every method in $file that (a) is declared to return string and
	 * (b) returns expr()->orX()/andX() without an explicit (string) cast.
	 *
	 * @return list<string> "relative/path:line" for each offender
	 */
	private function stringTypedCompositeReturns(string $file): array
	{
		$source = file_get_contents($file);
		if ($source === false
			|| (!str_contains($source, '->expr()->orX(') && !str_contains($source, '->expr()->andX('))) {
			return [];
		}

		$root = dirname(__DIR__, 3);
		$rel = ltrim(substr($file, strlen($root) + 1), '/');
		$tokens = token_get_all($source);
		$count = count($tokens);
		$offenders = [];

		for ($i = 0; $i < $count; $i++) {
			if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
				continue;
			}
			$line = $tokens[$i][2];

			// A named method is `function name(...)`; a closure is
			// `function (...)`. Only methods carry the declaration we check.
			$next = $i + 1;
			while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
				$next++;
			}
			if ($next >= $count || !is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING) {
				continue; // closure or arrow fn, not a method
			}

			// The signature runs to the opening brace of the body (or `;`
			// for an abstract method, which has no body to check).
			$sig = '';
			$j = $i + 1;
			while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
				$sig .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
				$j++;
			}
			if ($j >= $count || $tokens[$j] === ';') {
				continue; // abstract/interface: no body
			}

			// Declared return type: the segment after the last ':' of the
			// signature. `string` or `?string` (or a union holding only
			// string) is the lie we are hunting.
			if (!preg_match('/:\s*(\??string)\s*$/', rtrim($sig, " \t\r\n"), $m)) {
				continue; // not string-typed (or no return type at all)
			}

			// The body: from the opening brace to its match, brace-counted.
			$depth = 0;
			$body = '';
			$k = $j;
			while ($k < $count) {
				if ($tokens[$k] === '{') {
					$depth++;
				} elseif ($tokens[$k] === '}') {
					$depth--;
					if ($depth === 0) {
						break;
					}
				}
				$body .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
				$k++;
			}

			foreach (['->expr()->orX(', '->expr()->andX('] as $call) {
				foreach ($this->returnStatementsContaining($body, $call) as $stmt) {
					if (!preg_match('/return\s*\(\s*string\s*\)/', $stmt)) {
						$offenders[] = sprintf('%s:%d', $rel, $line);
						break 2; // one report per method is enough
					}
				}
			}

			// Skip past this method's body: methods cannot nest, and this
			// keeps closures inside it from being rescanned as methods.
			$i = $k;
		}
		return $offenders;
	}

	/**
	 * The text of every `return ...;` statement in $body whose expression
	 * text contains $needle.
	 *
	 * @return list<string>
	 */
	private function returnStatementsContaining(string $body, string $needle): array
	{
		$stmts = [];
		$offset = 0;
		while (($pos = strpos($body, 'return', $offset)) !== false) {
			$end = strpos($body, ';', $pos);
			if ($end === false) {
				$end = strlen($body) - 1;
			}
			$stmt = substr($body, $pos, $end - $pos);
			if (str_contains($stmt, $needle)) {
				$stmts[] = $stmt;
			}
			$offset = $pos + 6;
		}
		return $stmts;
	}
}
