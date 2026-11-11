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
		// The source file changed while the job ran: the worker's result
		// was produced from an older version, so writing it would clobber
		// the user's newer file. Terminal — the new version is enqueued as
		// its own job by the next scan.
		'source_changed' => false,
		// The classifier's verdicts. These are not failures: the file needs
		// no work, so there is nothing to retry and nobody to tell. The
		// classifier decides; the policy only records the decision's name.
		//   born_digital_text_based — the PDF already carries a text layer.
		//   mobilenet               — the image is a photo, not a document.
		//   stamped_good_by_us      — our own earlier OCR output is there.
		'born_digital_text_based' => false,
		'mobilenet' => false,
		'stamped_good_by_us' => false,
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
	 * The code for a classifier verdict, or null when the text is not one.
	 *
	 * The worker may report a verdict either as a code or as the free-text
	 * reason it already used ("the PDF already has a text layer"). Both must
	 * land on the same code, or the table answers "why" two different ways
	 * depending on which worker said it. A worker that reports the code
	 * outright needs no help; this is the fallback for one that does not.
	 */
	public function verdictCode(?string $reason): ?string
	{
		if ($reason === null) {
			return null;
		}
		return match ($reason) {
			'born_digital_text_based', 'mobilenet', 'stamped_good_by_us' => $reason,
			default => null,
		};
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

	/**
	 * Reason codes that are PERMANENT: retrying the SAME BYTES can never
	 * succeed, so after retention purges the job row, `Queue::enqueue()`
	 * refuses to re-queue the file while its etag is unchanged.
	 *
	 * This is a strict subset of the terminal failure codes. A code is only
	 * here when the failure is a property of the bytes themselves — the same
	 * pixels, parsed by any worker, produce the same refusal. Codes whose
	 * diagnosis is ambiguous fail open toward retrying:
	 *
	 *   empty_stream        — transport-shaped: "gone" and "transient 5xx" are
	 *                         not distinguished yet, so it cannot be trusted
	 *                         as a verdict about the bytes.
	 *   credentials_missing — deploy-shape: an operator adding the engine
	 *                         credentials makes the same bytes succeed.
	 *   app_4xx, engine_4xx — a 4xx may be about these bytes (too large) or
	 *                         about credentials/quota; the code alone does
	 *                         not say which, so it is not a verdict.
	 *   source_changed      — the file moved on: the new version gets its own
	 *                         attempt by design, and a different etag never
	 *                         matches the verdict anyway.
	 *   born_digital_text_based, mobilenet, stamped_good_by_us — classifier
	 *                         verdicts, not failures: the file needed no work.
	 *
	 * Unknown or absent codes are never permanent (see {@see CODE_UNKNOWN}).
	 *
	 * @var list<string>
	 */
	private const PERMANENT = [
		'no_text_detected',
		'parse_produced_nothing',
		'save_refused',
		'broken_pdf',
		'bad_format',
		'empty_source',
	];

	/**
	 * True when a failure with this code can never succeed for the same
	 * bytes. Anything not explicitly listed — including every unknown code —
	 * is not permanent: the block must fail open toward retrying.
	 */
	public function isPermanent(?string $code): bool
	{
		return $code !== null && in_array($code, self::PERMANENT, true);
	}

	/**
	 * The permanent set, for the drift tripwire in the tests.
	 *
	 * @return list<string>
	 */
	public function permanentCodes(): array
	{
		return self::PERMANENT;
	}
}
