<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Service\PendingResults;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * [unit] The stash's artifact half: bytes land in appdata, never in the
 * user's files, and a repeat for the same (file_id, etag) supersedes the
 * old artifact instead of accumulating.
 *
 * The row half (upsert, unique violation, expiry sweep against a real
 * table) needs a real database; those are the [real-db] assertions in the
 * integration smoke.
 *
 * Fails on unmodified main: the class does not exist there.
 */
class PendingResultsTest extends TestCase
{
	private const BODY = '%PDF-1.7 the OCR result that could not be written';

	/** @var array<string, string> artifact name => bytes on "disk" */
	private array $artifacts = [];

	private RecordingPendingResults $service;

	protected function setUp(): void
	{
		$folder = new FakeSimpleFolder($this->artifacts);
		$appData = $this->createMock(\OCP\Files\IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$appData->method('newFolder')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with(Application::APP_ID)->willReturn($appData);

		$this->service = new RecordingPendingResults(
			$this->createMock(IDBConnection::class),
			$factory,
			$this->config('30'),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function config(string $retentionDays): IConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $appId, string $key, string $default = ''): string
				=> $key === PendingResults::CONFIG_KEY ? $retentionDays : $default
		);
		return $config;
	}

	private function serviceWith(string $retentionDays): RecordingPendingResults
	{
		$folder = new FakeSimpleFolder($this->artifacts);
		$appData = $this->createMock(\OCP\Files\IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$appData->method('newFolder')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with(Application::APP_ID)->willReturn($appData);

		return new RecordingPendingResults(
			$this->createMock(IDBConnection::class),
			$factory,
			$this->config($retentionDays),
			$this->createMock(LoggerInterface::class),
		);
	}

	private static function stream(string $bytes): mixed
	{
		$h = fopen('php://memory', 'rb+');
		fwrite($h, $bytes);
		rewind($h);
		return $h;
	}

	public function testStashWritesTheBytesIntoAppdata(): void
	{
		$this->service->stash(42, 'old-etag', self::stream(self::BODY), 'tesseract', '5.3.0', 3);

		$artifact = PendingResults::artifactName(42, 'old-etag');
		$this->assertSame([$artifact], array_keys($this->artifacts), 'exactly one artifact');
		$this->assertSame(self::BODY, $this->artifacts[$artifact], 'the exact bytes the worker sent');

		$this->assertCount(1, $this->service->rows);
		$this->assertSame(42, $this->service->rows[0]['file_id']);
		$this->assertSame('old-etag', $this->service->rows[0]['etag']);
		$this->assertSame($artifact, $this->service->rows[0]['artifact_path']);
		$this->assertSame('tesseract', $this->service->rows[0]['engine']);
		$this->assertSame(3, $this->service->rows[0]['pages']);
	}

	public function testARepeatStashForTheSameVersionSupersedes(): void
	{
		$this->service->stash(42, 'old-etag', self::stream(self::BODY), 'tesseract', '5.3.0', 3);
		$this->service->stash(42, 'old-etag', self::stream('a newer result for the same version'), 'tesseract', '5.3.0', 3);

		$this->assertCount(1, $this->artifacts, 'one artifact per (file_id, etag), superseded in place');
		$this->assertSame('a newer result for the same version', reset($this->artifacts));
	}

	public function testDifferentVersionsKeepDifferentArtifacts(): void
	{
		$this->service->stash(42, 'etag-a', self::stream('result A'), null, null, null);
		$this->service->stash(42, 'etag-b', self::stream('result B'), null, null, null);
		$this->assertCount(2, $this->artifacts);
	}

	public function testTheArtifactNameIsDeterministicPerFileVersion(): void
	{
		$this->assertSame(
			PendingResults::artifactName(42, 'a'),
			PendingResults::artifactName(42, 'a'),
		);
		$this->assertNotSame(
			PendingResults::artifactName(42, 'a'),
			PendingResults::artifactName(42, 'b'),
		);
		$this->assertNotSame(
			PendingResults::artifactName(42, 'a'),
			PendingResults::artifactName(43, 'a'),
		);
	}

	public function testTotalArtifactBytesCountsThePendingArtifacts(): void
	{
		$this->service->stash(42, 'a', self::stream(self::BODY), null, null, null);
		$this->service->paths = array_column($this->service->rows, 'artifact_path');
		$this->assertSame(strlen(self::BODY), $this->service->totalArtifactBytes());
	}

	public function testTheDefaultRetentionIsThirtyDays(): void
	{
		$this->assertSame(30, $this->service->retentionDays());
	}

	public function testZeroDisablesArtifactRetention(): void
	{
		$this->assertNull($this->serviceWith('0')->retentionDays());
	}

	public function testGarbageFallsBackToTheDefault(): void
	{
		foreach (['soon', '-7', '2.5', '', '  '] as $garbage) {
			$this->assertSame(30, $this->serviceWith($garbage)->retentionDays(), $garbage);
		}
	}

	public function testSweepExpiredDeletesTheArtifactBytes(): void
	{
		$this->service->stash(42, 'old-etag', self::stream(self::BODY), null, null, null);
		$this->assertNotEmpty($this->artifacts);

		// The row the (real-db) sweep would have selected as expired.
		$this->service->expired = $this->service->rows;

		$discarded = $this->service->sweepExpired();
		$this->assertSame(1, $discarded);
		$this->assertSame([1], $this->service->discarded, 'the row is left behind as discarded');
		$this->assertSame([], $this->artifacts, 'expiry deletes the bytes');
	}

	public function testSweepIsANoOpWhenRetentionIsDisabled(): void
	{
		$this->service->stash(42, 'old-etag', self::stream(self::BODY), null, null, null);
		$disabled = $this->serviceWith('0');
		$disabled->expired = $disabled->rows;

		$this->assertSame(0, $disabled->sweepExpired());
		$this->assertNotEmpty($this->artifacts, '`0` keeps every artifact');
	}
}

/**
 * The DB seams, recorded instead of executed: the row behaviour against a
 * real table is the integration smoke's job.
 */
class RecordingPendingResults extends PendingResults
{
	/** @var list<array<string, mixed>> */
	public array $rows = [];

	/** @var list<int> */
	public array $discarded = [];

	/** @var list<array<string, mixed>> */
	public array $expired = [];

	/** @var list<string> */
	public array $paths = [];

	protected function recordRow(
		int $fileId,
		?string $etag,
		string $artifact,
		?string $engine,
		?string $engineVersion,
		?int $pages,
		int $createdAt,
	): void {
		$this->rows[] = [
			'id' => count($this->rows) + 1,
			'file_id' => $fileId,
			'etag' => $etag,
			'artifact_path' => $artifact,
			'engine' => $engine,
			'engine_version' => $engineVersion,
			'pages' => $pages,
			'created_at' => $createdAt,
		];
	}

	protected function expiredRows(int $cutoff): array
	{
		return $this->expired;
	}

	protected function discardRow(int $id): void
	{
		$this->discarded[] = $id;
	}

	protected function artifactPaths(): array
	{
		return $this->paths;
	}
}

/**
 * An appdata folder backed by an array: newFile/putContent accept a
 * resource exactly like the real one, so the buffering path runs.
 */
class FakeSimpleFolder implements ISimpleFolder
{
	/** @param array<string, string> $artifacts */
	public function __construct(private array &$artifacts) {}

	public function getDirectoryListing(): array
	{
		return [];
	}

	public function fileExists(string $name): bool
	{
		return isset($this->artifacts[$name]);
	}

	public function getFile(string $name): ISimpleFile
	{
		if (!isset($this->artifacts[$name])) {
			throw new NotFoundException($name);
		}
		$name_ = $name;
		$bytes = $this->artifacts[$name];
		$artifacts = &$this->artifacts;
		return new class($name_, $bytes, $artifacts) implements ISimpleFile {
			public function __construct(
				private string $name,
				private string $bytes,
				private array &$artifacts,
			) {}

			public function getName(): string
			{
				return $this->name;
			}

			public function getSize(): int|float
			{
				return strlen($this->bytes);
			}

			public function getETag(): string
			{
				return 'etag';
			}

			public function getMTime(): int
			{
				return 0;
			}

			public function getContent(): string
			{
				return $this->bytes;
			}

			public function putContent($data): void
			{
				$this->artifacts[$this->name] = is_resource($data)
					? (string) stream_get_contents($data)
					: (string) $data;
			}

			public function delete(): void
			{
				unset($this->artifacts[$this->name]);
			}

			public function getMimeType(): string
			{
				return 'application/pdf';
			}

			public function getExtension(): string
			{
				return 'pdf';
			}

			public function read()
			{
				$h = fopen('php://memory', 'rb+');
				fwrite($h, $this->bytes);
				rewind($h);
				return $h;
			}

			public function write()
			{
				$h = fopen('php://memory', 'rb+');
				$this->artifacts[$this->name] = '';
				return $h;
			}
		};
	}

	public function newFile(string $name, $content = null): ISimpleFile
	{
		$this->artifacts[$name] = is_resource($content)
			? (string) stream_get_contents($content)
			: (string) ($content ?? '');
		return $this->getFile($name);
	}

	public function delete(): void
	{
	}

	public function getName(): string
	{
		return 'pending-results';
	}

	public function getFolder(string $name): ISimpleFolder
	{
		return $this;
	}

	public function newFolder(string $name): ISimpleFolder
	{
		return $this;
	}
}
