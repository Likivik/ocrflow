<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

require '/var/www/html/lib/base.php';

if ($argc !== 2 || !ctype_digit($argv[1])) {
	fwrite(STDERR, "usage: attempt-release-probe.php <queued-job-id>\n");
	exit(2);
}

$jobs = \OC::$server->get(\OCA\OcrFlow\Storage\JobStore::class);
$id = (int) $argv[1];
$claimed = $jobs->claimById($id, 'attempt-probe');
if ($claimed === null) {
	fwrite(STDERR, "job was not claimable\n");
	exit(3);
}
$out = ['claim_attempts' => $claimed->getAttempts()];
$out['released'] = $jobs->release($id, (string) $claimed->getClaimToken(), true);
$released = $jobs->getById($id);
$out['release_attempts'] = $released?->getAttempts();
$out['status'] = $released?->getStatus();

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
