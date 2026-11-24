<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Controller;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\BackgroundJob\ScanJob;
use OCA\OcrFlow\Storage\DailyBudget;
use OCA\OcrFlow\Storage\EnqueueService;
use OCA\OcrFlow\Storage\JobStore;
use OCA\OcrFlow\Report\CategoryRow;
use OCA\OcrFlow\Report\PanelReport;
use OCA\OcrFlow\Service\AccessGate;
use OCA\OcrFlow\Service\EnginePolicy;
use OCA\OcrFlow\Service\OutcomeCatalog;
use OCA\OcrFlow\Service\RetryPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
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
	/** Files one click takes when the panel says nothing. */
	private const DEFAULT_PROCESS_LIMIT = 25;
	public function __construct(
		string $appName,
		IRequest $request,
		private JobStore $jobStore,
		private EnqueueService $enqueue,
		private IJobList $jobs,
		private IUserSession $userSession,
		private AccessGate $access,
		private EnginePolicy $engine,
		private PanelReport $panelReport,
		private DailyBudget $budget,
		private ?RetryPolicy $retryPolicy = null,
		private ?IConfig $config = null,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse
	{
		// One report per request, from aggregates. It used to be computed twice
		// per load — once here and once inside blocks() — each time over every
		// file id the user owns.
		$report = $this->panelReport->forUser($this->uid());

		return new DataResponse([
			'queue' => $this->jobStore->counts(),
			// "Skipped" is six decisions, not one: a photo, a born-digital
			// PDF, our own stamp, a foreign tesseract layer, an unsupported
			// type. Split by the code the worker already records, with the
			// sentence the app already writes for that code.
			// What happened to the files, per file: six blocks, each with the
			// rows that add up to its total. This is the report; `queue` stays
			// for the filter chips only.
			'blocks' => $this->blocks($report),
			// The button's own numbers, next to the counts they belong to:
			// how many it takes, what is left of the day, and what the last
			// month says a file costs.
			'process' => [
				'batch_size' => $this->batchSizePreference(),
				'max_files' => $this->budget->perClickCap(),
				'daily_used' => $this->budget->used($this->uid()),
				'daily_cap' => $this->budget->cap(),
				'estimate' => $this->panelReport->pageStatsFor($this->uid()),
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
	private function blocks(array $report): array
	{
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
					$label2 = OutcomeCatalog::engine($engine, $version);
					// A known version below the bar is the one case worth
					// re-reading; an unknown version is unknown, not bad.
					$rows[] = \OCA\OcrFlow\Report\CategoryRow::of(
						($engine ?? '') . '|' . ($version ?? ''),
						$label2,
						$engineRow['count'],
						null,
						$engine !== null && $version !== null && !$this->engine->meetsBar($engine, $version),
					);
				}
				// "Nothing to transcribe" is a read: the reader is the fact the
				// owner cares about, so this row is named by the engine that
				// looked and found nothing — not by us in the abstract.
				foreach ($report['no_text_by_engine'] as $noText) {
					$rows[] = \OCA\OcrFlow\Report\CategoryRow::of(
					'no_text|' . ($noText['engine'] ?? ''),
					OutcomeCatalog::reader($noText['engine']) . ', no text to transcribe',
					$noText['count'],
				);
				}
			}

			if ($id === 'queue') {
				$rows[] = \OCA\OcrFlow\Report\CategoryRow::of('queued', 'Waiting to be processed', $report['queue']['queued']);
				$rows[] = \OCA\OcrFlow\Report\CategoryRow::of('claimed', 'Being processed right now', $report['queue']['claimed']);
				$count = $report['queue']['queued'] + $report['queue']['claimed'];
			}

			if ($id === 'error') {
				foreach ($report['by_code'] as $code => $n) {
					if ($policy->blockOf((string) $code) !== 'error') {
						continue;
					}
					$entry = $policy->entry((string) $code);
					// A label, not the sentence: the rows sit in a column of counts,
					// and the sentence explains the code. It rides along as `title`
					// so a pointer can still ask.
					$rows[] = \OCA\OcrFlow\Report\CategoryRow::of(
						(string) $code,
						$entry['short'] ?? (string) $code,
						$n,
						$entry['text'] ?? '',
					);
				}
				// Errors is every file we tried to read and could not — including
				// the two kinds that never entered the pipeline, because they are
				// errors of the same kind: the file cannot be read, and the user
				// is the one who can fix it. Keeping them in a footnote below a
				// total made them look like a rounding note.
				if ($report['empty'] > 0) {
					$rows[] = \OCA\OcrFlow\Report\CategoryRow::of('empty', 'Empty file', $report['empty'], 'This file has no content (0 bytes).');
				}
				if ($report['unreadable_format'] > 0) {
					$rows[] = \OCA\OcrFlow\Report\CategoryRow::of('unreadable_format', 'Format we do not read', $report['unreadable_format'], 'This file type is not one the pipeline can read.');
				}
				$count = 0;
				foreach ($rows as $row) {
					$count += (int) $row['count'];
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
		$job = $this->jobStore->getById($id);
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
	 * How many files the panel's field shows. A preference only; the run clamps
	 * it again against the admin's ceiling.
	 */
	private function batchSizePreference(): int
	{
		if ($this->config === null) {
			return self::DEFAULT_PROCESS_LIMIT;
		}
		$raw = trim((string) $this->config->getUserValue($this->uid(), Application::APP_ID, 'process_batch_size', (string) self::DEFAULT_PROCESS_LIMIT));
		return ctype_digit($raw) && (int) $raw > 0 ? min((int) $raw, $this->budget->perClickCap()) : self::DEFAULT_PROCESS_LIMIT;
	}

	/**
	 * Queue this user's own unprocessed files — the button under
	 * "Not processed yet".
	 *
	 * The number is tunable in the panel and clamped here as well: a client is
	 * not trusted to respect its own field, and the daily counter is the only
	 * thing standing between a trial budget and a stuck finger.
	 */
	#[NoAdminRequired]
	public function process(): DataResponse
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('no session');
		}
		if (!$this->request->passesCSRFCheck()) {
			return new DataResponse(['message' => 'CSRF check failed'], Http::STATUS_PRECONDITION_FAILED);
		}
		if (!$this->access->canProcess($user)) {
			throw new OCSForbiddenException('OCR is not enabled for your account');
		}
		if (!$this->engine->isConfigured()) {
			return new DataResponse([
				'message' => 'No OCR engine is configured',
				'engine' => EnginePolicy::NONE,
			], Http::STATUS_CONFLICT);
		}

		// The budget owns the arithmetic: what one click may take is its
		// answer, clamped to what the day still allows. The old code compared
		// a counter here and queued past the cap; the grant cannot.
		$cap = $this->budget->perClickCap();
		$raw = $this->request->getParam('limit');
		$limit = $raw === null ? min(self::DEFAULT_PROCESS_LIMIT, $cap) : (int) $raw;
		$limit = max(1, min($limit, $cap));

		$uid = $user->getUID();
		$grant = $this->budget->reserve($uid, $limit);
		if ($grant['granted'] === 0) {
			return new DataResponse([
				'message' => sprintf('Daily limit reached: %d files.', $grant['cap']),
			], Http::STATUS_TOO_MANY_REQUESTS);
		}

		$result = $this->enqueue->enqueueNeverProcessed($uid, $grant['granted'], $this->engine->engineForJob());
		// What was granted but never queued is not spending: give it back,
		// so the day's number counts files that actually went.
		$this->budget->refund($uid, $grant['granted'] - $result['queued']);

		return new DataResponse([
			'queued' => $result['queued'],
			'refused' => $result['refused'],
			'skipped_young' => $result['young'],
			'remaining' => $result['remaining'],
			'limit' => $grant['granted'],
			'daily_used' => $grant['used'],
			'daily_cap' => $grant['cap'],
		]);
	}

	/**
	 * Remember how many files the button should take. A preference, not a
	 * permission: the clamp is applied again on every run.
	 */
	#[NoAdminRequired]
	public function batchSize(): DataResponse
	{
		if ($this->userSession->getUser() === null) {
			throw new OCSForbiddenException('no session');
		}
		if (!$this->request->passesCSRFCheck()) {
			return new DataResponse(['message' => 'CSRF check failed'], Http::STATUS_PRECONDITION_FAILED);
		}

		$size = max(1, min((int) ($this->request->getParam('size') ?? self::DEFAULT_PROCESS_LIMIT), $this->budget->perClickCap()));
		$this->config?->setUserValue($this->uid(), Application::APP_ID, 'process_batch_size', (string) $size);

		return new DataResponse(['size' => $size]);
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
		$job = $this->jobStore->findByPath($path);
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

	/**
	 * "by google 3.16.0" / "by google, version not recorded" / "by an engine we
	 * did not record" — one place, so the read rows and the no-text rows cannot
	 * drift apart in wording.
	 */
	private function engineLabel(?string $engine, ?string $version): string
	{
		if ($version === null) {
			return OutcomeCatalog::reader($engine) . ', version not recorded';
		}

		return OutcomeCatalog::reader($engine) . ' ' . $version;
	}

	/**
	 * Who read the file, with no claim about the version — the no-text rows say
	 * this and stop there, because the version is not what they answer.
	 */
	private function readerLabel(?string $engine): string
	{
		if ($engine === null) {
			return 'Engine not recorded';
		}

		return $this->engineDisplayName($engine);
	}

	/**
	 * The engine's own spelling. They are proper nouns and the panel prints them
	 * in a column next to counts, where a lowercase `google` reads like a code.
	 */
	private function engineDisplayName(string $engine): string
	{
		return match (strtolower($engine)) {
			'google' => 'Google',
			'minimax' => 'MiniMax',
			'tesseract' => 'Tesseract',
			default => mb_strtoupper(mb_substr($engine, 0, 1)) . mb_substr($engine, 1),
		};
	}
}
