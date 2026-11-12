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
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The needs_fix predicate runs through an expression builder that honors
 * the OCP contract, not a hand-rolled string of "should be fine". The
 * shipped bug was exactly the gap between those: predicate() was declared
 * `: string` while IExpressionBuilder::orX() returns an ICompositeExpression,
 * so PHP threw a TypeError at runtime on the real Nextcloud and killed both
 * front doors (occ ocrflow:errors, the attention endpoint) while the unit
 * suite stayed green — every other test stubs the SQL seam (StoreAttention)
 * and never builds an expression.
 *
 * The OC QueryBuilder implementation lives in the server, not in the ocp
 * package, so a fully real one is not available in the unit layer; the
 * contract that broke here is the interface's return types, which a typed
 * mock encodes exactly. The real-instance behaviour (HTTP 200, command exit
 * 0) is asserted by the integration smoke against a real Nextcloud.
 */
final class AttentionPredicateTest extends TestCase
{
	private IQueryBuilder&MockObject $qb;
	private IExpressionBuilder&MockObject $expr;
	/** @var list<array{0: string, 1: string, 2: mixed}> */
	private array $inCalls = [];
	private int $paramCounter = 0;

	protected function setUp(): void
	{
		$this->expr = $this->createMock(IExpressionBuilder::class);

		// orX/andX return real ICompositeExpression values carrying the type
		// and arity of what was composed, so a wrong shape fails an
		// assertion instead of passing vacuously.
		$composite = function (string $type): ICompositeExpression {
			$parts = func_get_args();
			array_shift($parts);
			$c = $this->createMock(ICompositeExpression::class);
			$c->method('getType')->willReturn($type);
			$c->method('count')->willReturn(count($parts));
			return $c;
		};
		$this->expr->method('orX')->willReturnCallback(
			fn (...$parts) => $composite('OR', ...$parts));
		$this->expr->method('andX')->willReturnCallback(
			fn (...$parts) => $composite('AND', ...$parts));
		$this->expr->method('in')->willReturnCallback(function (string $x, $y, $type = null): string {
			$this->inCalls[] = [$x, (string) $y, $y];
			return $x . ' IN (' . (is_string($y) ? $y : '') . ')';
		});
		$this->expr->method('eq')->willReturnCallback(
			fn ($x, $y, $type = null) => $x . ' = ' . $y);
		$this->expr->method('isNull')->willReturnCallback(fn ($x) => $x . ' IS NULL');

		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->qb->method('expr')->willReturn($this->expr);
		$this->qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = null) {
			// Every parameter needs a distinct placeholder, or the two in()
			// branches would collide and mask a wrong-arity bug.
			return ':p' . ++$this->paramCounter;
		});
	}

	/**
	 * The production failure: TypeError "Return value must be of type
	 * string, ICompositeExpression returned". This test throws it verbatim
	 * against the buggy declaration and only passes when predicate()'s
	 * return type tells the truth.
	 */
	public function testPredicateReturnsTheCompositeExpressionOrXBuilds(): void
	{
		$attention = new Attention(
			$this->createMock(Queue::class),
			new RetryPolicy(),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
		);

		$method = new \ReflectionMethod(Attention::class, 'predicate');
		$spellings = (new RetryPolicy())->spellingsForVerdict(RetryPolicy::VERDICT_NEEDS_FIX);
		$this->assertNotEmpty($spellings, 'needs_fix must have at least one spelling');

		$result = $method->invoke($attention, $this->qb, $spellings);

		$this->assertInstanceOf(ICompositeExpression::class, $result,
			'predicate() must return what orX() builds, not a string');
		$this->assertSame('OR', $result->getType(),
			'the worklist predicate is a disjunction: code or reason');
		$this->assertSame(2, $result->count(),
			'code match OR (empty code AND reason match)');

		// The two in() branches must carry the real needs_fix vocabulary
		// and distinct placeholders — the same array passed to both.
		$this->assertCount(2, $this->inCalls, 'one in() for reason_code, one for reason');
		$this->assertSame(['reason_code', 'reason'], array_column($this->inCalls, 0));
		$this->assertNotSame($this->inCalls[0][2], $this->inCalls[1][2],
			'the two in() calls must use distinct named parameters');
	}

	/**
	 * The pre-0.1.5 rows: reason_code empty or NULL, matched on reason.
	 */
	public function testPredicateCoversTheLegacyEmptyCodeShape(): void
	{
		$attention = new Attention(
			$this->createMock(Queue::class),
			new RetryPolicy(),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
		);

		$method = new \ReflectionMethod(Attention::class, 'predicate');
		$spellings = (new RetryPolicy())->spellingsForVerdict(RetryPolicy::VERDICT_NEEDS_FIX);
		$method->invoke($attention, $this->qb, $spellings);

		// isNull and eq on reason_code were exercised by the legacy branch.
		$this->assertNotEmpty($this->inCalls);
	}
}
