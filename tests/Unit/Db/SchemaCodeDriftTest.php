<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

/**
 * Migration ↔ code column drift, as a class-wide tripwire.
 *
 * Two shipped bugs were this exact family: a getter silently dropped
 * `reason_code` (every needs_fix row looked uncoded — unlistable and
 * unretryable), and code kept reading `claimed_by` after a migration
 * dropped it. Both passed a green unit suite because the row shape is a
 * property of the schema, not of the data.
 *
 * This test parses lib/Migration/*.php for the columns each table ends up
 * with (addColumn / dropColumn / renameColumn, applied in version order)
 * and then refuses, anywhere in lib/Db and lib/Service:
 *
 *  - a `$row['<col>']` read, `->set('<col>')`, `->select('<col>')`,
 *    `->values(['<col>' => …])` write, or a first-argument column of the
 *    query-builder expression methods, naming a column no migration
 *    produces;
 *  - an Entity (Job) property, @method getter/setter or fromRow()/toApi()
 *    key that its table does not have.
 *
 * Dependency-free on purpose: token_get_all over plain file text, no new
 * package, runs in the ordinary PHPUnit suite.
 */
final class SchemaCodeDriftTest extends TestCase
{
	public function testCodeOnlyNamesColumnsAMigrationProduces(): void
	{
		$columns = $this->finalColumns();
		$all = [];
		foreach ($columns as $cols) {
			$all = array_merge($all, $cols);
		}
		$known = array_flip($all);
		// Aliases a SELECT invents (selectAlias('COUNT(*)', 'total')) are
		// legitimate row keys even though no table has them.
		foreach ($this->selectAliases() as $alias) {
			$known[$alias] = true;
		}

		$offenders = [];
		foreach ($this->phpFilesIn(['lib/Db', 'lib/Service']) as $file) {
			$offenders = array_merge($offenders, $this->unknownColumnReferences($file, $known));
		}
		$this->assertSame([], $offenders,
			"These column references name a column no migration produces:\n  "
			. implode("\n  ", $offenders) . "\n"
			. "Either add the column in a NEW lib/Migration step (never edit a shipped one),\n"
			. "or fix the reference — the schema is the only source of column names.");
	}

	public function testJobEntityMatchesItsTable(): void
	{
		$columns = $this->finalColumns();
		$this->assertArrayHasKey('ocrflow_jobs', $columns,
			'no migration builds ocrflow_jobs — the entity guard cannot run');
		$jobs = array_flip($columns['ocrflow_jobs']);

		$file = $this->repoRoot() . '/lib/Db/Job.php';
		$source = (string) file_get_contents($file);

		// Every protected property, snake_cased, must be a column.
		preg_match_all('/^\tprotected\s+[?\\\\A-Za-z]+\s+\$(\w+)\s*=/m', $source, $props, PREG_OFFSET_CAPTURE);
		$offenders = [];
		foreach ($props[1] as $m) {
			$column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $m[0]));
			if (!isset($jobs[$column])) {
				$offenders[] = sprintf('lib/Db/Job.php:%d — property $%s maps to column "%s", which ocrflow_jobs does not have — add it in a new migration or drop the property',
					$this->lineAt($source, $m[1]), $m[0], $column);
			}
		}

		// Every @method getter/setter must name one of those properties. `id`
		// lives on the Entity base class, not on Job — inherited, not drift.
		foreach ($this->entityMethodNames($source) as [$name, $offset]) {
			$property = lcfirst(preg_replace('/^(get|set|is)/', '', $name));
			if ($property !== 'id' && !in_array($property, array_column($props[1], 0), true)) {
				$offenders[] = sprintf('lib/Db/Job.php:%d — @method %s() names $%s, which is not a Job property — the row shape and the entity disagree',
					$this->lineAt($source, $offset), $name, $property);
			}
		}

		// Every fromRow()/toApi() key must be a column of the table.
		foreach (['fromRow', 'toApi'] as $method) {
			$body = $this->methodBody($source, $method);
			if ($body === null) {
				$offenders[] = sprintf('lib/Db/Job.php — %s() is missing — the row shape has no single owner', $method);
				continue;
			}
			preg_match_all("/'([a-z][a-z0-9_]+)'/", $body[1], $keys);
			foreach (array_unique($keys[1]) as $key) {
				if (!isset($jobs[$key])) {
					$offenders[] = sprintf('lib/Db/Job.php:%d — %s() touches "%s", which ocrflow_jobs does not have — add it in a new migration or stop reading/writing it',
						$this->lineAt($source, $body[0]) , $method, $key);
				}
			}
		}

		$this->assertSame([], $offenders,
			"The Job entity and the ocrflow_jobs schema disagree:\n  "
			. implode("\n  ", $offenders) . "\n"
			. "This is the family that shipped twice: a dropped getter and a read of a dropped column.");
	}

	// ------------------------------------------------------------------ schema

	/**
	 * table => list<column>, after every migration ran in version order.
	 *
	 * @return array<string, list<string>>
	 */
	private function finalColumns(): array
	{
		$tables = [];
		foreach (glob($this->repoRoot() . '/lib/Migration/Version*.php') ?: [] as $file) {
			$tokens = $this->flatTokens((string) file_get_contents($file));
			$bound = []; // var name => table name

			for ($i = 0, $n = count($tokens); $i < $n; $i++) {
				[$id, $text] = $tokens[$i];

				// `$x = ...->createTable('t')` / `->getTable('t')` binds $x.
				if ($id === T_VARIABLE && ($tokens[$i + 1][1] ?? '') === '=') {
					for ($j = $i + 2; $j < min($i + 12, $n); $j++) {
						if (in_array($tokens[$j][1], ['createTable', 'getTable'], true)) {
							$table = $this->nextString($tokens, $j);
							if ($table !== null) {
								$bound[$text] = $table;
								$tables[$table] ??= [];
							}
							break;
						}
						if ($tokens[$j][1] === ';') {
							break;
						}
					}
					continue;
				}

				// `$x->addColumn('c', ...)`, `dropColumn('c')`, `renameColumn('a','b')`.
				if ($id === T_VARIABLE && isset($bound[$text])
					&& ($tokens[$i + 1][1] ?? null) === '->'
					&& in_array($tokens[$i + 2][1] ?? '', ['addColumn', 'dropColumn', 'renameColumn'], true)) {
					$op = $tokens[$i + 2][1];
					$table = $bound[$text];
					$tables[$table] ??= [];
					if ($op === 'renameColumn') {
						$old = $this->nextString($tokens, $i + 2);
						$new = $this->nextString($tokens, $i + 2, 2);
						if ($old !== null && $new !== null) {
							$tables[$table] = array_values(array_diff($tables[$table], [$old]));
							$tables[$table][] = $new;
						}
					} else {
						$column = $this->nextString($tokens, $i + 2);
						if ($column !== null) {
							if ($op === 'addColumn') {
								if (!in_array($column, $tables[$table], true)) {
									$tables[$table][] = $column;
								}
							} else {
								$tables[$table] = array_values(array_diff($tables[$table], [$column]));
							}
						}
					}
				}
			}
		}
		ksort($tables);
		return $tables;
	}

	/**
	 * Every alias literal handed to selectAlias() — a legal row key.
	 *
	 * @return list<string>
	 */
	private function selectAliases(): array
	{
		$aliases = [];
		foreach ($this->phpFilesIn(['lib/Db', 'lib/Service']) as $file) {
			$tokens = $this->flatTokens((string) file_get_contents($file));
			for ($i = 0, $n = count($tokens); $i < $n; $i++) {
				if (($tokens[$i][1] ?? '') === 'selectAlias') {
					// The last string literal at depth 1 of the call.
					$depth = 0;
					$last = null;
					for ($j = $i + 1; $j < $n; $j++) {
						$t = $tokens[$j][1];
						if ($t === '(') {
							$depth++;
						} elseif ($t === ')') {
							$depth--;
							if ($depth === 0) {
								break;
							}
						} elseif ($tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING && $depth === 1) {
							$last = $this->unquote($t);
						}
					}
					if ($last !== null) {
						$aliases[] = $last;
					}
				}
			}
		}
		return array_values(array_unique($aliases));
	}

	// ------------------------------------------------------------------- code scan

	/**
	 * Column references in $file that $known does not contain.
	 *
	 * @param array<string, true> $known
	 * @return list<string> "path:line — reference — fix"
	 */
	private function unknownColumnReferences(string $file, array $known): array
	{
		$source = (string) file_get_contents($file);
		$tokens = $this->flatTokens($source);
		$rel = $this->relative($file);
		$offenders = [];

		$columnPositionMethods = [ // first argument is a column (possibly "alias.column")
			'set', 'select', 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'notIn',
			'like', 'ilike', 'isNull', 'isNotNull', 'orderBy', 'addOrderBy', 'groupBy',
		];

		for ($i = 0, $n = count($tokens); $i < $n; $i++) {
			[$id, $text] = $tokens[$i];
			$column = null;
			$line = null;

			if ($id === T_VARIABLE && in_array($text, ['$row', '$rows'], true)) {
				// $row['col'] / $rows[0]['col']
				$j = $i + 1;
				while ($j < $n && $tokens[$j][1] === '[') {
					$next = $tokens[$j + 1] ?? null;
					if ($next !== null && $next[0] === T_CONSTANT_ENCAPSED_STRING
						&& ($tokens[$j + 2][1] ?? null) === ']') {
						$column = $this->unquote($next[1]);
						$line = $next[2];
						break;
					}
					if ($next !== null && $next[0] === T_LNUMBER) {
						$j += 3; // skip one numeric dimension ($rows[0])
					} else {
						break;
					}
				}
			} elseif ($id === T_STRING && ($tokens[$i - 1][1] ?? '') === '->'
				&& in_array($text, array_merge($columnPositionMethods, ['values']), true)
				&& ($tokens[$i + 1][1] ?? '') === '(') {
				if ($text === 'values') {
					// ->values(['col' => …]) — the keys of the row literal.
					foreach ($this->arrayLiteralKeys($tokens, $i) as $key) {
						if ($this->looksLikeAColumn($key) && !isset($known[$key])) {
							$offenders[] = sprintf('%s:%d — values() key "%s" is not a column any migration produces — add it in a new lib/Migration step or fix the key',
								$rel, $line ?? 0, $key);
						}
					}
					continue;
				}
				// ->method('col', ...) — the first string literal at depth 1.
				$depth = 0;
				for ($j = $i + 1; $j < $n; $j++) {
					$t = $tokens[$j][1];
					if ($t === '(') {
						$depth++;
					} elseif ($t === ')') {
						$depth--;
						if ($depth === 0) {
							break;
						}
					} elseif ($tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING && $depth === 1) {
						$column = $this->unquote($t);
						$line = $tokens[$j][2];
						break;
					}
				}
			}

			if ($column !== null && $this->looksLikeAColumn($column)) {
				$name = $column;
				if (str_contains($name, '.')) { // "j.claimed_by_worker_id"
					$name = substr($name, strrpos($name, '.') + 1);
				}
				if (!isset($known[$name])) {
					$offenders[] = sprintf('%s:%d — "%s" is not a column any migration produces — add it in a new lib/Migration step or fix the reference',
						$rel, $line ?? 0, $column);
				}
			}
		}

		// `$values = ['col' => …]` — the row literal a ->values($values) call
		// later sends (Queue::writeLedger's shape). Keys are columns there.
		if (preg_match('/\$values\s*=\s*\[/', $source, $m, PREG_OFFSET_CAPTURE)) {
			$open = strpos($source, '[', $m[0][1]);
			$close = $this->matchingBracket($source, $open);
			if ($close !== null
				&& preg_match_all("/'([a-z][a-z0-9_]*)'\s*=>/", substr($source, $open, $close - $open), $keys)) {
				foreach (array_unique($keys[1]) as $key) {
					if ($this->looksLikeAColumn($key) && !isset($known[$key])) {
						$offenders[] = sprintf('%s:%d — row-literal key "%s" is not a column any migration produces — add it in a new lib/Migration step or fix the key',
							$rel, $this->lineAt($source, $open), $key);
					}
				}
			}
		}

		sort($offenders);
		return array_values(array_unique($offenders));
	}

	/**
	 * The string keys of the array literals inside the ->values(...) call at
	 * token index $call.
	 *
	 * @return list<string>
	 */
	private function arrayLiteralKeys(array $tokens, int $call): array
	{
		$keys = [];
		$depth = 0;
		for ($j = $call + 1, $n = count($tokens); $j < $n; $j++) {
			$t = $tokens[$j][1];
			if ($t === '(') {
				$depth++;
			} elseif ($t === ')' || $t === ';') {
				if ($t === ')') {
					$depth--;
					if ($depth === 0) {
						break;
					}
				} else {
					break;
				}
			} elseif ($tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING
				&& ($tokens[$j + 1][1] ?? null) === T_DOUBLE_ARROW) {
				$keys[] = $this->unquote($t);
			}
		}
		return $keys;
	}

	private function matchingBracket(string $source, int $open): ?int
	{
		$depth = 0;
		for ($i = $open, $n = strlen($source); $i < $n; $i++) {
			if ($source[$i] === '[') {
				$depth++;
			} elseif ($source[$i] === ']') {
				$depth--;
				if ($depth === 0) {
					return $i;
				}
			}
		}
		return null;
	}

	/**
	 * Column-shaped: snake_case identifier with at least one underscore, or
	 * one of the single-word column names this schema actually uses. Free-text
	 * keys ('exception' in a log context, 'label' in an API shape) are not
	 * column claims and are not checked.
	 */
	private function looksLikeAColumn(string $name): bool
	{
		$singleWord = ['id', 'path', 'status', 'reason', 'engine', 'etag', 'size', 'pages', 'chars', 'error', 'name', 'value', 'version', 'stage'];
		return (bool) preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)+$/', $name)
			|| in_array($name, $singleWord, true);
	}

	// ------------------------------------------------------------------- helpers

	/**
	 * Flat token list: [ [token id, text, line], … ], comments stripped.
	 */
	private function flatTokens(string $source): array
	{
		$flat = [];
		$line = 1;
		foreach (token_get_all($source) as $tok) {
			if (is_array($tok)) {
				if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
					$line = $tok[2] + substr_count($tok[1], "\n");
					continue;
				}
				$flat[] = [$tok[0], $tok[1], $tok[2]];
				$line = $tok[2];
			} else {
				$flat[] = [-1, $tok, $line];
			}
		}
		return $flat;
	}

	/** The next string literal after index $from, skipping $skip of them. */
	private function nextString(array $tokens, int $from, int $skip = 1): ?string
	{
		$seen = 0;
		for ($j = $from + 1, $n = count($tokens); $j < $n; $j++) {
			if ($tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
				$seen++;
				if ($seen === $skip) {
					return $this->unquote($tokens[$j][1]);
				}
			}
			if ($tokens[$j][1] === ';' || $tokens[$j][1] === ')') {
				return null;
			}
		}
		return null;
	}

	private function unquote(string $literal): string
	{
		return substr($literal, 1, -1);
	}

	/**
	 * [offset, body] of a method, or null when absent.
	 *
	 * @return array{0: int, 1: string}|null
	 */
	private function methodBody(string $source, string $name): ?array
	{
		if (!preg_match('/function\s+' . $name . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
			return null;
		}
		$open = strpos($source, '{', $m[0][1]);
		$depth = 0;
		for ($i = $open, $n = strlen($source); $i < $n; $i++) {
			if ($source[$i] === '{') {
				$depth++;
			} elseif ($source[$i] === '}') {
				$depth--;
				if ($depth === 0) {
					return [$open, substr($source, $open, $i - $open)];
				}
			}
		}
		return null;
	}

	/**
	 * @return list<array{0: string, 1: int}> @method names with their offsets
	 */
	private function entityMethodNames(string $source): array
	{
		preg_match_all('/@method\s+[?\\\\A-Za-z]+\s+(\w+)\(\)/', $source, $m, PREG_OFFSET_CAPTURE);
		return $m[1];
	}

	private function lineAt(string $source, int $offset): int
	{
		return substr_count(substr($source, 0, $offset), "\n") + 1;
	}

	private function repoRoot(): string
	{
		return dirname(__DIR__, 3); // tests/Unit/Db -> repo root
	}

	/**
	 * @param list<string> $dirs relative to the repo root
	 * @return list<string> absolute paths
	 */
	private function phpFilesIn(array $dirs): array
	{
		$files = [];
		foreach ($dirs as $dir) {
			foreach (glob($this->repoRoot() . '/' . $dir . '/*.php') ?: [] as $file) {
				$files[] = $file;
			}
		}
		sort($files);
		return $files;
	}

	private function relative(string $file): string
	{
		return ltrim(substr($file, strlen($this->repoRoot()) + 1), '/');
	}
}
