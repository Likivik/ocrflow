<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Analysis-only stub for the one symbol this app borrows from `files_lock`.
 *
 * `OCA\FilesLock\Service\LockService` is how a claim asks "is anyone in this
 * file right now?": files_lock exposes no public query, and its own app code
 * resolves this class through `OCP\Server::get()` exactly as we do. The class
 * is not part of the OCP stubs, so the analyser would call it undefined; this
 * file teaches it the signature and nothing else. It is never autoloaded by the
 * app — `Locks::available()` keeps the runtime honest when the app is absent.
 */

namespace OCA\FilesLock\Service;

class LockService
{
	public function getLockFromFileId(int $fileId): ?\OCP\Files\Lock\ILock
	{
		return null;
	}
}
