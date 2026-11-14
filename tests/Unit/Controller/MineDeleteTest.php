<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\Controller\MineController;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Mine;
use OCA\OcrFlow\Tests\Unit\Service\StoreAttention;
use OCA\OcrFlow\Tests\Unit\Service\StoreOutcomes;
use OCP\AppFramework\Http;
use OCP\Files\Config\ICachedMountFileInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * [unit] Delete removes the FILE, through the Files API, and only for its owner.
 *
 * The panel's Delete is a user-facing destructive action, so the failure that
 * matters is not "it did not delete" but "it deleted someone else's file" and
 * "it deleted a file in a way the trash bin never sees". Those are two
 * different mechanisms and this test pins both:
 *
 *   * ownership is decided by the node's own path against the caller's
 *     `/<uid>/files/` scope, so a foreign file id is a 403 and the delete is
 *     never even attempted (asserted as `never()`, not as a zero count);
 *   * the deletion is `$node->delete()` on the node resolved from the
 *     caller's own user folder — the filesystem-layer call the files_trashbin
 *     hooks intercept. A 404 for a vanished node, never a 500.
 *
 * Bulk is the same decision applied to a list, and it must REPORT what it
 * refused: "deleted 2" with no mention of the third id reads like the third
 * file was deleted too.
 */
class MineDeleteTest extends TestCase
{
	/**
	 * @param array<string, mixed> $params
	 * @param array<int, string> $heldBy file id => uid of the user whose mount holds it.
	 *   The mount cache is how the server tells "nobody has this id" (404) from
	 *   "somebody else has it" (403); a fake that could not answer that would
	 *   hide the very decision under test.
	 */
	private function controller(
		StoreAttention $attention,
		IRootFolder $rootFolder,
		array $params = [],
		bool $csrfOk = true,
		?string $uid = 'alice',
		array $heldBy = [],
	): MineController {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $params) ? $params[$key] : $default,
		);
		$request->method('passesCSRFCheck')->willReturn($csrfOk);

		$mountCache = $this->createMock(IUserMountCache::class);
		$mountCache->method('getMountsForFileId')->willReturnCallback(
			function (int $id) use ($heldBy): array {
				if (!isset($heldBy[$id])) {
					return [];
				}
				$owner = $this->createMock(IUser::class);
				$owner->method('getUID')->willReturn($heldBy[$id]);
				$mount = $this->createMock(ICachedMountFileInfo::class);
				$mount->method('getUser')->willReturn($owner);
				return [$mount];
			},
		);

		return new MineController(
			'ocrflow',
			$request,
			new Mine($attention, new StoreOutcomes(), null, $rootFolder, $mountCache),
			$session,
		);
	}

	/**
	 * A user folder whose getById() answers with the given nodes, and a root
	 * folder that hands that folder out for $uid — and for nobody else.
	 *
	 * A user folder only resolves ids inside that user's own space, so a
	 * foreign id comes back EMPTY here — which is what the real one does, and
	 * why the distinction has to come from the mount cache instead.
	 *
	 * @param array<int, Node> $nodesById what the caller's own folder resolves
	 */
	private function rootFolder(string $uid, array $nodesById): IRootFolder
	{
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			static fn (int $id): array => isset($nodesById[$id]) ? [$nodesById[$id]] : [],
		);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturnCallback(
			static function (string $asked) use ($uid, $folder): Folder {
				if ($asked !== $uid) {
					throw new \OCP\Files\NotPermittedException('no such user folder');
				}
				return $folder;
			},
		);
		return $rootFolder;
	}

	public function testDeletingYourOwnFileGoesThroughTheFilesApi(): void
	{
		$node = $this->createMock(Node::class);
		$node->method('getPath')->willReturn('/alice/files/scan.pdf');
		// The trash bin hooks the filesystem delete; a WebDAV-style call is
		// not part of this path and must not appear.
		$node->expects($this->once())->method('delete');

		$response = $this->controller(new StoreAttention($this->createMock(Queue::class)), $this->rootFolder('alice', [7 => $node]))
			->delete(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['deleted'] ?? null);
	}

	public function testAnotherUsersFileIsForbiddenAndNeverDeleted(): void
	{
		$node = $this->createMock(Node::class);
		$node->method('getPath')->willReturn('/bob/files/private.pdf');
		$node->expects($this->never())->method('delete');

		// Alice asks for Bob's file id. Her own folder cannot see it; the
		// mount cache says Bob's mount holds it, and that is what makes the
		// answer 403 rather than 404.
		$response = $this->controller(
			new StoreAttention($this->createMock(Queue::class)),
			$this->rootFolder('alice', []),
			heldBy: [9 => 'bob'],
		)->delete(9);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('deleted', $response->getData());
	}

	/**
	 * Belt and braces: even if a resolution ever handed us a node outside the
	 * caller's scope, the PATH check refuses it. The scope rule is applied to
	 * the node we are about to delete, not only to the lookup that found it.
	 */
	public function testANodeOutsideTheScopeIsRefusedEvenWhenResolved(): void
	{
		$node = $this->createMock(Node::class);
		$node->method('getPath')->willReturn('/bob/files/private.pdf');
		$node->expects($this->never())->method('delete');

		$response = $this->controller(
			new StoreAttention($this->createMock(Queue::class)),
			$this->rootFolder('alice', [9 => $node]),
		)->delete(9);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAMissingFileIsANotFoundAndNotAnError(): void
	{
		$response = $this->controller(new StoreAttention($this->createMock(Queue::class)), $this->rootFolder('alice', []))
			->delete(11);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testTheDeleteNeedsACsrfToken(): void
	{
		$node = $this->createMock(Node::class);
		$node->method('getPath')->willReturn('/alice/files/scan.pdf');
		$node->expects($this->never())->method('delete');

		$response = $this->controller(
			new StoreAttention($this->createMock(Queue::class)),
			$this->rootFolder('alice', [7 => $node]),
			csrfOk: false,
		)->delete(7);

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	/**
	 * The row a listing returns must carry the file id the Open link and the
	 * Delete need. A retry id (job id / ledger id) is NOT the file id, and
	 * using it would open whatever file happens to have that number.
	 */
	public function testEveryListedRowCarriesItsFileId(): void
	{
		$queue = $this->createMock(Queue::class);
		$attention = new StoreAttention($queue);
		$attention->rows = [[
			'id' => 42,
			'file_id' => 4242,
			'path' => '/alice/files/a.pdf',
			'reason_code' => 'error_file_broken',
			'reason' => 'error_file_broken',
			'error' => null,
			'attempts' => 2,
			'finished_at' => 1000,
		]];

		$rows = (new Mine($attention, new StoreOutcomes()))->list('alice', 50)['rows'];

		$this->assertSame(4242, $rows[0]['file_id'] ?? null, 'the panel needs the file id to Open and Delete');
		$this->assertSame(42, $rows[0]['id'], 'and the retry id stays the retry id');
	}
}
