<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'ocs' => [
        // --- worker plane: called by the OCR ExApp, never by a browser ---------
        ['name' => 'queue#claim', 'url' => '/api/v1/queue/next', 'verb' => 'POST'],
        // Bytes for the claimed job. The app reads the file as its owner; the
        // worker only presents the shared secret, so no account credential
        // exists for it to hold.
        ['name' => 'queue#file', 'url' => '/api/v1/queue/{id}/file', 'verb' => 'GET'],
        // The result, written back the same way. The worker never touches
        // WebDAV: it hands the app bytes, the app stores them as the owner.
        ['name' => 'queue#putFile', 'url' => '/api/v1/queue/{id}/file', 'verb' => 'PUT'],
        // A NEW file beside the job's file (image -> PDF). The worker names it,
        // never paths it: the directory comes from the job alone.
        ['name' => 'queue#resultFile', 'url' => '/api/v1/queue/{id}/result-file', 'verb' => 'POST'],
        ['name' => 'queue#result', 'url' => '/api/v1/queue/{id}/result', 'verb' => 'POST'],
        ['name' => 'queue#failure', 'url' => '/api/v1/queue/{id}/failure', 'verb' => 'POST'],

        // Worker registry: one registration at startup, then heartbeats.
        // The row id is the identity a claim points at; the heartbeat is the
        // evidence stale-claim recovery reads instead of a timer.
        ['name' => 'queue#workerRegister', 'url' => '/api/v1/worker/register', 'verb' => 'POST'],
        ['name' => 'queue#workerHeartbeat', 'url' => '/api/v1/worker/heartbeat', 'verb' => 'POST'],

        // --- user plane: the Files sidebar / admin settings -------------------
        ['name' => 'status#index', 'url' => '/api/v1/status', 'verb' => 'GET'],
        ['name' => 'status#job', 'url' => '/api/v1/jobs/{id}', 'verb' => 'GET'],
        ['name' => 'status#scan', 'url' => '/api/v1/scan', 'verb' => 'POST'],
        ['name' => 'status#file', 'url' => '/api/v1/file', 'verb' => 'GET'],

        // --- settings: instance policy (admin) and the user's own opt-out ---
        ['name' => 'settings#index', 'url' => '/api/v1/settings', 'verb' => 'GET'],
        ['name' => 'settings#update', 'url' => '/api/v1/settings', 'verb' => 'PUT'],
        ['name' => 'me#index', 'url' => '/api/v1/me', 'verb' => 'GET'],
        ['name' => 'me#update', 'url' => '/api/v1/me', 'verb' => 'PUT'],

        // --- attention: the admin's needs_fix worklist (see RetryPolicy) ---
        // Rows whose reason code has the needs_fix verdict — the operator's
        // queue. Admin only; the paths and error prose are other people's
        // documents.
        ['name' => 'attention#index', 'url' => '/api/v1/attention', 'verb' => 'GET'],
        // One implementation, two front doors: this and occ ocrflow:requeue
        // share the Attention service's retry. POST, so CSRF is checked.
        ['name' => 'attention#retry', 'url' => '/api/v1/attention/retry', 'verb' => 'POST'],
    ],
];
