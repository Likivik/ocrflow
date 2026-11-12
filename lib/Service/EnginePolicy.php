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

	/**
	 * The engine bar: which (engine, engine_version) pairs count as "already
	 * good, nothing to redo". A JSON object in app config,
	 * `ocr_accepted_engines`, engine → minimum acceptable version.
	 *
	 * An engine absent from the map is NEVER acceptable (an unmeasured
	 * engine has no bar to clear), and a stamp with no version is below any
	 * bar — "unknown" can never be proven good. The comparison is numeric,
	 * never lexicographic: 3.15.0 is above 3.9.0.
	 */
	public const ACCEPTED_ENGINES_KEY = 'ocr_accepted_engines';

	/** @var string the default bar, as JSON so the shape is the stored shape */
	private const DEFAULT_BAR = '{"google": "3.15.0"}';

	/**
	 * The bar as engine → minimum version. Garbage, an empty object, or an
	 * unset value all fall back to the default rather than to "everything
	 * passes" — a mis-edited config must not silently accept every stamp.
	 *
	 * @return array<string, string>
	 */
	public function acceptedEngines(): array {
		$raw = trim((string) $this->config->getAppValue(
			Application::APP_ID, self::ACCEPTED_ENGINES_KEY, self::DEFAULT_BAR,
		));
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) || $decoded === []) {
			return ['google' => '3.15.0'];
		}
		$out = [];
		foreach ($decoded as $engine => $version) {
			if (is_string($engine) && $engine !== '' && is_string($version) && trim($version) !== '') {
				$out[strtolower(trim($engine))] = trim($version);
			}
		}
		return $out === [] ? ['google' => '3.15.0'] : $out;
	}

	/**
	 * Is this (engine, version) at or above the bar? See
	 * {@see acceptedEngines()} for the rules: absent engine never, missing
	 * version never, numeric comparison always.
	 */
	public function meetsBar(?string $engine, ?string $version): bool {
		if ($engine === null || trim($engine) === '' || $version === null || trim($version) === '') {
			return false;
		}
		$bar = $this->acceptedEngines();
		$key = strtolower(trim($engine));
		if (!array_key_exists($key, $bar)) {
			return false;
		}
		return version_compare(trim($version), $bar[$key], '>=');
	}

	/**
	 * The verdict for one of our stamps: `nothing_to_do` when the engine that
	 * wrote it is at or above the bar, `redo` when it is below (a redo
	 * candidate the owner picks from — never an automatic re-OCR).
	 */
	public function stampVerdict(?string $engine, ?string $engineVersion): string {
		return $this->meetsBar($engine, $engineVersion) ? 'nothing_to_do' : 'redo';
	}
}
