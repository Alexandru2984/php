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

const APP_ROOT = __DIR__;

// ---------------------------------------------------------------------------
// Minimal .env loader
// ---------------------------------------------------------------------------
$__envFile = APP_ROOT . '/.env';
$__envLines = is_file($__envFile) && is_readable($__envFile)
    ? @file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : false;
if (is_array($__envLines)) {
    foreach ($__envLines as $__line) {
        $__line = trim($__line);
        if ($__line === '' || $__line[0] === '#' || !str_contains($__line, '=')) {
            continue;
        }
        [$__k, $__v] = explode('=', $__line, 2);
        $__k = trim($__k);
        if ($__k !== '' && getenv($__k) === false && !array_key_exists($__k, $_ENV)) {
            $_ENV[$__k] = trim($__v, " \t\"'");
        }
    }
}
unset($__envFile, $__envLines, $__line, $__k, $__v);

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v !== false) {
        return $v;
    }
    return array_key_exists($key, $_ENV) ? $_ENV[$key] : $default;
}

/** Stop safely when production configuration is missing or malformed. */
function configuration_error(string $reason): never
{
    error_log('Guestbook configuration error: ' . $reason);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Guestbook configuration error.\n");
        exit(78); // EX_CONFIG
    }
    http_response_code(503);
    exit('Service temporarily unavailable.');
}

// ---------------------------------------------------------------------------
// Error reporting — never leak stack traces in production.
// ---------------------------------------------------------------------------
$__appEnv = env('APP_ENV', 'production');
if (!in_array($__appEnv, ['production', 'development', 'testing'], true)) {
    configuration_error('APP_ENV must be production, development or testing');
}
$__isDev = $__appEnv === 'development';
error_reporting(E_ALL);
ini_set('display_errors', $__isDev ? '1' : '0');
ini_set('log_errors', '1');

if (!class_exists('Normalizer')) {
    configuration_error('PHP intl Normalizer support is required');
}

$__secret = env('APP_SECRET', '');
if (!is_string($__secret) || preg_match('/\A[0-9a-f]{64}\z/i', $__secret) !== 1) {
    configuration_error('APP_SECRET must be a 32-byte hexadecimal value');
}
define('APP_SECRET', strtolower($__secret));

$__adminHash = env('ADMIN_PASSWORD_HASH', '');
$__hashInfo = is_string($__adminHash) && $__adminHash !== ''
    ? password_get_info($__adminHash)
    : ['algoName' => 'unknown'];
if ($__appEnv === 'production') {
    $__hashAlgorithm = $__hashInfo['algoName'] ?? 'unknown';
    $__hashCost = (int) ($__hashInfo['options']['cost'] ?? 0);
    if (!in_array($__hashAlgorithm, ['bcrypt', 'argon2i', 'argon2id'], true)
        || ($__hashAlgorithm === 'bcrypt' && $__hashCost < 12)) {
        configuration_error('ADMIN_PASSWORD_HASH must use bcrypt cost 12+ or Argon2');
    }
}

$__origin = rtrim((string) env('APP_ORIGIN', 'https://php.micutu.com'), '/');
$__originParts = parse_url($__origin);
if (!is_array($__originParts)
    || !in_array($__originParts['scheme'] ?? '', ['http', 'https'], true)
    || empty($__originParts['host'])
    || isset($__originParts['user'])
    || isset($__originParts['pass'])
    || isset($__originParts['query'])
    || isset($__originParts['fragment'])
    || (($__originParts['path'] ?? '') !== '')) {
    configuration_error('APP_ORIGIN must be an origin without a path, query or fragment');
}
if ($__appEnv === 'production' && ($__originParts['scheme'] ?? '') !== 'https') {
    configuration_error('APP_ORIGIN must use HTTPS in production');
}
define('APP_ORIGIN', $__origin);

$__analyticsId = (string) env('ANALYTICS_WEBSITE_ID', '');
if ($__analyticsId !== ''
    && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $__analyticsId) !== 1) {
    configuration_error('ANALYTICS_WEBSITE_ID must be a UUID or empty');
}
define('ANALYTICS_WEBSITE_ID', strtolower($__analyticsId));

// The database must stay outside the document root. APP_STORAGE_DIR exists for
// isolated tests and immutable deployments; production normally uses the default.
$__storageDir = rtrim((string) env('APP_STORAGE_DIR', dirname(APP_ROOT) . '/php_micutu_app_storage'), '/');
if ($__storageDir === '' || $__storageDir[0] !== '/') {
    configuration_error('APP_STORAGE_DIR must be an absolute path');
}
$__normalizedStorage = realpath($__storageDir);
if ($__normalizedStorage === false || !is_dir($__normalizedStorage)) {
    configuration_error('APP_STORAGE_DIR must be an existing directory');
}
if ($__normalizedStorage === APP_ROOT || str_starts_with($__normalizedStorage, APP_ROOT . '/')) {
    configuration_error('APP_STORAGE_DIR must be outside APP_ROOT');
}
define('STORAGE_DIR', $__normalizedStorage);
define('DB_PATH', STORAGE_DIR . '/database.sqlite');

// ---------------------------------------------------------------------------
// Tunables
// ---------------------------------------------------------------------------
const AUTHOR_MAX          = 60;
const MESSAGE_MAX         = 2000;
const PER_PAGE            = 10;
const RATE_LIMIT_SECONDS  = 15;   // min seconds between posts from one client
const RATE_LIMIT_PER_HOUR = 12;   // max posts per client IP per hour
const RISK_REVIEW_THRESHOLD = 40; // queue for human review; never auto-delete
const ADMIN_IDLE_SECONDS  = 1800; // 30 minutes
const ADMIN_MAX_SECONDS   = 43200; // 12 hours
const MESSAGE_OWNER_COOKIE_SECONDS = 31536000; // one year on the private management path

require_once APP_ROOT . '/session-policy.php';

// ---------------------------------------------------------------------------
// Session hardening. CLI maintenance and migration jobs do not have a browser
// session and must not create files in PHP's shared session directory.
// ---------------------------------------------------------------------------
$__statelessRequest = defined('GUESTBOOK_STATELESS_REQUEST')
    && GUESTBOOK_STATELESS_REQUEST === true;
if (PHP_SAPI !== 'cli' && !$__statelessRequest) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    $__secureCookie = ($__originParts['scheme'] ?? 'https') === 'https';
    ini_set('session.cookie_secure', $__secureCookie ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $__secureCookie,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('gbsid');
    session_start();

    $__now = time();
    if (!empty($_SESSION['is_admin'])) {
        $__expired = !admin_session_is_current(
            $_SESSION,
            $__now,
            ADMIN_IDLE_SECONDS,
            ADMIN_MAX_SECONDS
        );

        if ($__expired) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Your admin session expired. Please sign in again.',
            ];
        } else {
            $_SESSION['admin_last_seen_at'] = $__now;
            $__lastRotation = (int) ($_SESSION['admin_session_rotated_at'] ?? 0);
            if ($__now - $__lastRotation >= 900) {
                session_regenerate_id(true);
                $_SESSION['admin_session_rotated_at'] = $__now;
            }
        }
    }
}

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

unset(
    $__appEnv,
    $__isDev,
    $__secret,
    $__adminHash,
    $__hashInfo,
    $__hashAlgorithm,
    $__hashCost,
    $__origin,
    $__originParts,
    $__analyticsId,
    $__secureCookie,
    $__storageDir,
    $__normalizedStorage,
    $__now,
    $__expired,
    $__lastRotation,
    $__statelessRequest
);
