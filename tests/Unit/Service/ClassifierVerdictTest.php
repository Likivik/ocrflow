<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCA\OcrFlow\Controller\QueueController;
use OCA\OcrFlow\Db\Job;
use OCA\OcrFlow\Db\Queue;
use OCA\OcrFlow\Db\WorkerRegistry;
use OCA\OcrFlow\Service\FileExchange;
use OCA\OcrFlow\Service\RetryPolicy;
use OCA\OcrFlow\Service\StageEvents;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The classifier's three verdicts are decisions, not failures.
 *
 * Until now the worker reported them as free text ("the image is a photo")
 * and the app had no code for them, so a skip and a failure were the same
 * shape in the table. They are not the same shape: a failure wants a retry,
 * a verdict wants silence.
 */
final class ClassifierVerdictTest extends TestCase
{
	private const VERDICTS = [
		'born_digital_text_based',
		'mobilenet',
		'stamped_good_by_us',
		// The new spellings of the same verdicts (0.1.5 vocabulary).
		'skip_text_native',
		'skip_classifier_says_photo',
		'skip_ocred_by_ocrflow',
		'skip_ocred_by_suspected_foreign',
	];

	public function testTheThreeClassifierVerdictsAreKnown(): void
	{
		$policy = new RetryPolicy();
		foreach (self::VERDICTS as $code) {
			$this->assertTrue($policy->isKnown($code), $code . ' must be in the vocabulary');
		}
	}

	public function testTheThreeClassifierVerdictsAreTerminal(): void
	{
		$policy = new RetryPolicy();
		foreach (self::VERDICTS as $code) {
			$this->assertFalse(
				$policy->isRetryable($code),
				$code . ' is "no work needed": retrying cannot change the outcome',
			);
		}
	}

	/**
	 * A verdict must survive the recorder unchanged. Downgraded to `unknown`
	 * it would become bounded-retryable, and a retry of "this PDF already has
	 * a text layer" costs money and changes nothing.
	 */
	public function testAVerdictIsRecordedAsItselfAndNotAsUnknown(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$events = new StageEvents(new RetryPolicy(), $logger);
		$rows = $events->normalize([
			['stage' => 'classify', 'reason_code' => 'born_digital_text_based', 'duration_ms' => 12],
			['stage' => 'classify', 'reason_code' => 'mobilenet', 'duration_ms' => 34],
			['stage' => 'intake', 'reason_code' => 'stamped_good_by_us', 'duration_ms' => 5],
		]);

		$this->assertCount(3, $rows);
		$this->assertSame('born_digital_text_based', $rows[0]['reason_code']);
		$this->assertSame('mobilenet', $rows[1]['reason_code']);
		$this->assertSame('stamped_good_by_us', $rows[2]['reason_code']);
	}

	public function testAnUnknownCodeIsStillBoundedRetryable(): void
	{
		// The verdict codes join the vocabulary; the rule for everything else
		// is untouched.
		$policy = new RetryPolicy();
		$this->assertFalse($policy->isKnown('some_new_engine_fault'));
		$this->assertTrue($policy->isRetryable('some_new_engine_fault'));
	}

	/**
	 * A verdict reported as prose only still lands on a code.
	 *
	 * The worker may report either the code or the free-text reason it
	 * already used. Both must produce the same row, or the table answers
	 * "why" two different ways depending on which worker said it.
	 */
	public function testAVerdictInProseMapsToItsCode(): void
	{
		$policy = new RetryPolicy();
		// New names pass through as themselves.
		foreach (['skip_text_native', 'skip_classifier_says_photo', 'skip_ocred_by_ocrflow', 'skip_ocred_by_suspected_foreign'] as $code) {
			$this->assertSame($code, $policy->verdictCode($code));
		}
		// Old prose-era names land on the same new codes (read old, write new).
		$this->assertSame('skip_text_native', $policy->verdictCode('born_digital_text_based'));
		$this->assertSame('skip_classifier_says_photo', $policy->verdictCode('mobilenet'));
		$this->assertSame('skip_ocred_by_ocrflow', $policy->verdictCode('stamped_good_by_us'));
	}

	public function testTextThatIsNotAVerdictMapsToNoCode(): void
	{
		$policy = new RetryPolicy();
		$this->assertNull($policy->verdictCode('unsupported_ext'));
		$this->assertNull($policy->verdictCode('foreign_rewrite'));
		$this->assertNull($policy->verdictCode(null));
	}

	/**
	 * The end of the road: a skipped job whose worker said only "born
	 * digital" must still reach the table with a code on the row.
	 */
	public function testASkippedJobReportedInProseStillGetsACode(): void
	{
		$queue = $this->createMock(Queue::class);
		$captured = null;
		$queue->method('getById')->willReturnCallback(static function (): Job {
			$job = new Job();
			$job->setId(7);
			$job->setPath('/u/files/a.pdf');
			$job->setStatus(Queue::STATUS_CLAIMED);
			$job->setClaimToken('tok-7');
			return $job;
		});
		$queue->method('finish')->willReturnCallback(
			function (int $id, array $result) use (&$captured): bool {
				$captured = $result;
				return true;
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match ($name) {
				Application::WORKER_SECRET_HEADER => 'the-secret',
				Application::CLAIM_HEADER => 'tok-7',
				default => '',
			}
		);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'status' => 'skipped',
				'reason' => 'born_digital_text_based',
				default => $default,
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with(Application::APP_ID, 'worker_secret', '')
			->willReturn('the-secret');

		$controller = new QueueController(
			Application::APP_ID,
			$request,
			$queue,
			$config,
			$this->createMock(LoggerInterface::class),
			$this->createMock(FileExchange::class),
			new RetryPolicy(),
			$this->createMock(WorkerRegistry::class),
		);
		$controller->result(7);

		$this->assertIsArray($captured);
		$this->assertSame('skip_text_native', $captured['reason_code']);
		$this->assertFalse($captured['retryable'], 'a verdict is terminal');
	}
}
