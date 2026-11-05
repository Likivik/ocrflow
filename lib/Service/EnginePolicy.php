<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCA\OcrFlow\AppInfo\Application;
use OCP\IConfig;

/**
 * Which OCR engine this instance uses, and whether it has been chosen at all.
 *
 * The engine is **instance policy**, not a worker property: it decides whether
 * page images of users' documents leave the server and what they cost. It used
 * to live only in the worker's environment, defaulting to `google` — so an
 * instance nobody configured silently uploaded scans to a third party. The
 * default is now `none`, and nothing is queued until an administrator chooses.
 *
 * Set it with:
 *
 *   occ ocrflow:engine tesseract
 *
 * An unrecognised value is treated as unconfigured rather than passed through:
 * a typo must not become a job that fails inside the worker after the file has
 * already been claimed.
 */
final class EnginePolicy
{
	/** Engines the worker implements. `none` is the absence of a choice. */
	public const ENGINES = ['tesseract', 'google', 'minimax'];

	public const NONE = 'none';

	public function __construct(private IConfig $config) {
	}

	/** The raw configured value, which may be empty or wrong. */
	public function raw(): string {
		return trim((string) $this->config->getAppValue(Application::APP_ID, 'engine', ''));
	}

	/** The effective engine: a valid configured one, or `none`. */
	public function current(): string {
		$raw = strtolower($this->raw());
		return in_array($raw, self::ENGINES, true) ? $raw : self::NONE;
	}

	/**
	 * False when no engine has been chosen, or the configured one is not real.
	 * Callers refuse to queue work in that state: a queue full of jobs nobody can
	 * run is worse than a clear "not configured".
	 */
	public function isConfigured(): bool {
		return $this->current() !== self::NONE;
	}

	/** The engine to stamp on a queued job, or null when unconfigured. */
	public function engineForJob(): ?string {
		return $this->isConfigured() ? $this->current() : null;
	}

	public function set(string $engine): void {
		$this->config->setAppValue(Application::APP_ID, 'engine', strtolower(trim($engine)));
	}
}
