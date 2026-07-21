<?php
/**
 * Helper functions: escaping, CSRF, flash messages, client IP, rate limiting,
 * validation, avatars and human-friendly timestamps.
 * Included by index.php only.
 */

declare(strict_types=1);

if (!defined('GUESTBOOK_APP')) {
    http_response_code(404);
    exit;
}

/** HTML-escape for safe output in any context. */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Return the current CSRF token, creating one for the session if needed. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input carrying the CSRF token, for embedding in forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Constant-time CSRF check. */
function csrf_valid(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && is_string($_POST['csrf'])
        && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/** Queue a one-shot flash message shown after the next redirect. */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Pop the pending flash message, if any. */
function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

/** Redirect (Post/Redirect/Get) and stop. */
function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/**
 * Best-effort client IP. nginx `real_ip_header CF-Connecting-IP` rewrites
 * REMOTE_ADDR to the true visitor behind Cloudflare, so REMOTE_ADDR is trusted.
 */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Privacy-preserving, salted hash of the client IP for rate limiting. */
function client_ip_hash(): string
{
    return hash('sha256', client_ip() . '|' . (env('APP_SECRET') ?? 'insecure-default'));
}

/**
 * Rate-limit a client. Returns null if allowed, or an error string if blocked.
 * Combines a per-session cooldown with a per-IP hourly cap.
 */
function rate_limit_check(PDO $pdo): ?string
{
    $now  = time();
    $last = $_SESSION['last_post_at'] ?? 0;
    if ($now - $last < RATE_LIMIT_SECONDS) {
        $wait = RATE_LIMIT_SECONDS - ($now - $last);
        return "Please wait {$wait}s before posting again.";
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM messages
         WHERE ip_hash = :h AND created_at >= datetime('now', '-1 hour')"
    );
    $stmt->execute([':h' => client_ip_hash()]);
    if ((int) $stmt->fetchColumn() >= RATE_LIMIT_PER_HOUR) {
        return 'You have reached the hourly posting limit. Try again later.';
    }
    return null;
}

/**
 * Validate + normalise a submitted message. Returns [author, content, error].
 * On error, author/content are empty and error is a user-facing string.
 */
function validate_submission(array $post): array
{
    $author  = trim((string) ($post['author'] ?? ''));
    $content = trim((string) ($post['message'] ?? ''));

    // Collapse Windows newlines and strip other control characters.
    $content = str_replace("\r\n", "\n", $content);
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content) ?? $content;
    $author  = preg_replace('/[\x00-\x1F\x7F]/u', '', $author) ?? $author;

    if ($content === '') {
        return ['', '', 'Message content cannot be empty.'];
    }
    if (mb_strlen($content) > MESSAGE_MAX) {
        return ['', '', 'Message is too long (max ' . MESSAGE_MAX . ' characters).'];
    }
    if (mb_strlen($author) > AUTHOR_MAX) {
        return ['', '', 'Name is too long (max ' . AUTHOR_MAX . ' characters).'];
    }
    if ($author === '') {
        $author = 'Anonymous';
    }
    // Clamp runaway blank lines.
    $content = preg_replace("/\n{4,}/", "\n\n\n", $content) ?? $content;

    return [$author, $content, null];
}

/** Deterministic avatar palette index (0-11) from a display name. */
function avatar_index(string $name): int
{
    return (int) (hexdec(substr(md5(mb_strtolower($name)), 0, 8)) % 12);
}

/** First visible character of a name, uppercased, for the avatar glyph. */
function avatar_initial(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    return mb_strtoupper(mb_substr($name, 0, 1));
}

/** Human-friendly "x ago" string. Assumes stored timestamps are UTC. */
function time_ago(string $datetime): string
{
    try {
        $ago = new DateTime($datetime, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return '';
    }
    $now  = new DateTime('now', new DateTimeZone('UTC'));
    $diff = $now->diff($ago);

    $weeks = (int) floor($diff->d / 7);
    $days  = $diff->d - $weeks * 7;

    $units = [
        'y' => [$diff->y, 'year'],
        'mo' => [$diff->m, 'month'],
        'w' => [$weeks, 'week'],
        'd' => [$days, 'day'],
        'h' => [$diff->h, 'hour'],
        'i' => [$diff->i, 'minute'],
    ];
    foreach ($units as [$value, $label]) {
        if ($value > 0) {
            return $value . ' ' . $label . ($value > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}
