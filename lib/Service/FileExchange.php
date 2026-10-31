<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * The only place in the app that touches files on behalf of the worker.
 *
 * The worker holds nothing but a shared secret, so everything it can reach has
 * to be resolved here: the owner and the directory come from the job, never
 * from the request. That is what keeps a caller-supplied path out of the
 * filesystem — the bug class Nextcloud fixed in GHSA-273v-9h7x-p68v, where
 * `newFile()`/`newFolder()` could be steered outside the user's own space.
 *
 * Kept free of interfaces on purpose: it is a plain collaborator the controllers
 * can mock, so the worker-plane unit tests do not have to build a mock of
 * IRootFolder (whose interface chain drags in legacy `OC\Hooks\Emitter`).
 */
class FileExchange
{
	public function __construct(
		private IRootFolder $rootFolder,
		private IFilenameValidator $filenameValidator,
	) {
	}

	/**
	 * Open an existing file for reading.
	 *
	 * @return array{name: string, mime: string, handle: resource}
	 * @throws NotFoundException when the file is gone
	 */
	public function open(string $owner, string $relative): array
	{
		$node = $this->resolve($owner, $relative);
		if (!$node instanceof File) {
			throw new NotFoundException('not a file: ' . $relative);
		}
		$handle = $node->fopen('rb');
		if ($handle === false) {
			throw new NotFoundException('unreadable: ' . $relative);
		}
		return [
			'name' => $node->getName(),
			'mime' => $node->getMimetype() ?: 'application/octet-stream',
			'handle' => $handle,
		];
	}

	/**
	 * Overwrite an existing file (the OCR-in-place case).
	 *
	 * @param resource $body
	 * @return array{size: int, etag: string}
	 * @throws NotFoundException
	 */
	public function replace(string $owner, string $relative, $body): array
	{
		$node = $this->resolve($owner, $relative);
		if (!$node instanceof File) {
			throw new NotFoundException('not a file: ' . $relative);
		}
		$node->putContent($body);
		return ['size' => $node->getSize(), 'etag' => (string) $node->getEtag()];
	}

	/**
	 * Create a new file beside the job's file (the image → PDF case).
	 *
	 * The name is validated here and the collision rule is ours; the caller only
	 * suggests one. Returns where the bytes actually landed.
	 *
	 * @param resource $body
	 * @return array{name: string, path: string, file_id: int}
	 * @throws NotFoundException
	 */
	public function createBeside(string $owner, string $relative, string $name, $body): array
	{
		$this->filenameValidator->validateFilename($name);

		$directory = dirname($relative);
		$directory = $directory === '.' ? '' : $directory;
		$folder = $this->resolve($owner, $directory === '' ? '.' : $directory);
		if (!$folder instanceof Folder) {
			throw new NotFoundException('not a folder: ' . $directory);
		}

		$final = self::nextFreeName($name, static fn (string $candidate): bool => $folder->nodeExists($candidate));
		$file = $folder->newFile($final, $body);

		return [
			'name' => $final,
			'path' => '/' . $owner . '/files/' . ($directory === '' ? '' : $directory . '/') . $final,
			'file_id' => $file->getId(),
		];
	}

	/**
	 * `scan.pdf` then `scan (1).pdf`, `scan (2).pdf` — the naming Nextcloud's own
	 * clients use, so a second OCR never clobbers the first.
	 *
	 * Pure and callback-driven: the collision rule is the part worth testing
	 * without a filesystem.
	 */
	public static function nextFreeName(string $name, callable $exists): string
	{
		if (!$exists($name)) {
			return $name;
		}
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$stem = $extension === '' ? $name : substr($name, 0, -(strlen($extension) + 1));
		$suffix = $extension === '' ? '' : '.' . $extension;
		for ($n = 1; $n < 1000; $n++) {
			$candidate = sprintf('%s (%d)%s', $stem, $n, $suffix);
			if (!$exists($candidate)) {
				return $candidate;
			}
		}
		throw new \RuntimeException('no free name for ' . $name);
	}

	/**
	 * @throws NotFoundException
	 */
	private function resolve(string $owner, string $relative): \OCP\Files\Node
	{
		return $this->rootFolder->getUserFolder($owner)->get($relative);
	}
}
