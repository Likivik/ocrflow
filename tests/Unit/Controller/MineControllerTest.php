<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\Controller\MineController;
use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\Mine;
use OCA\OcrFlow\Tests\Unit\Service\StoreAttention;
use OCA\OcrFlow\Tests\Unit\Service\StoreOutcomes;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * [unit] The scope of the personal view is the SESSION, never the request.
 *
 * A user-scoped endpoint whose scope can be named by the client is an
 * admin-only endpoint with extra steps: `?uid=bob` would list Bob's files.
 * These tests pass exactly such a parameter and assert it changes nothing.
 */
class MineControllerTest extends TestCase
{
	/**
	 * @param array<string, mixed> $params
	 */
	private function controller(StoreAttention $attention, array $params = [], bool $csrfOk = true, ?string $uid = 'alice'): MineController
	{
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

		return new MineController('ocrflow', $request, new Mine($attention, new StoreOutcomes()), $session);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jobRow(int $id, string $path): array
	{
		return [
			'id' => $id,
			'path' => $path,
			'reason_code' => 'error_file_broken',
			'reason' => 'error_file_broken',
			'error' => null,
			'attempts' => 1,
			'finished_at' => 1000 + $id,
		];
	}

	public function testBothEndpointsAreReachableByANormalUser(): void
	{
		// OCS refuses a route without this attribute to every non-admin with
		// "Logged in account must be an admin" BEFORE the controller runs, so
		// the unit fake cannot see it and only the real instance can. Caught
		// that way once; pinned here so a future edit cannot drop it.
		foreach (['index', 'retry'] as $method) {
			$attributes = (new \ReflectionMethod(MineController::class, $method))
				->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class);
			$this->assertCount(1, $attributes,
				'MineController::' . $method . ' must carry #[NoAdminRequired], or every normal user gets a 403');
		}
	}

	public function testAUidParameterCannotWidenTheScope(): void
	{
		$attention = new StoreAttention($this->createMock(Queue::class));
		$attention->rows = [
			$this->jobRow(1, '/alice/files/a.pdf'),
			$this->jobRow(2, '/bob/files/b.pdf'),
		];

		// The attacker's request: "show me bob's rows".
		$response = $this->controller($attention, ['uid' => 'bob', 'user' => 'bob'])->index();
		$data = $response->getData();

		$this->assertSame(1, $data['total']);
		$this->assertSame('/alice/files/a.pdf', $data['rows'][0]['path']);
		$this->assertSame('/alice/files/', $attention->rowCalls[0]['prefix'],
			'the prefix comes from the session uid, whatever the request named');
	}

	public function testRetryingAnotherUsersIdThroughTheControllerIsRefused(): void
	{
		$job = new Job();
		$job->setId(42);
		$job->setPath('/bob/files/b.pdf');
		$job->setReasonCode('error_file_broken');
		$job->setFileId(42);

		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturn($job);
		$queue->expects($this->never())->method('deleteTerminalForFile');

		$response = $this->controller(new StoreAttention($queue), ['ids' => [42]])->retry();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $data['retried']);
		$this->assertSame(1, $data['refused']);
	}

	public function testTheRetryNeedsACsrfToken(): void
	{
		$queue = $this->createMock(Queue::class);
		$queue->expects($this->never())->method('getById');

		$response = $this->controller(new StoreAttention($queue), ['ids' => [1]], csrfOk: false)->retry();

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	public function testTheRetryNeedsAnIdList(): void
	{
		$response = $this->controller(new StoreAttention($this->createMock(Queue::class)))->retry();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testThereIsNoSessionNoRows(): void
	{
		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$this->controller(new StoreAttention($this->createMock(Queue::class)), uid: null)->index();
	}

	public function testTheRetryOnlyTouchesTheCallersIds(): void
	{
		$alice = new Job();
		$alice->setId(1);
		$alice->setPath('/alice/files/a.pdf');
		$alice->setReasonCode('error_file_broken');
		$alice->setFileId(1);

		$queue = $this->createMock(Queue::class);
		$queue->method('getById')->willReturn($alice);
		$queue->expects($this->once())->method('deleteTerminalForFile')->with(1)->willReturn(1);

		$data = $this->controller(new StoreAttention($queue), ['ids' => [1]])->retry()->getData();

		$this->assertSame(1, $data['retried']);
		$this->assertSame(0, $data['refused']);
	}
}
