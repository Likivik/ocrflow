<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

require_once __DIR__ . '/../BackgroundJob/oc_hooks_emitter.php';

use OCA\OcrFlow\Service\Locks;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\Node;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The run lock's contract, in one place:
 *  - the flag can switch the whole feature off (0.1.65 behaviour);
 *  - a foreign lock means "skip", never "run anyway";
 *  - a broken lock call means "run anyway", never "stop the queue";
 *  - our own write passes inside the scope and is refused outside it;
 *  - release is idempotent and silent.
 */
class LocksTest extends TestCase
{
	private function locks(
		bool $flagged = true,
		bool $available = true,
		?ILockManager $manager = null,
	): Locks {
		$manager ??= $this->manager($available);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn($flagged ? 'yes' : 'no');

		return new Locks($manager, $this->createMock(IRootFolder::class), $config, $this->createMock(LoggerInterface::class));
	}

	private function manager(bool $available): ILockManager
	{
		$manager = $this->createMock(ILockManager::class);
		$manager->method('isLockProviderAvailable')->willReturn($available);

		return $manager;
	}

	private function node(): Node
	{
		return $this->createMock(Node::class);
	}

	public function testTheFlagOffMeansNoLockIsEverTouched(): void
	{
		$manager = $this->manager(true);
		// Taking a lock is what the flag forbids. Releasing is always
		// attempted: a lock taken before the flag was flipped must not leak.
		$manager->expects($this->never())->method('lock');
		$manager->expects($this->never())->method('runInScope');
		$locks = $this->locks(flagged: false, manager: $manager);

		$this->assertTrue($locks->lockForRun($this->node(), 'claim'));
		$locks->releaseForRun($this->node(), 'claim');
		$this->assertSame('ran', $locks->runInScope($this->node(), 'claim', static fn (): string => 'ran'));
	}

	public function testNoProviderMeansProceed(): void
	{
		$locks = $this->locks(available: false);

		$this->assertFalse($locks->available());
		$this->assertTrue($locks->lockForRun($this->node(), 'claim'));
	}

	public function testAForeignLockMeansSkip(): void
	{
		$manager = $this->manager(true);
		$manager->expects($this->once())->method('lock')->willThrowException(
			new OwnerLockedException($this->createMock(ILock::class)),
		);

		$this->assertFalse($this->locks(manager: $manager)->lockForRun($this->node(), 'claim'));
	}

	public function testOurLockIsAppOwned(): void
	{
		$seen = null;
		$manager = $this->manager(true);
		$manager->expects($this->once())->method('lock')->willReturnCallback(
			static function (LockContext $ctx) use (&$seen): void {
				$seen = [$ctx->getType(), $ctx->getOwner()];
			},
		);

		$this->assertTrue($this->locks(manager: $manager)->lockForRun($this->node(), 'claim'));
		$this->assertSame([ILock::TYPE_APP, Locks::OWNER . ':' . hash('sha256', 'claim')], $seen);
	}

	public function testALockGlitchProceedsUnlocked(): void
	{
		$manager = $this->manager(true);
		$manager->method('lock')->willThrowException(new \RuntimeException('database is on fire'));

		$this->assertTrue($this->locks(manager: $manager)->lockForRun($this->node(), 'claim'));
	}

	public function testReleaseSwallowsFailures(): void
	{
		$manager = $this->manager(true);
		$manager->method('unlock')->willThrowException(new \RuntimeException('already gone'));

		$this->locks(manager: $manager)->releaseForRun($this->node(), 'claim');
		$this->addToAssertionCount(1);
	}

	public function testScopeCarriesTheReturnValue(): void
	{
		$inScope = false;
		$manager = $this->manager(true);
		$manager->expects($this->once())->method('runInScope')->willReturnCallback(
			static function (LockContext $ctx, callable $fn) use (&$inScope): void {
				$inScope = $ctx->getOwner() === Locks::OWNER . ':' . hash('sha256', 'claim');
				$fn();
			},
		);

		$this->assertSame('bytes', $this->locks(manager: $manager)->runInScope($this->node(), 'claim', static fn (): string => 'bytes'));
		$this->assertTrue($inScope);
	}
}
