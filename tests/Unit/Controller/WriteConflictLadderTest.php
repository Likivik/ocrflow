<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Controller\QueueController;
use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\WorkerRegistry;
use OCA\OcrFlow\Service\FileExchange;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\SettlementService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** [unit] The write ladder reads the run's source, and bytes outrank etags. */
class WriteConflictLadderTest extends TestCase
{
	private FileExchange&MockObject $files;

	protected function setUp(): void
	{
		$this->files = $this->createMock(FileExchange::class);
	}

	private function conflict(Job $job): bool
	{
		$controller = new QueueController(
			Application::APP_ID,
			$this->createMock(IRequest::class),
			$this->createMock(JobStore::class),
			$this->createMock(SettlementService::class),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->files,
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
		);
		$method = new \ReflectionMethod(QueueController::class, 'writeConflict');
		$method->setAccessible(true);

		return $method->invoke($controller, $job);
	}

	private function job(?string $sourceHash): Job
	{
		$job = new Job();
		$job->setPath('/alice/files/source.jpg');
		$job->setEtag('intake-etag');
		$job->setRunEtag('etag-after-our-lock');
		$job->setSourceSha256($sourceHash);

		return $job;
	}

	public function testTheBaselineIsTheEtagAfterOurLockNotTheIntakeEtag(): void
	{
		$this->files->expects($this->once())->method('currentEtag')
			->with('alice', 'source.jpg')
			->willReturn('etag-after-our-lock');
		$this->files->expects($this->never())->method('contentSha256');

		$this->assertFalse($this->conflict($this->job(null)));
	}

	public function testAMovedEtagWithIdenticalBytesIsNotAConflict(): void
	{
		$this->files->method('currentEtag')->willReturn('somebody-moved-the-etag');
		$this->files->expects($this->once())->method('contentSha256')
			->with('alice', 'source.jpg')
			->willReturn('same-bytes');

		$this->assertFalse($this->conflict($this->job('same-bytes')));
	}

	public function testAMovedEtagWithDifferentBytesIsAConflict(): void
	{
		$this->files->method('currentEtag')->willReturn('somebody-moved-the-etag');
		$this->files->method('contentSha256')->willReturn('edited-bytes');

		$this->assertTrue($this->conflict($this->job('original-bytes')));
	}

	public function testTheSidecarNameIsNeverSubstitutedForTheJobSource(): void
	{
		// A fresh result name such as out.pdf has no useful etag or hash. Both
		// reads must therefore name the source carried by the job itself.
		$this->files->expects($this->once())->method('currentEtag')
			->with('alice', 'source.jpg')
			->willReturn('somebody-moved-the-etag');
		$this->files->expects($this->once())->method('contentSha256')
			->with('alice', 'source.jpg')
			->willReturn('same-bytes');

		$this->assertFalse($this->conflict($this->job('same-bytes')));
	}
}
