<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Attention;
use OCA\OcrFlow\Service\Outcomes;
use OCA\OcrFlow\Service\PendingResults;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Files\AppData\IAppDataFactory;
use Psr\Log\LoggerInterface;

/**
 * Outcomes with the two ledger/stash SQL seams replaced by in-memory stores.
 *
 * The jobs origin is faked through StoreAttention (Attention::list is
 * generalised by verdict, so the jobs tabs ride the SAME production code),
 * and the ledger + pending_results origins are faked here. The tab mapping,
 * the bar comparison, the dedup and the estimate rules stay production code.
 */
class StoreOutcomes extends Outcomes
{
	/** @var list<array<string, mixed>> raw ledger rows, any order */
	public array $ledger = [];

	/** @var list<array<string, mixed>> raw pending_results rows, any order */
	public array $pending = [];

	/** @var list<array<string, mixed>> */
	public array $ledgerCalls = [];

	/** @var list<array<string, mixed>> */
	public array $pendingCalls = [];

	/** @var Attention the jobs-origin reader, generalised by verdict */
	public Attention $storeAttention;

	/**
	 * @param array<string, string> $values app config values (engine bar, price, cap)
	 * @param list<array<string, mixed>> $jobRows
	 */
	public function __construct(
		array $values = [],
		array $jobRows = [],
		?Attention $attention = null,
	) {
		$this->storeAttention = $attention ?? new StoreAttention(
			new class () extends Queue {
				/** no database: the seams below never reach it */
				public function __construct()
				{
				}
			},
			null,
			self::configWith($values),
			null,
		);
		if ($attention === null) {
			$this->storeAttention->rows = $jobRows;
		}
		parent::__construct(
			$this->storeAttention,
			new RetryPolicy(),
			new \OCA\OcrFlow\Service\EnginePolicy(self::configWith($values)),
			self::pendingResults(),
			self::configWith($values),
			null,
		);
	}

	/** @param array<string, string> $values */
	public static function configWith(array $values): IConfig
	{
		return new class ($values) implements IConfig {
			public function __construct(private array $values)
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
				return $this->values[$key] ?? $default;
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
	}

	private static function pendingResults(): FakePendingResults
	{
		return new FakePendingResults(
			null,
			new class () implements IAppDataFactory {
				public function get(string $appId): \OCP\Files\IAppData
				{
					throw new \RuntimeException('no appdata in the unit store');
				}
			},
			self::configWith([]),
			new class () implements LoggerInterface {
				public function emergency($message, array $context = []): void
				{
				}

				public function alert($message, array $context = []): void
				{
				}

				public function critical($message, array $context = []): void
				{
				}

				public function error($message, array $context = []): void
				{
				}

				public function warning($message, array $context = []): void
				{
				}

				public function notice($message, array $context = []): void
				{
				}

				public function info($message, array $context = []): void
				{
				}

				public function debug($message, array $context = []): void
				{
				}

				public function log($level, $message, array $context = []): void
				{
				}
			},
		);
	}

	/** @return list<array<string, mixed>> */
	protected function fetchLedgerRows(): array
	{
		$this->ledgerCalls[] = ['rows' => count($this->ledger)];
		return $this->ledger;
	}

	/** @return list<array<string, mixed>> */
	protected function fetchPendingRows(): array
	{
		$this->pendingCalls[] = ['rows' => count($this->pending)];
		return $this->pending;
	}
}

/**
 * The stash service for the outcomes store: Apply and Discard behaviour is
 * pinned by PendingResultsApplyTest; here they only answer, so the
 * controller's delegation and response mapping can be tested without
 * appdata or a user tree.
 */
class FakePendingResults extends PendingResults
{
	public function apply(int $id): array
	{
		return ['status' => 'refused', 'warning' => 'versioning is unavailable: applying would overwrite the file with no way back to its previous content'];
	}

	public function discard(int $id): array
	{
		return ['status' => 'discarded', 'warning' => null];
	}
}
