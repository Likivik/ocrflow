<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCP\IConfig;

/**
 * Spend policy: the two numbers that decide whether a run may start.
 *
 * The journal (`SpendJournal`) owns the books; this owns the rules — how big a
 * day is, and what one file is expected to cost before the engine answers.
 * The estimate is always replaced by the truth at settle; it exists so the
 * gate can say no BEFORE money leaves, not after.
 */
class SpendPolicy
{
	/** Pages one user may spend per day when the admin has said nothing. */
	public const DEFAULT_CAP_PAGES = 2000;

	/** One click may queue at most this many files, whatever the admin says. */
	public const DEFAULT_PER_CLICK = 200;

	/** Rough pages per byte for a typical document, used only as an estimate. */
	private const BYTES_PER_PAGE = 50000;

	public function __construct(
		private readonly ?IConfig $config = null,
	) {
	}

	/**
	 * Pages one user may spend per day. The new knob names its unit; the old
	 * files-based knob is the fallback for one release, converted at ten
	 * pages per file — the estimate, not a promise.
	 */
	public function capPages(): int
	{
		$raw = trim((string) ($this->config?->getAppValue(Application::APP_ID, 'spend_daily_cap_pages', '') ?? ''));
		if (ctype_digit($raw) && (int) $raw > 0) {
			return (int) $raw;
		}

		$files = trim((string) ($this->config?->getAppValue(Application::APP_ID, 'process_daily_cap', '') ?? ''));
		if (ctype_digit($files) && (int) $files > 0) {
			return (int) $files * 10;
		}

		return self::DEFAULT_CAP_PAGES;
	}

	/**
	 * What one run is expected to cost, in pages. A guess from the file's
	 * size; the settle records what the engine really read. At least one:
	 * a run that books nothing is a run that can be spent for free.
	 */
	/**
	 * What one click may queue. Hard-capped at ours: the admin may lower one
	 * click, not raise it. Queuing is free — the money is booked at claim —
	 * so this is a politeness bound, not a budget.
	 */
	public function perClickCap(): int
	{
		$raw = trim((string) ($this->config?->getAppValue(Application::APP_ID, 'process_max_files', '') ?? ''));
		$asked = ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : self::DEFAULT_PER_CLICK;

		return min($asked, self::DEFAULT_PER_CLICK);
	}

	public function estimatePages(?int $size): int
	{
		return max(1, (int) ceil((max(0, $size ?? 0)) / self::BYTES_PER_PAGE));
	}
}
