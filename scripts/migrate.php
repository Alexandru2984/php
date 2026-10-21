#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('GUESTBOOK_APP', true);
require dirname(__DIR__) . '/config.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    version    TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

$files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

$alreadyApplied = $pdo->prepare(
    'SELECT 1 FROM schema_migrations WHERE version = :version'
);
$recordApplied = $pdo->prepare(
    'INSERT INTO schema_migrations (version) VALUES (:version)'
);

foreach ($files as $file) {
    $version = basename($file);
    $alreadyApplied->execute([':version' => $version]);
    if ($alreadyApplied->fetchColumn() !== false) {
        fwrite(STDOUT, "skip  {$version}\n");
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Migration {$version} is empty or unreadable");
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $recordApplied->execute([':version' => $version]);
        $pdo->commit();
        fwrite(STDOUT, "apply {$version}\n");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException("Migration {$version} failed", 0, $e);
    }
}

fwrite(STDOUT, "Schema is current.\n");
