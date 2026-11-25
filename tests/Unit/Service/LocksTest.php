<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\Locks;
use OCP\Files\Lock\ILockManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The lock lookup's honest answers: no provider, provider present, lookup
 * broken — and the rules that bind them. **Never throw, never stop the queue**:
 * a lookup that fails answers "not locked", which is the pre-lock behaviour,
 * and the write ladder still protects the file.
 *
 * The lookup is deliberately read-only. Taking a lock rewrites the file's etag
 * (`files_lock`'s `propagateEtag()`), and the write ladder depends on that etag
 * holding still — a held lock turns every result into a stash. The
 * `never()->method('lock')` assertions are this decision, written down.
 */
class LocksTest extends TestCase
{
	private function manager(bool $available): ILockManager
	{
		$manager = $this->createMock(ILockManager::class);
		$manager->method('isLockProviderAvailable')->willReturn($available);

		return $manager;
	}

	public function testNoProviderMeansNeverLocked(): void
	{
		$manager = $this->manager(false);
		$manager->expects($this->never())->method('lock');
		$manager->expects($this->never())->method('unlock');
		$locks = new Locks($manager, $this->createMock(LoggerInterface::class));

		$this->assertFalse($locks->available());
		$this->assertFalse($locks->isLocked(42));
		$this->assertFalse($locks->isLocked(null));
	}

	public function testALookupGlitchReadsAsUnlocked(): void
	{
		// files_lock is absent in the unit context: the read must fail quietly
		// rather than take the claim down with it.
		$locks = new Locks($this->manager(true), $this->createMock(LoggerInterface::class));

		$this->assertFalse($locks->isLocked(42));
	}

	public function testTheLookupNeverTakesOrReleasesALock(): void
	{
		$manager = $this->manager(true);
		$manager->expects($this->never())->method('lock');
		$manager->expects($this->never())->method('unlock');
		$locks = new Locks($manager, $this->createMock(LoggerInterface::class));

		$locks->isLocked(7);
		$this->addToAssertionCount(1);
	}
}
