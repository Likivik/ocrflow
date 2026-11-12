<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Command;

use OCA\OcrFlow\Command\Errors;
use OCA\OcrFlow\Controller\AttentionController;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Tests\Unit\Service\StoreAttention;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ ocrflow:errors` is the terminal view of the same worklist the
 * endpoint serves — one data source, so the two can never disagree about
 * whose work it is. The fixture test pins exactly that.
 */
class ErrorsCommandTest extends TestCase
{
	private function queueMock(): Queue
	{
		return $this->createMock(Queue::class);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fixture(): array
	{
		return [
			['id' => 11, 'path' => 'alice/files/a.pdf', 'reason_code' => 'error_file_broken', 'reason' => 'error_file_broken', 'error' => 'x', 'attempts' => 3, 'finished_at' => 400],
			['id' => 12, 'path' => 'bob/files/b.pdf', 'reason_code' => null, 'reason' => 'credentials_missing', 'error' => 'y', 'attempts' => 1, 'finished_at' => 900],
			['id' => 13, 'path' => 'carol/files/c.pdf', 'reason_code' => 'error_worker_secret_rejected', 'reason' => 'error_worker_secret_rejected', 'error' => 'z', 'attempts' => 2, 'finished_at' => 100],
		];
	}

	public function testTheCommandAndTheEndpointReturnTheSameSet(): void
	{
		$queue = $this->queueMock();
		$attention = new StoreAttention($queue);
		$attention->rows = $this->fixture();

		// The endpoint door.
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'limit' ? 200 : $default,
		);
		$controller = new AttentionController('ocrflow', $request, $attention, $session, $groups, new RetryPolicy());
		$endpointIds = array_column($controller->index()->getData()['rows'], 'id');

		// The terminal door, over the SAME service instance.
		$tester = new CommandTester(new Errors($attention));
		$tester->execute([]);
		$display = $tester->getDisplay();

		preg_match_all('/^\s*(\d+)\s+\S*\//m', $display, $matches);
		$commandIds = array_map(intval(...), $matches[1] ?? []);

		$this->assertSame($endpointIds, $commandIds, 'same fixture, same set: the two doors cannot disagree');
		$this->assertStringContainsString('3 needing attention', $display);
	}

	public function testTheEmptyWorklistSaysSo(): void
	{
		$attention = new StoreAttention($this->queueMock());

		$tester = new CommandTester(new Errors($attention));
		$tester->execute([]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('0 needing attention', $tester->getDisplay());
	}

	public function testTheFilterShowsOnlyOneCode(): void
	{
		$attention = new StoreAttention($this->queueMock());
		$attention->rows = $this->fixture();

		$tester = new CommandTester(new Errors($attention));
		$tester->execute(['--code' => 'error_provider_credentials_missing']);

		$display = $tester->getDisplay();
		$this->assertStringContainsString('bob/files/b.pdf', $display);
		$this->assertStringNotContainsString('alice/files/a.pdf', $display);
		$this->assertStringContainsString('1 needing attention', $display);
	}
}
