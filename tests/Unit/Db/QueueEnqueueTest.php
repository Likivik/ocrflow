<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Db;

use OCA\OcrFlow\Db\Queue;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * `enqueue()` must return the id of the row it just wrote.
 *
 * It used to ask the CONNECTION for it — `$this->db->getLastInsertId()` — a
 * method Nextcloud 34 removed from `IDBConnection` while keeping it on the query
 * builder. On the live host that raised
 * "Call to undefined method OC\DB\ConnectionAdapter::getLastInsertId()" on every
 * single upload: the row landed anyway (the INSERT had already executed), the
 * listener logged "could not queue", and it returned nothing, so no caller could
 * tell a job had been created.
 *
 * Nothing failed loudly: the file still got processed, so the only trace was a
 * warning in nextcloud.log. That is why the assertion pins the id, not "a row
 * exists".
 */
class QueueEnqueueTest extends TestCase
{
    private function builder(int $insertId): IQueryBuilder
    {
        // enqueue() first asks whether this content version is already queued or
        // done, so the builder must also answer the SELECT half — empty result,
        // nothing in flight.
        $empty = $this->createMock(IResult::class);
        $empty->method('fetchAll')->willReturn([]);
        // isDoneForVersion() decides with `fetch() !== false`, so a bare mock
        // returning null reads as "already done" and enqueue bails out.
        $empty->method('fetch')->willReturn(false);

        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($empty);
        $qb->method('insert')->willReturnSelf();
        $qb->method('values')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeStatement')->willReturn(1);
        $qb->method('getLastInsertId')->willReturn($insertId);

        return $qb;
    }

    public function testEnqueueReturnsTheNewJobId(): void
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($this->builder(42));
        // IDBConnection does not declare getLastInsertId() at all in the
        // Nextcloud 34 stubs — which is why the live host raised "undefined
        // method" while PHPStan saw nothing.

        $queue = new Queue($db);

        $this->assertSame(42, $queue->enqueue('alice/files/a.pdf', 7, 1234, 'etag', 'tesseract'));
    }
}
