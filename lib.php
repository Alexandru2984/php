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

/** Versioned asset URL (cache-busting via file mtime). */
function asset(string $rel): string
{
    $file = APP_ROOT . '/' . ltrim($rel, '/');
    $v    = is_file($file) ? filemtime($file) : time();
    return '/' . ltrim($rel, '/') . '?v=' . $v;
}

/** Inline SVG icon (stroke-based, inherits currentColor). CSP-safe. */
function icon(string $name, string $class = ''): string
{
    static $paths = [
        'book'    => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'sun'     => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
        'moon'    => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'user'    => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'send'    => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'clock'   => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'trash'   => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
        'inbox'   => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'history' => '<path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><polyline points="12 7 12 12 15 15"/>',
        'check'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'alert'   => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'search'  => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    ];
    $body = $paths[$name] ?? '';
    $cls  = $class !== '' ? ' class="' . e($class) . '"' : '';
    return '<svg' . $cls . ' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" '
         . 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
         . 'stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
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
