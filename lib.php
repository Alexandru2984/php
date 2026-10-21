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

/** XML text/attribute escaping for RSS output. */
function xml_e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
}

/** Return the current CSRF token, creating one for the session if needed. */
function csrf_token(): string
{
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || strlen($_SESSION['csrf']) !== 64) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Rotate the CSRF secret after a privilege or session transition. */
function csrf_rotate(): string
{
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
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
        && is_string($_SESSION['csrf'])
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

/** Preserve bounded form input across one validation redirect. */
function old_input_set(array $post): void
{
    $author = string_input($post, 'author');
    $message = string_input($post, 'message');
    $_SESSION['old_input'] = [
        'author' => mb_substr($author ?? '', 0, AUTHOR_MAX),
        'message' => mb_substr($message ?? '', 0, MESSAGE_MAX),
    ];
}

/** Return and forget form input preserved for the next request. */
function old_input_get(): array
{
    $input = $_SESSION['old_input'] ?? [];
    unset($_SESSION['old_input']);
    return is_array($input) ? $input : [];
}

/** Redirect (Post/Redirect/Get) and stop. */
function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

/** Send a minimal error without reflecting request data or leaking internals. */
function abort_response(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

/** Return a string input or null when missing/wrongly typed. */
function string_input(array $source, string $key): ?string
{
    return array_key_exists($key, $source) && is_string($source[$key])
        ? $source[$key]
        : null;
}

/** Parse a positive decimal integer without PHP array/string coercion. */
function positive_int_input(array $source, string $key, int $default = 1): int
{
    $value = string_input($source, $key);
    if ($value === null || preg_match('/\A[1-9][0-9]{0,8}\z/D', $value) !== 1) {
        return $default;
    }
    return (int) $value;
}

/** Parse a positive database identifier, returning null for malformed input. */
function id_input(array $source, string $key): ?int
{
    $value = string_input($source, $key);
    if ($value === null || preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1) {
        return null;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return $parsed === false ? null : $parsed;
}

/** Additional same-origin defence for state-changing browser requests. */
function same_origin_request(): bool
{
    $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite === 'cross-site') {
        return false;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        // Some older clients omit Origin. The per-session CSRF token remains required.
        return true;
    }
    if (!is_string($origin) || $origin === 'null') {
        return false;
    }

    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    if (($parts['path'] ?? '') !== '' || isset($parts['query']) || isset($parts['fragment'])) {
        return false;
    }

    $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (isset($parts['port'])) {
        $normalized .= ':' . $parts['port'];
    }
    return hash_equals(strtolower(APP_ORIGIN), $normalized);
}

/**
 * Best-effort client IP. nginx `real_ip_header CF-Connecting-IP` rewrites
 * REMOTE_ADDR to the true visitor behind Cloudflare, so REMOTE_ADDR is trusted.
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false
        ? $ip
        : '0.0.0.0';
}

/** Purpose-separated, keyed client identifier for abuse prevention. */
function client_ip_hash(string $purpose = 'posting'): string
{
    return hash_hmac('sha256', $purpose . "\0" . client_ip(), APP_SECRET);
}

/** Stable logical actor for the single local admin; deliberately unrelated to IP. */
function admin_actor_hash(): string
{
    return hash_hmac('sha256', "admin-actor\0local-admin", APP_SECRET);
}

/** Seconds remaining on a persistent limiter, or zero when the subject may try. */
function rate_limit_retry_after(
    PDO $pdo,
    string $scope,
    string $subjectHash,
    ?int $now = null
): int {
    $now ??= time();
    $stmt = $pdo->prepare(
        'SELECT blocked_until FROM rate_limits WHERE scope = :scope AND subject_hash = :subject'
    );
    $stmt->execute([':scope' => $scope, ':subject' => $subjectHash]);
    $blockedUntil = $stmt->fetchColumn();
    return $blockedUntil === false ? 0 : max(0, (int) $blockedUntil - $now);
}

/**
 * Record a failed action and return its lockout time. Lockouts grow exponentially
 * for repeated windows and are capped at 24 hours.
 */
function rate_limit_record_failure(
    PDO $pdo,
    string $scope,
    string $subjectHash,
    int $limit,
    int $windowSeconds,
    int $baseBlockSeconds,
    ?int $now = null
): int {
    if ($limit < 1 || $windowSeconds < 1 || $baseBlockSeconds < 1) {
        throw new InvalidArgumentException('Rate-limit values must be positive');
    }
    $now ??= time();

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $pdo->prepare(
            'SELECT window_started_at, attempts, penalty_level, blocked_until, updated_at
             FROM rate_limits WHERE scope = :scope AND subject_hash = :subject'
        );
        $stmt->execute([':scope' => $scope, ':subject' => $subjectHash]);
        $row = $stmt->fetch();

        if ($row === false) {
            $windowStartedAt = $now;
            $attempts = 0;
            $penaltyLevel = 0;
            $blockedUntil = 0;
        } else {
            $windowStartedAt = (int) $row['window_started_at'];
            $attempts = (int) $row['attempts'];
            $penaltyLevel = (int) $row['penalty_level'];
            $blockedUntil = (int) $row['blocked_until'];

            if ($now - (int) $row['updated_at'] > 86400) {
                $penaltyLevel = 0;
            }
            if ($now - $windowStartedAt >= $windowSeconds) {
                $windowStartedAt = $now;
                $attempts = 0;
            }
        }

        if ($blockedUntil > $now) {
            $pdo->exec('COMMIT');
            return $blockedUntil - $now;
        }

        $attempts++;
        if ($attempts >= $limit) {
            $penaltyLevel = min(8, $penaltyLevel + 1);
            $blockSeconds = min(86400, $baseBlockSeconds * (2 ** ($penaltyLevel - 1)));
            $blockedUntil = $now + $blockSeconds;
        } else {
            $blockedUntil = 0;
        }

        $upsert = $pdo->prepare(
            'INSERT INTO rate_limits
                (scope, subject_hash, window_started_at, attempts, penalty_level, blocked_until, updated_at)
             VALUES (:scope, :subject, :window, :attempts, :penalty, :blocked, :updated)
             ON CONFLICT(scope, subject_hash) DO UPDATE SET
                window_started_at = excluded.window_started_at,
                attempts = excluded.attempts,
                penalty_level = excluded.penalty_level,
                blocked_until = excluded.blocked_until,
                updated_at = excluded.updated_at'
        );
        $upsert->execute([
            ':scope' => $scope,
            ':subject' => $subjectHash,
            ':window' => $windowStartedAt,
            ':attempts' => $attempts,
            ':penalty' => $penaltyLevel,
            ':blocked' => $blockedUntil,
            ':updated' => $now,
        ]);
        $pdo->exec('COMMIT');
        return max(0, $blockedUntil - $now);
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // Preserve the original exception.
        }
        throw $e;
    }
}

/** A successful authentication clears accumulated failures for that subject. */
function rate_limit_clear(PDO $pdo, string $scope, string $subjectHash): void
{
    $stmt = $pdo->prepare(
        'DELETE FROM rate_limits WHERE scope = :scope AND subject_hash = :subject'
    );
    $stmt->execute([':scope' => $scope, ':subject' => $subjectHash]);
}

/** Record a minimal, non-secret security event for detection and audit. */
function security_event(
    PDO $pdo,
    string $eventType,
    string $outcome,
    ?string $subjectHash = null,
    ?int $relatedId = null,
    ?int $now = null
): void {
    if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/D', $eventType) !== 1
        || !in_array($outcome, ['success', 'failure', 'blocked'], true)) {
        throw new InvalidArgumentException('Invalid security event');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO security_events
            (event_type, outcome, subject_hash, related_id, created_at)
         VALUES (:event, :outcome, :subject, :related, :created)'
    );
    $stmt->execute([
        ':event' => $eventType,
        ':outcome' => $outcome,
        ':subject' => $subjectHash,
        ':related' => $relatedId,
        ':created' => $now ?? time(),
    ]);
}

/** Allowed public-report categories. Values are stored instead of free-form PII. */
function abuse_report_categories(): array
{
    return ['spam', 'harassment', 'privacy', 'other'];
}

/** Allowed lifecycle states for a message. */
function moderation_states(): array
{
    return ['pending', 'published', 'hidden', 'deleted', 'spam'];
}

/** Controlled moderation reasons; arbitrary admin notes are deliberately excluded. */
function moderation_reasons(): array
{
    return ['spam', 'harassment', 'privacy', 'policy', 'requested', 'other', 'restored'];
}

/** Read and validate a scalar enum input without coercion. */
function enum_input(array $source, string $key, array $allowed): ?string
{
    $value = string_input($source, $key);
    return $value !== null && in_array($value, $allowed, true) ? $value : null;
}

/** Append a minimal, immutable admin audit event. */
function admin_audit_event(
    PDO $pdo,
    string $eventType,
    string $outcome,
    string $actorHash,
    ?int $relatedId = null,
    ?string $previousState = null,
    ?string $newState = null,
    ?string $reasonCode = null,
    ?int $now = null
): void {
    $validStates = moderation_states();
    if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/D', $eventType) !== 1
        || !in_array($outcome, ['success', 'failure', 'blocked'], true)
        || preg_match('/\A[0-9a-f]{64}\z/D', $actorHash) !== 1
        || ($previousState !== null && !in_array($previousState, $validStates, true))
        || ($newState !== null && !in_array($newState, $validStates, true))
        || ($reasonCode !== null && !in_array($reasonCode, moderation_reasons(), true))) {
        throw new InvalidArgumentException('Invalid admin audit event');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO admin_audit_events
            (event_type, outcome, actor_hash, related_id, previous_state,
             new_state, reason_code, created_at)
         VALUES (:event, :outcome, :actor, :related, :previous, :new, :reason, :created)'
    );
    $stmt->execute([
        ':event' => $eventType,
        ':outcome' => $outcome,
        ':actor' => $actorHash,
        ':related' => $relatedId,
        ':previous' => $previousState,
        ':new' => $newState,
        ':reason' => $reasonCode,
        ':created' => $now ?? time(),
    ]);
}

/**
 * Move a message between moderation states and write its audit event atomically.
 * Returning false means the message does not exist or is already in that state.
 */
function moderate_message(
    PDO $pdo,
    int $messageId,
    string $newState,
    string $reasonCode,
    string $actorHash,
    ?int $now = null
): bool {
    if ($messageId < 1
        || !in_array($newState, moderation_states(), true)
        || !in_array($reasonCode, moderation_reasons(), true)) {
        throw new InvalidArgumentException('Invalid moderation transition');
    }
    $now ??= time();

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $lookup = $pdo->prepare('SELECT status FROM messages WHERE id = :id');
        $lookup->execute([':id' => $messageId]);
        $previousState = $lookup->fetchColumn();
        if ($previousState === false || $previousState === $newState) {
            $pdo->exec('COMMIT');
            return false;
        }
        if (!is_string($previousState) || !in_array($previousState, moderation_states(), true)) {
            throw new RuntimeException('Message has an invalid moderation state');
        }

        $update = $pdo->prepare(
            "UPDATE messages
             SET status = :new_state,
                 moderation_reason = :reason,
                 moderated_at = datetime(:now, 'unixepoch'),
                 deleted_at = CASE
                     WHEN :new_state = 'deleted' THEN datetime(:now, 'unixepoch')
                     ELSE NULL
                 END,
                 ip_hash = CASE WHEN :new_state = 'published' THEN ip_hash ELSE NULL END
             WHERE id = :id AND status = :previous_state"
        );
        $update->execute([
            ':new_state' => $newState,
            ':reason' => $reasonCode,
            ':now' => $now,
            ':id' => $messageId,
            ':previous_state' => $previousState,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Concurrent moderation transition');
        }

        $eventType = match ($newState) {
            'published' => 'message.restore',
            'hidden' => 'message.hide',
            'deleted' => 'message.delete',
            'spam' => 'message.spam',
            'pending' => 'message.pending',
        };
        admin_audit_event(
            $pdo,
            $eventType,
            'success',
            $actorHash,
            $messageId,
            $previousState,
            $newState,
            $reasonCode,
            $now
        );
        $pdo->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // Preserve the original exception.
        }
        throw $e;
    }
}

/** Create a category-only report if the referenced message is still public. */
function create_abuse_report(PDO $pdo, int $messageId, string $category, ?int $now = null): bool
{
    if ($messageId < 1 || !in_array($category, abuse_report_categories(), true)) {
        throw new InvalidArgumentException('Invalid abuse report');
    }
    $stmt = $pdo->prepare(
        "INSERT INTO abuse_reports (message_id, category, created_at)
         SELECT id, :category, :created FROM messages
         WHERE id = :message_id AND status = 'published'"
    );
    $stmt->execute([
        ':category' => $category,
        ':created' => $now ?? time(),
        ':message_id' => $messageId,
    ]);
    return $stmt->rowCount() === 1;
}

/** Resolve or dismiss a queued report and append its admin audit event atomically. */
function review_abuse_report(
    PDO $pdo,
    int $reportId,
    string $decision,
    string $actorHash,
    ?int $now = null
): bool {
    if ($reportId < 1 || !in_array($decision, ['resolved', 'dismissed'], true)) {
        throw new InvalidArgumentException('Invalid report review');
    }
    $now ??= time();

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $update = $pdo->prepare(
            "UPDATE abuse_reports
             SET status = :decision, reviewed_at = :reviewed
             WHERE id = :id AND status = 'open'"
        );
        $update->execute([
            ':decision' => $decision,
            ':reviewed' => $now,
            ':id' => $reportId,
        ]);
        if ($update->rowCount() !== 1) {
            $pdo->exec('COMMIT');
            return false;
        }
        admin_audit_event(
            $pdo,
            'report.' . $decision,
            'success',
            $actorHash,
            $reportId,
            null,
            null,
            null,
            $now
        );
        $pdo->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // Preserve the original exception.
        }
        throw $e;
    }
}

/** Bound pseudonymous limiter/event retention; intended for scheduled maintenance. */
function prune_security_records(PDO $pdo, ?int $now = null): array
{
    $now ??= time();
    $limits = $pdo->prepare('DELETE FROM rate_limits WHERE updated_at < :cutoff');
    $limits->execute([':cutoff' => $now - 7 * 86400]);
    $events = $pdo->prepare('DELETE FROM security_events WHERE created_at < :cutoff');
    $events->execute([':cutoff' => $now - 90 * 86400]);
    $reports = $pdo->prepare(
        "DELETE FROM abuse_reports
         WHERE status IN ('resolved', 'dismissed') AND reviewed_at < :cutoff"
    );
    $reports->execute([':cutoff' => $now - 90 * 86400]);
    // Posting limits only need a one-hour identifier. Keep a 24-hour margin for
    // troubleshooting, then irreversibly detach the keyed identifier from content.
    $identifiers = $pdo->prepare(
        "UPDATE messages SET ip_hash = NULL
         WHERE ip_hash IS NOT NULL AND created_at < datetime(:cutoff, 'unixepoch')"
    );
    $identifiers->execute([':cutoff' => $now - 86400]);
    return [
        'rate_limits' => $limits->rowCount(),
        'security_events' => $events->rowCount(),
        'abuse_reports' => $reports->rowCount(),
        'message_identifiers' => $identifiers->rowCount(),
    ];
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
    $rawAuthor = string_input($post, 'author');
    $rawContent = string_input($post, 'message');
    if ($rawContent === null || ($rawAuthor === null && array_key_exists('author', $post))) {
        return ['', '', 'Invalid form data. Please try again.'];
    }

    $author  = trim($rawAuthor ?? '');
    $content = trim($rawContent);

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
        'restore' => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-9.36L1 10"/>',
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
