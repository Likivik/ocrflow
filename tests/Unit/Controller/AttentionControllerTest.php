<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\Controller\AttentionController;
use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Tests\Unit\Service\StoreAttention;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The attention endpoints are the admin plane of the worklist: rows whose
 * reason code has the needs_fix verdict, other people's document paths and
 * error prose included. The rule these pin: nothing on this controller is
 * answerable to a non-admin (GET or POST), and the POST that spends engine
 * money is CSRF-checked so a cross-site form cannot queue half the archive.
 */
class AttentionControllerTest extends TestCase
{
	/**
	 * @param array<string, mixed> $params request params
	 */
	private function controller(StoreAttention $attention, array $params = [], bool $csrfOk = true, bool $admin = true): AttentionController
	{
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $params) ? $params[$key] : $default,
		);
		$request->method('passesCSRFCheck')->willReturn($csrfOk);

		return new AttentionController(
			'ocrflow',
			$request,
			$attention,
			$session,
			$groups,
			new RetryPolicy(),
		);
	}

	private function attention(): StoreAttention
	{
		$queue = $this->createMock(Queue::class);
		return new StoreAttention($queue);
	}

	/**
	 * @param array<string, ?string> $overrides
	 * @return array<string, mixed>
	 */
	private function row(int $id, int $finishedAt, array $overrides = []): array
	{
		return array_merge([
			'id' => $id,
			'path' => 'alice/files/scan' . $id . '.pdf',
			'reason_code' => 'error_file_broken',
			'reason' => 'error_file_broken',
			'error' => 'the pdf is broken',
			'attempts' => 3,
			'finished_at' => $finishedAt,
		], $overrides);
	}

	public function testANonAdminIsRefusedOnIndex(): void
	{
		$this->expectException(OCSForbiddenException::class);
		$this->controller($this->attention(), [], csrfOk: true, admin: false)->index();
	}

	public function testANonAdminIsRefusedOnRetry(): void
	{
		$this->expectException(OCSForbiddenException::class);
		$this->controller($this->attention(), ['id' => 7], csrfOk: true, admin: false)->retry();
	}

	public function testIndexPaginates(): void
	{
		$attention = $this->attention();
		$attention->rows = [
			$this->row(1, 1000),
			$this->row(2, 3000),
			$this->row(3, 2000),
		];

		$response = $this->controller($attention, ['limit' => 2, 'offset' => 1])->index();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(3, $data['total']);
		// Newest first (higher finished_at is newer): 2, 3, 1; the page at
		// offset 1 with limit 2 is [3, 1].
		$this->assertSame([3, 1], array_column($data['rows'], 'id'));

		$this->assertCount(1, $attention->rowCalls);
		[$limit, $offset] = [$attention->rowCalls[0]['limit'], $attention->rowCalls[0]['offset']];
		$this->assertSame(2, $limit);
		$this->assertSame(1, $offset);
	}

	public function testIndexFiltersByCodeIncludingTheOldSpelling(): void
	{
		$attention = $this->attention();
		$attention->rows = [
			$this->row(1, 1000, ['reason_code' => 'error_provider_credentials_missing', 'reason' => 'error_provider_credentials_missing']),
			$this->row(2, 2000, ['reason_code' => null, 'reason' => 'credentials_missing']),
		];

		$data = $this->controller($attention, ['code' => 'error_provider_credentials_missing'])->index()->getData();

		$this->assertSame([2, 1], array_column($data['rows'], 'id'), 'the filter finds both spellings, newest first');
		$this->assertContains('credentials_missing', $attention->rowCalls[0]['spellings'], 'the SQL set carries the old alias');
	}

	public function testIndexTruncatesTheErrorProse(): void
	{
		$attention = $this->attention();
		$attention->rows = [
			$this->row(1, 1000, ['error' => str_repeat('x', 600)]),
		];

		$error = $this->controller($attention)->index()->getData()['rows'][0]['error'];

		$this->assertSame(501, mb_strlen($error));
		$this->assertStringEndsWith('…', $error);
	}

	public function testAnUnknownCodeFilterIsRejected(): void
	{
		$response = $this->controller($this->attention(), ['code' => 'error_nobody_knows'])->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testRetryRefusesAMissingCsrfToken(): void
	{
		$response = $this->controller($this->attention(), ['id' => 7], csrfOk: false)->retry();

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	public function testRetryRetriesOneRowThroughTheSharedPath(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturnCallback(
			static fn (int $id): ?Job => Job::fromRow([
				'id' => $id,
				'file_id' => 77,
				'path' => 'alice/files/scan.pdf',
				'status' => 'error',
				'reason' => null,
				'reason_code' => 'error_file_broken',
				'engine' => null,
				'claimed_by' => null,
				'attempts' => 3,
				'size' => null,
				'etag' => 'e1',
				'pages' => null,
				'chars' => null,
				'error' => 'broken',
				'created_at' => 1,
				'claimed_at' => null,
				'finished_at' => 2,
			]),
		);
		$queue->expects($this->once())->method('deleteTerminalForFile')->with(77)->willReturn(1);
		$queue->expects($this->once())->method('clearPermanentVerdict')->with(77);

		$attention = new StoreAttention($queue);
		$response = $this->controller($attention, ['id' => 7])->retry();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $data['retried']);
		// One line per retry, whichever door it came from.
		$this->assertCount(1, $attention->logLines);
		$this->assertStringContainsString('alice/files/scan.pdf', $attention->logLines[0]);
	}

	public function testRetryIsIdempotentWhenTheRowWasAlreadyRetried(): void
	{
		$queue = $this->createMock(Queue::class);
		// The first retry deleted the terminal row; a second call finds
		// nothing and must do no second work.
		$queue->method('getById')->willReturn(null);
		$queue->expects($this->never())->method('deleteTerminalForFile');
		$queue->expects($this->never())->method('clearPermanentVerdict');

		$attention = new StoreAttention($queue);
		$response = $this->controller($attention, ['id' => 7])->retry();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $data['retried']);
	}

	public function testRetryAllIsCapped(): void
	{
		$queue = $this->createMock(Queue::class);
		$jobs = [];
		foreach ([1, 2, 3, 4] as $i => $id) {
			$jobs[$id] = Job::fromRow([
				'id' => $id,
				'file_id' => 100 + $i,
				'path' => 'alice/files/scan' . $id . '.pdf',
				'status' => 'error',
				'reason' => null,
				'reason_code' => 'error_file_broken',
				'engine' => null,
				'claimed_by' => null,
				'attempts' => 1,
				'size' => null,
				'etag' => 'e',
				'pages' => null,
				'chars' => null,
				'error' => 'x',
				'created_at' => 1,
				'claimed_at' => null,
				'finished_at' => $id,
			]);
		}
		$queue->method('getById')->willReturnCallback(static fn (int $id): ?Job => $jobs[$id] ?? null);
		$queue->method('deleteTerminalForFile')->willReturn(1);

		$attention = new StoreAttention($queue, null, StoreAttention::config('2'));
		$attention->rows = array_map(
			static fn (int $id): array => [
				'id' => $id,
				'path' => 'a' . $id,
				'reason_code' => 'error_file_broken',
				'reason' => 'error_file_broken',
				'error' => 'x',
				'attempts' => 1,
				'finished_at' => $id,
			],
			[1, 2, 3, 4],
		);

		$response = $this->controller($attention, ['all' => true])->retry();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $data['retried'], 'the cap, not the worklist size, bounds one click');
		$this->assertSame(2, $data['cap']);
		$this->assertSame(2, $attention->idCalls[0]['limit'], 'the id select is bounded by the cap');
		$this->assertCount(2, $attention->logLines, 'one log line per retried row');
	}

	public function testRetryAllSelectsOnlyTheWorklistRows(): void
	{
		$attention = $this->attention();
		$attention->rows = [$this->row(1, 1000), $this->row(2, 2000)];

		$this->controller($attention, ['all' => true])->retry();

		$this->assertSame((new RetryPolicy())->spellingsForVerdict(RetryPolicy::VERDICT_NEEDS_FIX), $attention->idCalls[0]['spellings']);
	}
}
