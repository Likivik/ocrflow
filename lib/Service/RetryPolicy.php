<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

/**
 * The one retry table.
 *
 * The worker reports what happened; this class decides what that means for the
 * job. Before this existed, the decision was prose in the README and the code:
 * a failed job was a failed job, a `RuntimeError` from one site and a
 * `ValueError` from another were indistinguishable, and only a human could tell
 * a "the scan has no text, retrying re-bills the same page" from "Vision timed
 * out, try again".
 *
 * Every code is either terminal (a retry cannot change the outcome) or
 * retryable (a retry plausibly can). `unknown` is retryable on purpose: silence
 * must not turn a transient fault into a permanent loss, and the attempt
 * ceiling stops a broken code from looping forever.
 *
 * A code absent from this table is not a policy decision — the worker may only
 * report codes from here, and the controller records anything else as
 * `unknown` with a warning, so the vocabulary stays closed.
 */
final class RetryPolicy
{
	public const CODE_UNKNOWN = 'unknown';

	/**
	 * Closed vocabulary → retryable?
	 *
	 * Terminal codes: the same inputs produce the same result, and several of
	 * them cost money per attempt (parse_produced_nothing sits after paid OCR).
	 * Retryable codes: the failure was in a dependency or in transport.
	 *
	 * @var array<string, bool>
	 */
	private const TABLE = [
		// The file has nothing to read. Retrying re-sends the same pixels.
		'no_text_detected' => false,
		// The engine returned text we could not parse — a code bug. Retrying
		// re-bills the engine and fails the same way.
		'parse_produced_nothing' => false,
		// PyMuPDF refused the write in place and the verified fallback failed.
		// Deterministic and local.
		'save_refused' => false,
		// The source is structurally damaged; the inspect stage refused it
		// before any engine call.
		'broken_pdf' => false,
		// Not a document we can convert (unsupported extension/format).
		'bad_format' => false,
		// The source file is zero bytes on disk. Nothing to fetch or read —
		// this is a data problem and needs a human, not a retry.
		'empty_source' => false,
		// Transport returned an empty stream. Terminal until the transport
		// distinguishes "gone" from "transient" (410 vs 5xx).
		'empty_stream' => false,
		// The worker has no engine credentials; a deploy-shape failure.
		'credentials_missing' => false,
		// Client-side refusals: asking again with the same arguments fails the
		// same way.
		'app_4xx' => false,
		'engine_4xx' => false,
		// Dependency or transport faults: the inputs did not change.
		'app_5xx' => true,
		'engine_5xx' => true,
		'engine_timeout' => true,
		'storage_error' => true,
		// Bounded retry: an unrecognised failure may well be transient, and a
		// permanent freeze is the worse error.
		self::CODE_UNKNOWN => true,
	];

	public function isKnown(?string $code): bool
	{
		return $code !== null && array_key_exists($code, self::TABLE);
	}

	public function isRetryable(?string $code): bool
	{
		if ($code === null) {
			return false;
		}
		return self::TABLE[$code] ?? self::TABLE[self::CODE_UNKNOWN];
	}

	/**
	 * The app's decision, plus whether the worker's hint agreed with it.
	 *
	 * The hint is recorded and logged, never obeyed: policy has one owner, and
	 * two owners is how behaviour drifts between environments.
	 *
	 * @return array{retryable: bool, agrees: ?bool}
	 */
	public function decide(?string $code, ?bool $hint): array
	{
		$retryable = $this->isRetryable($code);
		return [
			'retryable' => $retryable,
			'agrees' => $hint === null ? null : $hint === $retryable,
		];
	}

	/**
	 * The vocabulary, for the migration check and the admin surface.
	 *
	 * @return array<string, bool>
	 */
	public function vocabulary(): array
	{
		return self::TABLE;
	}
}
