<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCP\IConfig;

/**
 * How many OCR jobs the worker may run at once.
 *
 * The number is instance policy, not a worker property: it decides how much of
 * the machine a batch may consume, and it is the only knob an operator needs
 * when a backlog starts growing. It used to be an environment variable read
 * once at start-up, which meant a rebuild to change it — a rebuild that drops
 * the jobs in flight and redoes them.
 *
 * The worker reads this value between jobs and resizes its slot pool; a slot
 * that is retired finishes the file it holds and exits, so a resize never
 * costs a redo. Nothing here clamps the number: the value is the operator's
 * instruction, and what the host can hold is their business, not ours. The
 * worker logs the setting next to the host's cores and free memory so a wrong
 * number is visible in one line.
 *
 * Set it with:
 *
 *   occ ocrflow:slots 3
 *
 * or in the admin settings page, which writes the same app value.
 */
final class SlotsPolicy
{
	public const KEY = 'worker_slots';

	/** Two is the default: one slot leaves the queue serial on a 4-core host. */
	public const DEFAULT_SLOTS = 2;

	public function __construct(private IConfig $config) {
	}

	/**
	 * The configured value, always usable.
	 *
	 * A missing or nonsense value falls back to the default rather than to
	 * zero: a worker told to run zero slots would sit idle while the queue
	 * grew, which looks exactly like a broken deployment.
	 */
	public function current(): int {
		$raw = trim((string) $this->config->getAppValue(Application::APP_ID, self::KEY, ''));
		if ($raw === '' || !ctype_digit($raw)) {
			return self::DEFAULT_SLOTS;
		}
		$value = (int) $raw;
		return $value >= 1 ? $value : self::DEFAULT_SLOTS;
	}

	/** Whether the operator has set it, as opposed to us defaulting. */
	public function isSet(): bool {
		return trim((string) $this->config->getAppValue(Application::APP_ID, self::KEY, '')) !== '';
	}

	/**
	 * Store a value. Returns false for anything that is not a positive integer:
	 * a typo must not become a worker that cannot start.
	 */
	public function set(int $slots): bool {
		if ($slots < 1) {
			return false;
		}
		$this->config->setAppValue(Application::APP_ID, self::KEY, (string) $slots);
		return true;
	}
}
