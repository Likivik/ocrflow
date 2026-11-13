<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Attention;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Attention with the three SQL seam methods replaced by an in-memory store
 * that applies the SAME predicates the production SQL applies (both the
 * spelling set and the reason_code/reason fallback), plus newest-first
 * ordering, limit and offset.
 *
 * Same pattern as StoreQueue in QueueRetentionTest: the mapping, the
 * cap and the retry flow stay production code; only the row I/O is faked.
 */
class StoreAttention extends Attention
{
	/** @var list<array<string, mixed>> raw job rows, any order */
	public array $rows = [];

	/** @var list<array{limit: int, offset: int, spellings: list<string>, prefix: ?string}> */
	public array $rowCalls = [];

	/** @var list<array{limit: int, spellings: list<string>}> */
	public array $idCalls = [];

	/** @var list<?string> the prefix each total() was asked to scope to */
	public array $totalCalls = [];

	/** @var list<string> */
	public array $logLines = [];

	public function __construct(
		public Queue $queue,
		?RetryPolicy $retryPolicy = null,
		?IConfig $config = null,
		?IDBConnection $db = null,
	) {
		parent::__construct(
			$queue,
			$retryPolicy ?? new RetryPolicy(),
			$config ?? self::config(),
			new class ($this) implements \Psr\Log\LoggerInterface {
				public function __construct(private StoreAttention $store)
				{
				}

				public function emergency($message, array $context = []): void
				{
					$this->store->logLines[] = $message;
				}

				public function alert($message, array $context = []): void
				{
					$this->store->logLines[] = $message;
				}

				public function critical($message, array $context = []): void
				{
					$this->store->logLines[] = $message;
				}

				public function error($message, array $context = []): void
				{
					$this->store->logLines[] = $message;
				}

				public function warning($message, array $context = []): void
				{
					$this->store->logLines[] = $message;
				}

				public function notice($message, array $context = []): void
				{
					$this->store->logLines[] = $message;
				}

				public function info($message, array $context = []): void
				{
					$this->store->logLines[] = $message;
				}

				public function debug($message, array $context = []): void
				{
				}

				public function log($level, $message, array $context = []): void
				{
					$this->store->logLines[] = $message;
				}
			},
			$db,
		);
	}

	public static function config(string $cap = '500'): IConfig
	{
		$config = new class ($cap) implements IConfig {
			public function __construct(private string $cap)
			{
			}

			public function setSystemValues(array $configs)
			{
			}

			public function setSystemValue($key, $value)
			{
			}

			public function getSystemValue($key, $default = '')
			{
				return $default;
			}

			public function getSystemValueBool(string $key, bool $default = false): bool
			{
				return $default;
			}

			public function getSystemValueInt(string $key, int $default = 0): int
			{
				return $default;
			}

			public function getSystemValueString(string $key, string $default = ''): string
			{
				return $default;
			}

			public function getFilteredSystemValue($key, $default = '')
			{
				return $default;
			}

			public function deleteSystemValue($key)
			{
			}

			public function getAppKeys($appName)
			{
				return [];
			}

			public function setAppValue($appName, $key, $value)
			{
			}

			public function getAppValue($appName, $key, $default = '')
			{
				return $key === 'attention_retry_cap' ? $this->cap : $default;
			}

			public function deleteAppValue($appName, $key)
			{
			}

			public function deleteAppValues($appName)
			{
			}

			public function setUserValue($userId, $appName, $key, $value, $preCondition = null)
			{
			}

			public function getUserValue($userId, $appName, $key, $default = '')
			{
				return $default;
			}

			public function getUserValueForUsers($appName, $key, $userIds)
			{
				return [];
			}

			public function getUserKeys($userId, $appName)
			{
				return [];
			}

			public function getAllUserValues(string $userId): array
			{
				return [];
			}

			public function deleteUserValue($userId, $appName, $key)
			{
			}

			public function deleteAllUserValues($userId)
			{
			}

			public function deleteAppFromAllUsers($appName)
			{
			}

			public function getUsersForUserValue($appName, $key, $value)
			{
				return [];
			}
		};
		return $config;
	}

	/**
	 * The rows matching the spelling set and the path scope, newest first,
	 * then paged.
	 *
	 * @param list<string> $spellings
	 * @return list<array<string, mixed>>
	 */
	private function matching(array $spellings, ?string $pathPrefix = null): array
	{
		$match = static function (array $row) use ($spellings): bool {
			$code = $row['reason_code'] ?? null;
			if (!is_string($code) || $code === '') {
				$code = $row['reason'] ?? null;
			}
			return is_string($code) && in_array($code, $spellings, true);
		};
		$rows = array_values(array_filter($this->rows, $match));
		if ($pathPrefix !== null && $pathPrefix !== '') {
			// The same scope predicate the production query applies.
			$rows = array_values(array_filter(
				$rows,
				static fn (array $row): bool => \OCA\OcrFlow\Service\UserScope::owns((string) ($row['path'] ?? ''), $pathPrefix),
			));
		}
		usort($rows, static function (array $a, array $b): int {
			$fa = (int) ($a['finished_at'] ?? 0);
			$fb = (int) ($b['finished_at'] ?? 0);
			return $fa === $fb ? (int) $b['id'] <=> (int) $a['id'] : $fb <=> $fa;
		});
		return $rows;
	}

	protected function fetchRows(int $limit, int $offset, array $spellings, ?string $pathPrefix = null): array
	{
		$this->rowCalls[] = ['limit' => $limit, 'offset' => $offset, 'spellings' => $spellings, 'prefix' => $pathPrefix];
		return array_slice($this->matching($spellings, $pathPrefix), $offset, $limit);
	}

	protected function fetchTotal(array $spellings, ?string $pathPrefix = null): int
	{
		$this->totalCalls[] = $pathPrefix;
		return count($this->matching($spellings, $pathPrefix));
	}

	protected function fetchIds(int $limit, array $spellings): array
	{
		$this->idCalls[] = ['limit' => $limit, 'spellings' => $spellings];
		return array_map(static fn (array $row): int => (int) $row['id'], array_slice($this->matching($spellings), 0, $limit));
	}
}
