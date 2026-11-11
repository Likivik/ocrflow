<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

// The OCP package ships IRootFolder with `extends Emitter` (OC\Hooks\Emitter)
// but does not ship the OC\* interfaces themselves — they live in Nextcloud
// server, which a unit test must not need. This empty stub satisfies the
// signature so IRootFolder can be mocked.

namespace OC\Hooks;

if (!interface_exists('OC\Hooks\Emitter', false)) {
	interface Emitter
	{
	}
}
