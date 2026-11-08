<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use Psr\Log\LoggerInterface;

/**
 * The stage vocabulary, and the only place that decides what a reported
 * stage event is allowed to say.
 *
 * The worker reports what happened per pipeline stage; the app decides
 * whether that report is admissible. Two rules, both closed vocabularies:
 *
 * * a stage name outside the list is dropped with a warning. A stage that
 *   never ran must be visibly absent, not folded into some neighbour.
 * * a reason code outside the retry vocabulary is recorded as `unknown`.
 *   Foreign codes are never obeyed and never stored as themselves: the
 *   whole point of the table is that `GROUP BY stage, reason_code` means
 *   the same thing tomorrow.
 *
 * `cost_pages` belongs to the `recognize` stage alone — it is the unit the
 * provider bills in, and no other stage spends money.
 */
final class StageEvents
{
	/**
	 * The pipeline stages, in order. Fixed by the architecture plan; a stage
	 * that did not run is absent from the table, not invented.
	 */
	public const STAGES = [
		'intake',
		'inspect',
		'classify',
		'prepare',
		'recognize',
		'compose',
		'deliver',
	];

	/** The only stage that can spend money. */
	public const COST_STAGE = 'recognize';

	/** A job cannot have more stages than this; anything beyond is noise. */
	private const MAX_EVENTS = 200;

	public function __construct(
		private RetryPolicy $retryPolicy,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Is this a stage we know?
	 */
	public function isKnownStage(string $stage): bool
	{
		return in_array($stage, self::STAGES, true);
	}

	/**
	 * Turn the reported events into rows we are willing to store.
	 *
	 * @param mixed $raw the decoded `stage_events` payload: a list of maps
	 * @return list<array{stage: string, reason_code: ?string, duration_ms: ?int, cost_pages: ?int}>
	 */
	public function normalize(mixed $raw): array
	{
		if (!is_array($raw)) {
			return [];
		}

		$rows = [];
		foreach ($raw as $event) {
			if (!is_array($event)) {
				continue;
			}
			$row = $this->normalizeEvent($event);
			if ($row !== null) {
				$rows[] = $row;
			}
			if (count($rows) >= self::MAX_EVENTS) {
				$this->logger->warning('ocrflow: worker reported more than ' . self::MAX_EVENTS . ' stage events; the rest are ignored');
				break;
			}
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $event
	 * @return array{stage: string, reason_code: ?string, duration_ms: ?int, cost_pages: ?int}|null
	 */
	private function normalizeEvent(array $event): ?array
	{
		$stage = isset($event['stage']) && is_string($event['stage']) ? trim($event['stage']) : '';
		if ($stage === '' || !$this->isKnownStage($stage)) {
			$this->logger->warning('ocrflow: worker reported unknown stage "' . $stage . '"; the event is dropped');
			return null;
		}

		return [
			'stage' => $stage,
			'reason_code' => $this->reasonCode($event['reason_code'] ?? null, $stage),
			'duration_ms' => $this->duration($event['duration_ms'] ?? null),
			'cost_pages' => $this->costPages($event['cost_pages'] ?? null, $stage),
		];
	}

	/**
	 * NULL means "the stage finished with no notable outcome" — not "it
	 * failed silently". A code we do not know becomes `unknown`.
	 */
	private function reasonCode(mixed $code, string $stage): ?string
	{
		if ($code === null || $code === '') {
			return null;
		}
		$code = is_string($code) ? $code : (string) $code;
		if (!$this->retryPolicy->isKnown($code)) {
			$this->logger->warning('ocrflow: worker reported unknown reason_code "' . $code . '" for stage "' . $stage . '"; recording unknown');
			return RetryPolicy::CODE_UNKNOWN;
		}
		return $code;
	}

	private function duration(mixed $value): ?int
	{
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_numeric($value)) {
			$this->logger->warning('ocrflow: worker reported a non-numeric duration_ms; it is ignored');
			return null;
		}
		$ms = (int) $value;
		return $ms < 0 ? 0 : $ms;
	}

	private function costPages(mixed $value, string $stage): ?int
	{
		if ($value === null || $value === '') {
			return null;
		}
		if ($stage !== self::COST_STAGE) {
			$this->logger->warning('ocrflow: worker reported cost_pages for stage "' . $stage . '"; only ' . self::COST_STAGE . ' spends money, so it is dropped');
			return null;
		}
		if (!is_numeric($value)) {
			$this->logger->warning('ocrflow: worker reported non-numeric cost_pages; it is ignored');
			return null;
		}
		$pages = (int) $value;
		return $pages < 0 ? 0 : $pages;
	}
}
