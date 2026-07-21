<?php
/**
 * Application configuration, environment loading, session + database bootstrap.
 * Included by index.php only. Direct web access is refused by the guard below.
 */

declare(strict_types=1);

if (!defined('GUESTBOOK_APP')) {
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------------------
// Paths — the database lives OUTSIDE the web root so it can never be served.
// ---------------------------------------------------------------------------
const APP_ROOT    = __DIR__;
const STORAGE_DIR = __DIR__ . '/../php_micutu_app_storage';
const DB_PATH     = STORAGE_DIR . '/database.sqlite';

// ---------------------------------------------------------------------------
// Minimal .env loader
// ---------------------------------------------------------------------------
$__envFile = APP_ROOT . '/.env';
if (is_file($__envFile)) {
    foreach (file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__line) {
        $__line = trim($__line);
        if ($__line === '' || $__line[0] === '#' || !str_contains($__line, '=')) {
            continue;
        }
        [$__k, $__v] = explode('=', $__line, 2);
        $_ENV[trim($__k)] = trim($__v, " \t\"'");
    }
}
unset($__envFile, $__line, $__k, $__v);

function env(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    $v = getenv($key);
    return $v === false ? $default : $v;
}

// ---------------------------------------------------------------------------
// Error reporting — never leak stack traces in production.
// ---------------------------------------------------------------------------
$__isDev = env('APP_ENV', 'production') === 'development';
error_reporting(E_ALL);
ini_set('display_errors', $__isDev ? '1' : '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------------------
// Tunables
// ---------------------------------------------------------------------------
const AUTHOR_MAX          = 60;
const MESSAGE_MAX         = 2000;
const PER_PAGE            = 10;
const RATE_LIMIT_SECONDS  = 15;   // min seconds between posts from one client
const RATE_LIMIT_PER_HOUR = 12;   // max posts per client IP per hour

// ---------------------------------------------------------------------------
// Session hardening
// ---------------------------------------------------------------------------
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_name('gbsid');
session_start();

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
try {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
} catch (Throwable $e) {
    error_log('Guestbook DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Service temporarily unavailable.');
}

// Base schema (no-op if it already exists).
$pdo->exec("CREATE TABLE IF NOT EXISTS messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    author     TEXT NOT NULL DEFAULT 'Anonymous',
    content    TEXT NOT NULL,
    ip_hash    TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Lightweight migrations for pre-existing databases.
foreach ([
    "ALTER TABLE messages ADD COLUMN author TEXT NOT NULL DEFAULT 'Anonymous'",
    "ALTER TABLE messages ADD COLUMN ip_hash TEXT",
] as $__migration) {
    try {
        $pdo->exec($__migration);
    } catch (Throwable $e) {
        // Column already exists — ignore.
    }
}
unset($__migration);

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_created_at ON messages(created_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_ip_hash ON messages(ip_hash, created_at)");
