#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('GUESTBOOK_APP', true);
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib.php';

$deleted = prune_security_records($pdo);
$pdo->exec('PRAGMA optimize');

fwrite(
    STDOUT,
    sprintf(
        "Maintenance complete: %d rate limits, %d security events, %d closed abuse reports, %d message identifiers, and %d expired owner capabilities pruned.\n",
        $deleted['rate_limits'],
        $deleted['security_events'],
        $deleted['abuse_reports'],
        $deleted['message_identifiers'],
        $deleted['owner_capabilities']
    )
);
