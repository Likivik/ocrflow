<?php

/**
 * files_lock probe: the two facts this app's claim gate depends on.
 *
 *   php files-lock-probe.php <user> <path-relative-to-the-user-folder>
 *
 * 1. `LockService::getLockFromFileId()` answers "is this file locked?" for a
 *    lock taken by anyone — that is the read the claim gate makes.
 * 2. Taking a lock **rewrites the file's etag** (`propagateEtag()` sets
 *    `etag = uniqid()`). The app therefore records `run_etag` after locking and
 *    compares content hashes when later lock operations move the etag again.
 * 3. OCRFlow lock owners are claim-specific: a second claim is refused, and a
 *    stale claim's release cannot unlock the newer claim.
 *
 * @copyright Copyright (c) 2026, Likivik
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

require '/var/www/html/lib/base.php';

if ($argc < 3) {
	fwrite(STDERR, "usage: files-lock-probe.php <user> <path>\n");
	exit(2);
}

[, $user, $relPath] = $argv;

$out = ['user' => $user, 'path' => $relPath];

$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
$locks = \OC::$server->get(\OCA\FilesLock\Service\LockService::class);
$manager = \OC::$server->get(\OCP\Files\Lock\ILockManager::class);

$node = $root->getUserFolder($user)->get($relPath);
$fileId = (int)$node->getId();
$ctx = new \OCP\Files\Lock\LockContext($node, \OCP\Files\Lock\ILock::TYPE_APP, 'ocrflow');

$out['available'] = $manager->isLockProviderAvailable();
$out['etag_before'] = (string)$node->getEtag();

$query = static function () use ($locks, $fileId): bool {
	try {
		return $locks->getLockFromFileId($fileId) !== null;
	} catch (\OCA\FilesLock\Exceptions\LockNotFoundException $e) {
		return false;
	}
};

$out['query_before'] = $query();

$manager->lock($ctx);
$out['locked'] = 'yes';
$out['query_while_locked'] = $query();

// The node object caches its etag; read a fresh one the way the ladder does.
$fresh = $root->getUserFolder($user)->get($relPath);
$out['etag_after'] = (string)$fresh->getEtag();
$out['etag_changed'] = $out['etag_after'] !== $out['etag_before'];

$manager->unlock($ctx);
$out['query_after_unlock'] = $query();

// Claim tokens are capabilities, so the app hashes them into distinct lock
// owners. A stale claim's late cleanup must not unlock the claim that replaced
// it, and two OCR runs must not share one re-entrant app-owned lock.
$appLocks = \OC::$server->get(\OCA\OcrFlow\Service\Locks::class);
$appLocks->lockForRun($node, 'old-claim');
$out['second_claim_refused'] = !$appLocks->lockForRun($node, 'new-claim');
$appLocks->releaseForRun($node, 'old-claim');
$out['new_claim_after_release'] = $appLocks->lockForRun($node, 'new-claim');
$appLocks->releaseForRun($node, 'old-claim'); // stale settlement, after requeue
$out['stale_release_kept_new_lock'] = !$appLocks->lockForRun($node, 'third-claim');
$appLocks->releaseForRun($node, 'new-claim');
$out['third_claim_after_new_release'] = $appLocks->lockForRun($node, 'third-claim');
$appLocks->releaseForRun($node, 'third-claim');

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
