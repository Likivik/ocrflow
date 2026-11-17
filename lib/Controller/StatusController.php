<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\BackgroundJob\ScanJob;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Service\AccessGate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The user plane: what the Files sidebar and the admin settings talk to.
 *
 * Every method runs as the logged-in user, so Nextcloud's own filesystem
 * permissions decide what can be scanned — the app never enumerates another
 * user's tree.
 */
class StatusController extends OCSController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private Queue $queue,
		private IJobList $jobs,
		private IUserSession $userSession,
		private AccessGate $access,
		private EnginePolicy $engine,
		private ?RetryPolicy $retryPolicy = null,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse
	{
		$report = $this->queue->fileReport($this->uid());

		return new DataResponse([
			'queue' => $this->queue->counts(),
			// "Skipped" is six decisions, not one: a photo, a born-digital
			// PDF, our own stamp, a foreign tesseract layer, an unsupported
			// type. Split by the code the worker already records, with the
			// sentence the app already writes for that code.
			// What happened to the files, per file: six blocks, each with the
			// rows that add up to its total. This is the report; `queue` stays
			// for the filter chips only.
			'blocks' => $this->blocks(),
			// Outside the processable set but still real files on disk.
			'footnote' => [
				'empty' => $report['empty'],
				'unreadable_format' => $report['unreadable_format'],
			],
			// Whether the instance will do OCR at all. The sidebar can say
			// "not configured" instead of offering a button that queues nothing.
			'engine' => $this->engine->current(),
			'engine_configured' => $this->engine->isConfigured(),
		]);
	}

	/**
	 * Six blocks, each with the rows that add up to it.
	 *
	 * The block comes from the outcome code (`RetryPolicy::blockOf`), never from
	 * the row's `status`: 7 live rows carry `status='skipped'` while their
	 * verdict is "needs a person", and grouping by status put them in the wrong
	 * block for as long as anyone looked at it.
	 *
	 * @return list<array{id: string, label: string, count: int, rows: list<array<string, mixed>>}>
	 */
	private function blocks(): array
	{
		$report = $this->queue->fileReport($this->uid());
		$policy = $this->retryPolicy ?? new RetryPolicy();

		// Files, by the block their latest outcome belongs to.
		$byBlock = [];
		foreach ($report['by_code'] as $code => $count) {
			$byBlock[$policy->blockOf((string) $code)] = ($byBlock[$policy->blockOf((string) $code)] ?? 0) + $count;
		}

		$blocks = [];
		foreach (RetryPolicy::BLOCKS as $id => $label) {
			$rows = [];
			$count = $byBlock[$id] ?? 0;

			if ($id === 'read') {
				foreach ($report['read_by_engine'] as $engineRow) {
					$engine = $engineRow['engine'];
					$version = $engineRow['version'];
					$label2 = $engine === null
						? 'by an engine we did not record'
						: ($version === null ? 'by ' . $engine . ', version not recorded' : 'by ' . $engine . ' ' . $version);
					$rows[] = [
						'key' => ($engine ?? '') . '|' . ($version ?? ''),
						'label' => $label2,
						'count' => $engineRow['count'],
						// A known version below the bar is the one case worth
						// re-reading; an unknown version is unknown, not bad.
						'below_bar' => $engine !== null && $version !== null && !$this->engine->meetsBar($engine, $version),
					];
				}
				$noText = $report['by_code']['skip_ocred_by_ocrflow_found_nothing'] ?? 0;
				if ($noText > 0) {
					$rows[] = [
						'key' => 'no_text_found',
						'label' => 'read it, no text to transcribe',
						'count' => $noText,
					];
				}
			}

			if ($id === 'queue') {
				$rows[] = ['key' => 'queued', 'label' => 'waiting to be processed', 'count' => $report['queue']['queued']];
				$rows[] = ['key' => 'claimed', 'label' => 'being processed right now', 'count' => $report['queue']['claimed']];
				$count = $report['queue']['queued'] + $report['queue']['claimed'];
			}

			if ($id === 'error') {
				foreach ($report['by_code'] as $code => $n) {
					if ($policy->blockOf((string) $code) !== 'error') {
						continue;
					}
					$rows[] = [
						'key' => (string) $code,
						'label' => $policy->entry((string) $code)['text'] ?? (string) $code,
						'count' => $n,
					];
				}
			}

			$blocks[] = [
				'id' => $id,
				'label' => $label,
				// "Not processed yet" is a fact about the disk, not about a code.
				'count' => $id === 'not_processed' ? $report['never_processed'] : $count,
				'rows' => $rows,
			];
		}
		return $blocks;
	}

	/**
	 * The current user's id. The report is per user: their tree, their files.
	 */
	private function uid(): string
	{
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	#[NoAdminRequired]
	public function job(int $id): DataResponse
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('no session');
		}
		$job = $this->queue->getById($id);
		// "Does not exist" and "not yours" must be the same answer: a guessed
		// id must not tell an attacker which is which.
		if ($job === null || !$this->ownsPath($user, $job->getPath())) {
			return new DataResponse(['message' => 'no such job'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($job->toApi());
	}

	/**
	 * Queue a whole folder. Returns immediately with the background job's name:
	 * a folder scan of a real archive is thousands of nodes and cannot live in an
	 * HTTP request.
	 *
	 * @throws OCSForbiddenException
	 */
	#[NoAdminRequired]
	public function scan(): DataResponse
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('no session');
		}
		// Group list + per-user opt-out. Empty group list means everyone, so
		// this is a no-op for an instance that never configured it.
		if (!$this->access->canProcess($user)) {
			throw new OCSForbiddenException('OCR is not enabled for your account');
		}

		// Not configured is not forbidden: 409 says "ask your administrator",
		// which is true, and keeps the distinction visible to the client.
		if (!$this->engine->isConfigured()) {
			return new DataResponse([
				'message' => 'No OCR engine is configured',
				'engine' => EnginePolicy::NONE,
			], Http::STATUS_CONFLICT);
		}

		$path = (string) $this->request->getParam('path', '/');
		$this->jobs->add(ScanJob::class, ['user' => $user->getUID(), 'path' => $path]);

		return new DataResponse(['queued' => true, 'path' => $path]);
	}

	/**
	 * Whether this path belongs to this user.
	 *
	 * Every queued path is `<uid>/files/<rel>` — the shape
	 * QueueController::splitPath() defines. Job metadata (its path, its error
	 * text, its page count) belongs to the file's owner.
	 */
	private function ownsPath(IUser $user, string $path): bool
	{
		$parts = QueueController::splitPath($path);
		return $parts !== null && $parts[0] === $user->getUID();
	}

	/**
	 * Per-file answer for the sidebar. `status` is this app's opinion of the
	 * file, not the file's own metadata — the sidebar must not have to download
	 * a 40 MB scan to render a label.
	 */
	#[NoAdminRequired]
	public function file(): DataResponse
	{
		$path = (string) $this->request->getParam('path', '');
		if ($path === '') {
			return new DataResponse(['message' => 'path is required'], Http::STATUS_BAD_REQUEST);
		}
		$user = $this->userSession->getUser();
		// Not yours → the same answer as "never queued": probing foreign paths
		// must learn nothing about other users' documents.
		if ($user === null || !$this->ownsPath($user, $path)) {
			return new DataResponse(['path' => $path, 'status' => 'unknown']);
		}
		$job = $this->queue->findByPath($path);
		if ($job === null) {
			return new DataResponse(['path' => $path, 'status' => 'unknown']);
		}
		return new DataResponse([
			'path' => $path,
			'status' => $job->getStatus(),
			'reason' => $job->getReason(),
			'engine' => $job->getEngine(),
			'pages' => $job->getPages(),
			'chars' => $job->getChars(),
		]);
	}
}
