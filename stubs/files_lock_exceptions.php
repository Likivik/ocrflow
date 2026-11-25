<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Analysis-only stub, second half: the exception `LockService::getLockFromFileId()`
 * throws when a file has no lock row. Caught by `Locks::isLocked()` — and
 * "no row" is the answer "not locked", not a failure.
 */

namespace OCA\FilesLock\Exceptions;

class LockNotFoundException extends \Exception
{
}
