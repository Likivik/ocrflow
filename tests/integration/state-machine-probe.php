<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

require '/var/www/html/lib/base.php';

if ($argc !== 3) {
	fwrite(STDERR, "usage: state-machine-probe.php <user> <file>\n");
	exit(2);
}

$user = (string) $argv[1];
$file = (string) $argv[2];
$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
$node = $root->getUserFolder($user)->get($file);
$pending = \OC::$server->get(\OCA\OcrFlow\Storage\PendingFileStore::class);
$jobs = \OC::$server->get(\OCA\OcrFlow\Storage\JobStore::class);
$enqueue = \OC::$server->get(\OCA\OcrFlow\Storage\EnqueueService::class);

$fileId = $node->getId();
$path = $node->getPath();
$etag = $node->getEtag();
$size = $node->getSize();
$out = [];

// Remove whatever the upload event already queued, so every queued row below
// is evidence of a drain, not of the listener.
$jobs->deleteQueuedForFile($fileId);
$jobs->deleteTerminalForFile($fileId);
$existing = $pending->get($fileId);
if ($existing !== null) {
	$pending->deleteIfRevision($fileId, (int) $existing['revision']);
}

// Two observations: revision grows, and a delete for the stale revision
// must not remove the newer one.
$first = $pending->observe($fileId, $path, $etag, $size);
$second = $pending->observe($fileId, $path, $etag, $size);
$out['revision_grew'] = $second > $first;
$out['stale_cas_refused'] = !$pending->deleteIfRevision($fileId, $first);
$out['current_cas_deleted'] = $pending->deleteIfRevision($fileId, $second);
$out['pending_cleared'] = $pending->get($fileId) === null;

// A deferred observation becomes queued work when drained.
$pending->observe($fileId, $path, $etag, $size);
$drained = $enqueue->drainPendingBatch(500, 'local');
$out['drained'] = $drained['drained'] >= 1;
$out['pending_consumed'] = $pending->get($fileId) === null;
$job = $jobs->findByPath($path);
$out['queued'] = $job !== null && $job->getStatus() === 'queued';
$out['superseded_before_claim'] = $job !== null && $jobs->supersedeQueued($fileId) === 1;

// Once claimed, supersession must leave the live claim alone.
$pending->observe($fileId, $path, $etag, $size);
$enqueue->drainPendingBatch(500, 'local');
$job = $jobs->findByPath($path);
$out['requeued'] = $job !== null && $job->getStatus() === 'queued';
$claimed = $job === null ? null : $jobs->claimById($job->getId(), 'state-probe');
$out['claimed'] = $claimed !== null;
$out['claim_revision_one'] = $claimed !== null && $claimed->getClaimRevision() === 1;
$out['supersede_refused_while_claimed'] = $jobs->supersedeQueued($fileId) === 0;
$out['claim_survived'] = $claimed !== null
	&& $jobs->getById($claimed->getId())?->getStatus() === 'claimed';
$out['renewed'] = $claimed !== null && $jobs->renewClaim($claimed->getId(), (string) $claimed->getClaimToken());
$out['claim_revision_two'] = $claimed !== null
	&& $jobs->getById($claimed->getId())?->getClaimRevision() === 2;
if ($claimed !== null) {
	$jobs->release($claimed->getId(), (string) $claimed->getClaimToken(), true);
	$jobs->supersedeQueued($fileId);
}
$out['leftover_pending'] = $pending->get($fileId) !== null;

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
