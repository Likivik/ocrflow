<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

/**
 * Which files are worth looking at.
 *
 * Deliberately a name test, not a content sniff: this runs on every upload and
 * on every file of a folder scan, so it must not open anything. Whether a file
 * actually needs OCR is decided later, by the worker, from the file's content.
 */
class Candidate
{
	/** Formats the worker can turn into a text layer. */
	private const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'webp'];

	/** Names we never touch, whatever their extension. */
	private const IGNORED_PREFIX = '.';

	public function isProcessable(string $name): bool
	{
		if ($name === '' || str_starts_with($name, self::IGNORED_PREFIX)) {
			return false;
		}
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		return in_array($ext, self::EXTENSIONS, true);
	}
}
