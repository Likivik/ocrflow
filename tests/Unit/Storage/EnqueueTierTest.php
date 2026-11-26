<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Storage;

use OCA\OcrFlow\Service\FileExchange;
use OCA\OcrFlow\Storage\EnqueueService;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Storage\Ledger;
use OCA\OcrFlow\Storage\SettlementService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** [unit] Content identity, not an etag anyone can move, stops duplicate work. */
class EnqueueTierTest extends TestCase
{
	private JobStore&MockObject $jobs;
	private Ledger&MockObject $ledger;
	private FileExchange&MockObject $exchange;
	private IDBConnection&MockObject $db;

	protected function setUp(): void
	{
		$this->jobs = $this->createMock(JobStore::class);
		$this->ledger = $this->createMock(Ledger::class);
		$this->exchange = $this->createMock(FileExchange::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->jobs->method('jobForVersion')->willReturn(null);
		$this->jobs->method('isDoneForVersion')->willReturn(false);
		$this->ledger->method('permanentFailureForVersion')->willReturn(null);
		$this->ledger->method('settledForVersion')->willReturn(null);
	}

	private function service(): EnqueueService
	{
		return new EnqueueService(
			$this->db,
			$this->jobs,
			$this->ledger,
			$this->createMock(SettlementService::class),
			null,
			null,
			$this->createMock(LoggerInterface::class),
			$this->exchange,
		);
	}

	public function testWorkAlreadyInFlightWinsOverTheMovedEtag(): void
	{
		$this->jobs->method('activeForFile')->willReturn(true);
		$this->jobs->expects($this->never())->method('jobForVersion');
		$this->exchange->expects($this->never())->method('contentSha256');
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->assertNull($this->service()->enqueue(
			'/alice/files/report.pdf',
			42,
			100,
			'etag-after-our-lock-moved-it',
		));
	}

	public function testIdenticalBytesUnderANewEtagDoNotEnqueue(): void
	{
		$this->jobs->method('activeForFile')->willReturn(false);
		$this->ledger->method('identityFor')->willReturn([
			'settled_sha256' => 'same-bytes',
			'settled_under_lock' => false,
		]);
		$this->exchange->expects($this->once())->method('contentSha256')
			->with('alice', 'report.pdf')
			->willReturn('same-bytes');
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->assertNull($this->service()->enqueue(
			'/alice/files/report.pdf',
			42,
			100,
			'a-different-etag',
		));
	}

	public function testDifferentBytesEnqueueAndCarryTheirSourceHash(): void
	{
		$this->jobs->method('activeForFile')->willReturn(false);
		$this->ledger->method('identityFor')->willReturn([
			'settled_sha256' => 'old-bytes',
			'settled_under_lock' => false,
		]);
		$this->exchange->method('contentSha256')
			->with('alice', 'report.pdf')
			->willReturn('new-bytes');

		$values = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn (mixed $value): mixed => $value,
		);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnCallback(function (array $rows) use (&$values, $qb): IQueryBuilder {
			$values = $rows;
			return $qb;
		});
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('getLastInsertId')->willReturn(123);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->assertSame(123, $this->service()->enqueue(
			'/alice/files/report.pdf',
			42,
			100,
			'a-different-etag',
		));
		$this->assertSame('new-bytes', $values['source_sha256'] ?? null);
	}
}
