<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\PendingResults;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Decide tab's Apply and Discard. All tests here are [unit]: the DB and
 * appdata seams are subclassed away, the user's tree is a mock.
 *
 * The rules, and the defect each catches:
 *
 *  - Apply refuses with a warning when versioning is unavailable — the
 *    previous content of the file would be UNRECOVERABLE, so overwriting
 *    it anyway is data loss dressed up as a convenience (the plan's hard
 *    rule; nothing here may fabricate a version that does not exist).
 *  - Apply writes the artifact over the file and closes the row as
 *    applied.
 *  - Discard deletes the artifact and closes the row as discarded.
 *  - Neither acts on a row that is not pending.
 */
class PendingResultsApplyTest extends TestCase
{
	private const ROW_ID = 7;
	private const FILE_ID = 42;

	private TestPendingResults $pending;
	private string $files = '';
	private ISimpleFolder $appFolder;
	private bool $versioningEnabled = false;
	private bool $artifactDeleted = false;

	protected function setUp(): void
	{
		$this->pending = new TestPendingResults(
			self::ROW_ID,
			self::FILE_ID,
			$this->appDataFactory(),
			$this->config(),
			$this->logger(),
			$this->rootFolder(),
			$this->appManager(),
		);
	}

	private function config(): IConfig
	{
		return new class () implements IConfig {
			public function setSystemValues(array $configs) {}
			public function setSystemValue($key, $value) {}
			public function getSystemValue($key, $default = '') { return $default; }
			public function getSystemValueBool(string $key, bool $default = false): bool { return $default; }
			public function getSystemValueInt(string $key, int $default = 0): int { return $default; }
			public function getSystemValueString(string $key, string $default = ''): string { return $default; }
			public function getFilteredSystemValue($key, $default = '') { return $default; }
			public function deleteSystemValue($key) {}
			public function getAppKeys($appName) { return []; }
			public function setAppValue($appName, $key, $value) {}
			public function getAppValue($appName, $key, $default = '') { return $default; }
			public function deleteAppValue($appName, $key) {}
			public function deleteAppValues($appName) {}
			public function setUserValue($userId, $appName, $key, $value, $preCondition = null) {}
			public function getUserValue($userId, $appName, $key, $default = '') { return $default; }
			public function getUserValueForUsers($appName, $key, $userIds) { return []; }
			public function getUserKeys($userId, $appName) { return []; }
			public function getAllUserValues(string $userId): array { return []; }
			public function deleteUserValue($userId, $appName, $key) {}
			public function deleteAllUserValues($userId) {}
			public function deleteAppFromAllUsers($appName) {}
			public function getUsersForUserValue($appName, $key, $value) { return []; }
		};
	}

	private function logger(): LoggerInterface
	{
		return new class () implements LoggerInterface {
			public array $lines = [];
			public function emergency($message, array $context = []): void { $this->lines[] = $message; }
			public function alert($message, array $context = []): void { $this->lines[] = $message; }
			public function critical($message, array $context = []): void { $this->lines[] = $message; }
			public function error($message, array $context = []): void { $this->lines[] = $message; }
			public function warning($message, array $context = []): void { $this->lines[] = $message; }
			public function notice($message, array $context = []): void { $this->lines[] = $message; }
			public function info($message, array $context = []): void { $this->lines[] = $message; }
			public function debug($message, array $context = []): void {}
			public function log($level, $message, array $context = []): void { $this->lines[] = $message; }
		};
	}

	private function appDataFactory(): IAppDataFactory
	{
		$artifact = $this->createMock(ISimpleFile::class);
		$artifact->method('read')->willReturn(fopen('data://text/plain,produced-pdf-bytes', 'rb'));
		$artifact->method('delete')->willReturnCallback(function (): void {
			$this->artifactDeleted = true;
		});
		$this->appFolder = $this->createMock(ISimpleFolder::class);
		$this->appFolder->method('fileExists')->willReturn(true);
		$this->appFolder->method('getFile')->willReturn($artifact);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->appFolder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);
		return $factory;
	}

	private function rootFolder(): IRootFolder
	{
		$node = $this->createMock(\OCP\Files\File::class);
		$node->method('putContent')->willReturnCallback(function ($content): void {
			$this->files = is_resource($content) ? (string) stream_get_contents($content) : (string) $content;
		});
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnCallback(
			fn (int $id): array => $id === self::FILE_ID ? [$node] : [],
		);
		return $root;
	}

	private function appManager(): IAppManager
	{
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isEnabledForUser')->willReturnCallback(
			fn (string $appId): bool => $appId === 'files_versions' && $this->versioningEnabled,
		);
		return $manager;
	}

	/**
	 * [unit] Apply refuses with a warning when versioning is unavailable:
	 * overwriting without a recoverable previous version is data loss.
	 */
	public function testApplyRefusesWhenVersioningIsUnavailable(): void
	{
		$this->versioningEnabled = false;

		$result = $this->pending->apply(self::ROW_ID);

		$this->assertSame('refused', $result['status']);
		$this->assertNotSame('', $result['warning']);
		$this->assertSame('pending', $this->pending->rowStatus, 'the row is untouched by a refusal');
	}

	/**
	 * [unit] With versioning available, Apply writes the artifact over the
	 * file and closes the row as applied.
	 */
	public function testApplyWritesTheArtifactAndClosesTheRow(): void
	{
		$this->versioningEnabled = true;

		$result = $this->pending->apply(self::ROW_ID);

		$this->assertSame('applied', $result['status']);
		$this->assertSame('produced-pdf-bytes', $this->files, 'the artifact bytes are what landed in the file');
		$this->assertSame('applied', $this->pending->rowStatus);
	}

	/**
	 * [unit] Apply refuses when the file itself is gone — the artifact has
	 * nowhere to go and must not be deleted to hide that.
	 */
	public function testApplyRefusesWhenTheFileIsGone(): void
	{
		$this->versioningEnabled = true;
		$this->pending->fileMissing = true;

		$result = $this->pending->apply(self::ROW_ID);

		$this->assertSame('refused', $result['status']);
		$this->assertSame('pending', $this->pending->rowStatus);
	}

	/**
	 * [unit] Discard deletes the artifact and leaves the row as the audit
	 * trail, the same end state retention's sweep produces.
	 */
	public function testDiscardDeletesTheArtifactAndKeepsTheRow(): void
	{
		$result = $this->pending->discard(self::ROW_ID);

		$this->assertSame('discarded', $result['status']);
		$this->assertTrue($this->artifactDeleted, 'the artifact bytes were deleted');
		$this->assertSame('discarded', $this->pending->rowStatus);
	}

	/**
	 * [unit] Neither door acts on a row that is not pending: a double
	 * click, or a stale page's button, must not re-decide a decided row.
	 */
	public function testADecidedRowIsLeftAlone(): void
	{
		$this->versioningEnabled = true;
		$this->pending->rowStatus = 'applied';

		$this->assertSame('refused', $this->pending->apply(self::ROW_ID)['status']);
		$this->assertSame('refused', $this->pending->discard(self::ROW_ID)['status']);
		$this->assertSame('applied', $this->pending->rowStatus);
	}
}

/**
 * The seams replaced: row load/update, and the artifact read/delete ride
 * the mocked appdata through the production folder() path.
 */
class TestPendingResults extends PendingResults
{
	public string $rowStatus = 'pending';
	public bool $fileMissing = false;

	public function __construct(
		private int $rowId,
		private int $fileId,
		IAppDataFactory $appDataFactory,
		IConfig $config,
		LoggerInterface $logger,
		private \OCP\Files\IRootFolder $rootFolder,
		private IAppManager $appManager,
	) {
		parent::__construct(
			null,
			$appDataFactory,
			$config,
			$logger,
			null,
			null,
		);
	}

	protected function fetchRow(int $id): ?array
	{
		if ($id !== $this->rowId) {
			return null;
		}
		return [
			'id' => $id,
			'file_id' => $this->fileId,
			'etag' => 'e1',
			'artifact_path' => 'pending-42-e1.pdf',
			'engine' => 'google',
			'engine_version' => '3.15.0',
			'pages' => 2,
			'created_at' => 1,
			'status' => $this->rowStatus,
		];
	}

	protected function markRow(int $id, string $status): void
	{
		$this->rowStatus = $status;
	}

	protected function discardRow(int $id): void
	{
		$this->rowStatus = 'discarded';
	}

	protected function nodeForFile(int $fileId): ?\OCP\Files\File
	{
		if ($this->fileMissing || $fileId !== $this->fileId) {
			return null;
		}
		$nodes = $this->rootFolder->getById($fileId);
		return $nodes[0] ?? null;
	}

	protected function versioningAvailable(): bool
	{
		return $this->appManager->isEnabledForUser('files_versions');
	}
}
