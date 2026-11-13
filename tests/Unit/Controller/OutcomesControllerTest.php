<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Controller;

use OCA\OcrFlow\Controller\OutcomesController;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Tests\Unit\Service\StoreOutcomes;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The outcomes endpoints are the admin plane of the five tabs, other
 * people's document paths included. All tests here are [unit].
 *
 * The rules these pin, and the defect each catches:
 *
 *  - nothing on this controller answers a non-admin (GET or POST) —
 *    catches an accidentally public tab;
 *  - every POST is CSRF-checked — catches an `img` tag re-OCRing the
 *    archive;
 *  - the redo cap bounds one click — catches an unbounded paid batch;
 *  - an empty selection spends nothing — catches "retry everything the
 *    request mentioned", including the empty request;
 *  - the redo answer carries the estimate it just spent.
 */
class OutcomesControllerTest extends TestCase
{
	/**
	 * @param array<string, mixed> $params
	 */
	private function controller(StoreOutcomes $outcomes, array $params = [], bool $csrfOk = true, bool $admin = true): OutcomesController
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

		return new OutcomesController(
			'ocrflow',
			$request,
			$outcomes,
			$session,
			$groups,
			new RetryPolicy(),
		);
	}

	/**
	 * The full fixture: a needs_fix job, a below-bar stamp, a suspected
	 * foreign row and one pending stash (whose ledger twin exists too).
	 */
	private function store(): StoreOutcomes
	{
		$store = new StoreOutcomes([], [
			['id' => 1, 'path' => 'a/nf.pdf', 'reason_code' => 'error_file_broken', 'reason' => 'error_file_broken', 'error' => 'x', 'attempts' => 3, 'finished_at' => 300],
		]);
		$store->ledger = [
			['id' => 14, 'file_id' => 14, 'path' => 'a/below.pdf', 'status' => 'skipped', 'reason' => 'skip_ocred_by_ocrflow', 'engine' => 'google', 'engine_version' => '3.9.0', 'etag' => 'e14', 'updated_at' => 1014],
			['id' => 15, 'file_id' => 15, 'path' => 'a/foreign.pdf', 'status' => 'skipped', 'reason' => 'skip_ocred_by_suspected_foreign', 'engine' => null, 'engine_version' => null, 'etag' => 'e15', 'updated_at' => 1015],
			['id' => 16, 'file_id' => 16, 'path' => 'a/conflict.pdf', 'status' => 'skipped', 'reason' => 'skip_source_changed_result_saved', 'engine' => 'google', 'engine_version' => '3.15.0', 'etag' => 'e16', 'updated_at' => 1016],
		];
		$store->pending = [
			['id' => 16, 'file_id' => 16, 'etag' => 'e16', 'artifact_path' => 'pending-16-e16.pdf', 'engine' => 'google', 'engine_version' => '3.15.0', 'pages' => 2, 'created_at' => 1016, 'status' => 'pending'],
		];
		return $store;
	}

	/** [unit] a non-admin is refused on every endpoint, GET and POST alike */
	public function testANonAdminIsRefusedEverywhere(): void
	{
		$cases = [
			'index' => fn (OutcomesController $c) => $c->index(),
			'redo' => fn (OutcomesController $c) => $c->redo(),
			'apply' => fn (OutcomesController $c) => $c->apply(),
			'discard' => fn (OutcomesController $c) => $c->discard(),
		];
		$refused = [];
		foreach ($cases as $name => $call) {
			$controller = $this->controller($this->store(), ['ids' => [1]], csrfOk: true, admin: false);
			try {
				$call($controller);
			} catch (OCSForbiddenException) {
				$refused[] = $name;
			}
		}
		$this->assertSame(['index', 'redo', 'apply', 'discard'], $refused, 'every endpoint must refuse a non-admin');
	}

	/** [unit] GET /outcomes lists a verdict's rows and the summary */
	public function testIndexListsTheVerdictAndCarriesTheSummary(): void
	{
		$response = $this->controller($this->store(), ['verdict' => 'needs_fix'])->index();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $data['total']);
		$this->assertSame(['a/nf.pdf'], array_column($data['rows'], 'path'));
		$this->assertSame(1, $data['summary']['needs_fix']);
		$this->assertArrayHasKey('pricing', $data);
	}

	/** [unit] an unknown verdict filter is rejected, not dumped as needs_fix */
	public function testAnUnknownVerdictIsRejected(): void
	{
		$response = $this->controller($this->store(), ['verdict' => 'nonsense'])->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/** [unit] every POST is CSRF-checked */
	public function testThePostsRefuseAMissingCsrfToken(): void
	{
		$controller = $this->controller($this->store(), ['ids' => [14]], csrfOk: false);

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $controller->redo()->getStatus());
		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $controller->apply()->getStatus());
		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $controller->discard()->getStatus());
	}

	/** [unit] the cap bounds one redo click, and the answer carries the estimate */
	public function testRedoIsCappedAndAnswersWithItsEstimate(): void
	{
		$queue = $this->createMock(\OCA\OcrFlow\Db\Queue::class);
		$queue->method('deleteTerminalForFile')->willReturn(1);
		$attention = new \OCA\OcrFlow\Service\Attention(
			$queue,
			new RetryPolicy(),
			StoreOutcomes::configWith(['attention_retry_cap' => '1']),
		);
		$store = $this->store();
		$outcomes = new StoreOutcomes(['attention_retry_cap' => '1'], [], $attention);
		$outcomes->ledger = $store->ledger;
		$outcomes->pending = $store->pending;

		$response = $this->controller($outcomes, ['ids' => [14, 15, 16]])->redo();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $data['retried'], 'the cap bounds one click');
		$this->assertSame(1, $data['cap']);
		$this->assertSame(1, $data['estimate']['files']);
	}

	/** [unit] an empty selection spends nothing and is not an error */
	public function testAnEmptySelectionSpendsNothing(): void
	{
		$queue = $this->createMock(\OCA\OcrFlow\Db\Queue::class);
		$queue->expects($this->never())->method('deleteTerminalForFile');
		$attention = new \OCA\OcrFlow\Service\Attention($queue, new RetryPolicy(), StoreOutcomes::configWith([]));
		$store = new StoreOutcomes([], [], $attention);
		$store->ledger = $this->store()->ledger;

		$response = $this->controller($store, ['ids' => []])->redo();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $data['retried']);
		$this->assertSame(0, $data['estimate']['files']);
	}

	/** [unit] neither ids nor code is a client the redo endpoint understands */
	public function testRedoWithoutASelectionIsRejected(): void
	{
		$response = $this->controller($this->store(), [])->redo();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/** [unit] apply and discard delegate to the stash by row id */
	public function testApplyAndDiscardAnswerTheStashRow(): void
	{
		$store = $this->store();

		$apply = $this->controller($store, ['id' => 7])->apply()->getData();
		$this->assertSame('refused', $apply['status']);
		$this->assertNotSame('', $apply['warning'], 'a refusal carries the warning a human reads');

		$discard = $this->controller($store, ['id' => 7])->discard()->getData();
		$this->assertSame('discarded', $discard['status']);
	}
}
