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
        ['name' => 'queue#result', 'url' => '/api/v1/queue/{id}/result', 'verb' => 'POST'],
        ['name' => 'queue#failure', 'url' => '/api/v1/queue/{id}/failure', 'verb' => 'POST'],

        // --- user plane: the Files sidebar / admin settings -------------------
        ['name' => 'status#index', 'url' => '/api/v1/status', 'verb' => 'GET'],
        ['name' => 'status#job', 'url' => '/api/v1/jobs/{id}', 'verb' => 'GET'],
        ['name' => 'status#scan', 'url' => '/api/v1/scan', 'verb' => 'POST'],
        ['name' => 'status#file', 'url' => '/api/v1/file', 'verb' => 'GET'],
    ],
];
