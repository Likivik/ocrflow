<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Command;

use OCA\OcrFlow\Command\Outcomes;
use OCA\OcrFlow\Controller\OutcomesController;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Tests\Unit\Service\StoreOutcomes;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The terminal door reads the SAME service as the endpoint, so the two
 * cannot disagree about whose work it is. All tests here are [unit].
 *
 * The defect this catches: a second listing implementation inside the
 * command (or the controller) drifting from the panel's — exactly the
 * "three doors, three answers" bug class the attention worklist fixed.
 */
class OutcomesCommandTest extends TestCase
{
	private function store(): StoreOutcomes
	{
		$store = new StoreOutcomes([], [
			['id' => 1, 'path' => 'a/nf.pdf', 'reason_code' => 'error_file_broken', 'reason' => 'error_file_broken', 'error' => 'x', 'attempts' => 3, 'finished_at' => 300],
		]);
		$store->ledger = [
			['id' => 14, 'file_id' => 14, 'path' => 'a/below.pdf', 'status' => 'skipped', 'reason' => 'skip_ocred_by_ocrflow', 'engine' => 'google', 'engine_version' => '3.9.0', 'etag' => 'e14', 'updated_at' => 1014],
			['id' => 16, 'file_id' => 16, 'path' => 'a/conflict.pdf', 'status' => 'skipped', 'reason' => 'skip_source_changed_result_saved', 'engine' => 'google', 'engine_version' => '3.15.0', 'etag' => 'e16', 'updated_at' => 1016],
		];
		$store->pending = [
			['id' => 16, 'file_id' => 16, 'etag' => 'e16', 'artifact_path' => 'pending-16-x.pdf', 'engine' => 'google', 'engine_version' => '3.15.0', 'pages' => 2, 'created_at' => 1016, 'status' => 'pending'],
		];
		return $store;
	}

	private function controller(StoreOutcomes $outcomes, array $params): OutcomesController
	{
		// The request/session/group mocks identical to the controller test's;
		// reuse its private recipe by construction is not possible across
		// test classes, so the small mock set is rebuilt here.
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('root');
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $params) ? $params[$key] : $default,
		);
		$request->method('passesCSRFCheck')->willReturn(true);
		return new OutcomesController('ocrflow', $request, $outcomes, $session, $groups, new RetryPolicy());
	}

	/**
	 * [unit] The endpoint and the occ command return IDENTICAL ids for one
	 * fixture: both doors read the one service, so their answers cannot
	 * drift. Catches a command-side reimplementation of the listing.
	 */
	public function testTheCommandAndTheEndpointReturnIdenticalIds(): void
	{
		$store = $this->store();

		// The endpoint's answer: file ids of the redo rows.
		$data = $this->controller($store, ['verdict' => 'redo'])->index()->getData();
		$endpointIds = array_map(
			static fn (array $row): string => $row['origin'] . ':' . $row['id'],
			$data['rows'],
		);

		// The command's answer: the same ids, parsed from its table output
		// (columns are space-separated; the first two are origin and id).
		$tester = new CommandTester(new Outcomes($store));
		$tester->execute(['--verdict' => 'redo']);
		$this->assertSame(0, $tester->getStatusCode());

		$commandIds = [];
		foreach (explode("\n", trim($tester->getDisplay())) as $line) {
			$parts = preg_split('/\s+/', trim($line));
			if ($parts !== false && in_array($parts[0] ?? '', ['job', 'ledger', 'stash'], true)) {
				$commandIds[] = $parts[0] . ':' . $parts[1];
			}
		}

		$this->assertSame($endpointIds, $commandIds, 'the endpoint and occ must name the same rows in the same order');
	}

	/** [unit] the default verdict is redo, and the total is printed */
	public function testTheDefaultVerdictIsRedoAndTheTotalIsPrinted(): void
	{
		$tester = new CommandTester(new Outcomes($this->store()));
		$tester->execute([]);
		$display = $tester->getDisplay();

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('a/conflict.pdf', $display);
		$this->assertStringContainsString('a/below.pdf', $display);
		$this->assertMatchesRegularExpression('/2 redo/', $display);
	}

	/** [unit] an empty tab prints a clean zero, not a stack trace */
	public function testAnEmptyTabIsNotAnError(): void
	{
		$tester = new CommandTester(new Outcomes($this->store()));
		$tester->execute(['--verdict' => 'dropped']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('0 dropped', $tester->getDisplay());
	}
}
