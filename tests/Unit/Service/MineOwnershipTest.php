<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Mine;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\UserScope;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The user-scoped view cannot cross users.
 *
 * The admin planes refuse non-admins precisely because their rows name other
 * people's documents. The personal view must keep that property while still
 * answering a normal user, so the rule these tests pin is: the scope is the
 * authenticated uid's `/<uid>/files/` prefix, applied to the STORED path —
 * which means an id that belongs to someone else is refused AND the retry
 * never runs.
 *
 * The recount test is the one that matters: it asserts the queue was not
 * touched at all, not merely that the count came back zero. A "refused" that
 * still deleted the terminal rows would look identical from the outside.
 */
class MineOwnershipTest extends TestCase
{
	private function attentionWith(Queue $queue): StoreAttention
	{
		return new StoreAttention($queue);
	}

	private function outcomes(): StoreOutcomes
	{
		return new StoreOutcomes();
	}

	/**
	 * @param array<string, ?string> $overrides
	 * @return array<string, mixed>
	 */
	private function jobRow(int $id, string $path, int $finishedAt, array $overrides = []): array
	{
		return array_merge([
			'id' => $id,
			'path' => $path,
			'reason_code' => 'error_file_broken',
			'reason' => 'error_file_broken',
			'error' => null,
			'attempts' => 2,
			'finished_at' => $finishedAt,
		], $overrides);
	}

	public function testAUserCannotRetryAnotherUsersJob(): void
	{
		$job = new Job();
		$job->setId(42);
		$job->setPath('/bob/files/private/scan.pdf');
		$job->setReasonCode('error_file_broken');
		$job->setFileId(7);

		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturn($job);
		// The whole point: not one of the retry's writes may happen.
		$queue->expects($this->never())->method('deleteTerminalForFile');
		$queue->expects($this->never())->method('clearPermanentVerdict');
		$queue->expects($this->never())->method('resetTimesEnqueued');

		$mine = new Mine($this->attentionWith($queue), $this->outcomes());
		$result = $mine->retry('alice', [42]);

		$this->assertSame(0, $result['retried'], 'another user\'s id must not be retried');
		$this->assertSame(1, $result['refused'], 'and it must be REPORTED as refused, not silently skipped');
		$this->assertSame([42], $result['refused_ids']);
	}

	public function testTheSameIdIsRetriedWhenItIsTheCallers(): void
	{
		$job = new Job();
		$job->setId(42);
		$job->setPath('/alice/files/scan.pdf');
		$job->setReasonCode('error_file_broken');
		$job->setFileId(7);

		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturn($job);
		$queue->expects($this->once())->method('deleteTerminalForFile')->with(7)->willReturn(1);

		$mine = new Mine($this->attentionWith($queue), $this->outcomes());
		$result = $mine->retry('alice', [42]);

		$this->assertSame(1, $result['retried']);
		$this->assertSame(0, $result['refused']);
	}

	public function testTheListReturnsOnlyTheCallersRows(): void
	{
		$queue = $this->createMock(Queue::class);
		$attention = $this->attentionWith($queue);
		$attention->rows = [
			$this->jobRow(1, '/alice/files/a.pdf', 1000),
			$this->jobRow(2, '/bob/files/b.pdf', 1001),
			// The near-miss a substring check would wrongly call Alice's.
			$this->jobRow(3, '/alice/filesx/c.pdf', 1002),
		];

		$mine = new Mine($attention, $this->outcomes());
		$result = $mine->list('alice', 50);

		$this->assertSame(1, $result['total']);
		$this->assertSame(['/alice/files/a.pdf'], array_column($result['rows'], 'path'));
		foreach ($result['rows'] as $row) {
			$this->assertStringNotContainsString('/bob/', $row['path']);
		}
		// And the query itself was scoped: filtering only in PHP would make
		// the total (and any future LIMIT) lie.
		$this->assertSame('/alice/files/', $attention->rowCalls[0]['prefix']);
	}

	public function testAnotherUserSeesNothingOfMine(): void
	{
		$queue = $this->createMock(Queue::class);
		$attention = $this->attentionWith($queue);
		$attention->rows = [$this->jobRow(1, '/alice/files/a.pdf', 1000)];

		$mine = new Mine($attention, $this->outcomes());
		$result = $mine->list('bob', 50);

		$this->assertSame(0, $result['total']);
		$this->assertSame([], $result['rows']);
	}

	/**
	 * The admin plane reads the same query with no prefix. If adding the
	 * scope had narrowed it by accident, every admin tab would silently lose
	 * rows — a regression that looks like "the queue is empty".
	 */
	public function testTheAdminListingIsStillUnscoped(): void
	{
		$queue = $this->createMock(Queue::class);
		$attention = $this->attentionWith($queue);
		$attention->rows = [
			$this->jobRow(1, '/alice/files/a.pdf', 1000),
			$this->jobRow(2, '/bob/files/b.pdf', 1001),
		];

		$result = $attention->list(50);

		$this->assertSame(2, $result['total'], 'the admin listing lists every user');
		$this->assertNull($attention->rowCalls[0]['prefix'],
			'the admin listing must not pass a prefix at all');
	}

	public function testRetryCountsIdsPastTheCapAsRefused(): void
	{
		$queue = $this->createMock(Queue::class);
		// cap comes from config; StoreAttention::config() defaults to 500,
		// so a smaller cap is built from the same fake with its own value.
		$attention = new StoreAttention($queue, null, StoreAttention::config('1'), null);

		$job = static function (int $id, string $path): Job {
			$j = new Job();
			$j->setId($id);
			$j->setPath($path);
			$j->setReasonCode('error_file_broken');
			$j->setFileId($id);
			return $j;
		};
		$queue->method('getById')->willReturnCallback(
			static fn (int $id): Job => $job($id, '/alice/files/' . $id . '.pdf'),
		);
		// One attempt only, whatever the request names.
		$queue->expects($this->once())->method('deleteTerminalForFile')->willReturn(1);

		$mine = new Mine($attention, $this->outcomes());
		$result = $mine->retry('alice', [1, 2, 3]);

		$this->assertSame(1, $result['retried']);
		$this->assertSame(2, $result['refused'], 'the ids past the cap are refused, and the count says so');
		$this->assertSame(1, $result['cap']);
	}

	public function testAPrefixIsNeverWidenedToEverything(): void
	{
		$this->assertFalse(UserScope::owns('/alice/files/x.pdf', ''), 'an empty scope owns nothing');
		$this->assertFalse(UserScope::owns('/alice/files/x.pdf', '/alice/files'), 'a prefix without the trailing slash is refused');
		$this->assertFalse(UserScope::owns('/alice/files', '/alice/files/'), 'the folder itself is not inside it');
		$this->assertFalse(UserScope::owns('/bob/files/alice/files/x', '/alice/files/'), 'a substring later in the path is not a prefix');
		$this->assertTrue(UserScope::owns('/alice/files/x.pdf', '/alice/files/'));
		$this->assertSame('/alice/files/', UserScope::prefixFor('alice'));
		$this->assertSame('', UserScope::prefixFor(''));
	}

	public function testRedoRowsAreScopedToo(): void
	{
		$queue = $this->createMock(Queue::class);
		$outcomes = $this->outcomes();
		$outcomes->ledger = [
			[
				'id' => 11, 'file_id' => 11, 'path' => '/alice/files/a.pdf', 'status' => 'skipped',
				'reason' => 'skip_ocred_by_suspected_foreign', 'engine' => 'google', 'engine_version' => '1.0.0',
				'etag' => 'e11', 'updated_at' => 1000,
			],
			[
				'id' => 12, 'file_id' => 12, 'path' => '/bob/files/b.pdf', 'status' => 'skipped',
				'reason' => 'skip_ocred_by_suspected_foreign', 'engine' => 'google', 'engine_version' => '1.0.0',
				'etag' => 'e12', 'updated_at' => 1001,
			],
		];

		$mine = new Mine($this->attentionWith($queue), $outcomes);
		$result = $mine->list('alice', 50);

		$paths = array_column($result['rows'], 'path');
		$this->assertSame(['/alice/files/a.pdf'], $paths);
		$this->assertSame('redo', $result['rows'][0]['kind']);
	}

	public function testARetryOfAnotherUsersDecideRowIsRefused(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturn(null);   // not a job id at all
		$queue->expects($this->never())->method('deleteTerminalForFile');

		$outcomes = $this->outcomes();
		$outcomes->ledger = [[
			'id' => 12, 'file_id' => 12, 'path' => '/bob/files/b.pdf', 'status' => 'skipped',
			'reason' => 'skip_ocred_by_suspected_foreign', 'engine' => 'google', 'engine_version' => '1.0.0',
			'etag' => 'e12', 'updated_at' => 1001,
		]];

		$mine = new Mine($this->attentionWith($queue), $outcomes);
		$result = $mine->retry('alice', [12]);

		$this->assertSame(0, $result['retried']);
		$this->assertSame([12], $result['refused_ids']);
	}

	public function testTheRetryCapIsTheAttentionCap(): void
	{
		$queue = $this->createMock(Queue::class);
		$attention = $this->attentionWith($queue);
		// Same config key, same value — one cap for both planes.
		$this->assertSame($attention->cap(), (new Mine($attention, $this->outcomes()))->retry('alice', [])['cap']);
	}

	/**
	 * Guard against the fixture drifting off the real vocabulary: a test
	 * that scopes rows nobody would ever produce proves nothing.
	 */
	public function testTheFixturesAreRealCodesOfTheirVerdicts(): void
	{
		$policy = new RetryPolicy();
		$this->assertContains('error_file_broken', $policy->spellingsForVerdict(RetryPolicy::VERDICT_NEEDS_FIX));
		$this->assertSame(RetryPolicy::VERDICT_REDO, $policy->verdict('skip_ocred_by_suspected_foreign'));
	}
}
