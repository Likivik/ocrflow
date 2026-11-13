<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

/**
 * One definition of "this path is mine".
 *
 * Jobs, the ledger and the pending stash all store the full Nextcloud path
 * (`/<uid>/files/Work/scan.pdf`). A user-scoped reader must select rows by
 * that prefix, and a user-scoped writer must re-check it before acting —
 * the two must agree exactly, or a listing and a retry can disagree about
 * whose row it is. Both therefore call these two methods and nothing else.
 *
 * The prefix is derived from the AUTHENTICATED uid, never from a request
 * parameter: a client that can name its own scope can name someone else's.
 */
final class UserScope
{
	/** The segment between a uid and that user's file tree. */
	public const FILES = '/files/';

	/**
	 * The path prefix that is exactly one user's files: `/alice/files/`.
	 *
	 * A uid cannot contain a slash in Nextcloud, but this is the one place
	 * that decides the scope, so it does not rely on that: any separator in
	 * the string is removed. An empty result means "no scope", and `owns()`
	 * then answers false for every path — never "everything".
	 */
	public static function prefixFor(string $uid): string
	{
		$safe = str_replace(['/', '\\'], '', $uid);
		if ($safe === '') {
			return '';
		}
		return '/' . $safe . self::FILES;
	}

	/**
	 * Whether `$path` is inside the scope `$prefix`.
	 *
	 * Prefix, not substring: `/alice/files/x.pdf` is Alice's, and
	 * `/alice/files` itself, `/alice/filesx/y.pdf` and `/bob/files/alice/x`
	 * are not. The trailing slash in the prefix is what makes that true, so
	 * a prefix without it is refused rather than widened.
	 */
	public static function owns(string $path, string $prefix): bool
	{
		if ($prefix === '' || !str_ends_with($prefix, self::FILES)) {
			return false;
		}
		return str_starts_with($path, $prefix);
	}
}
