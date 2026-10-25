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

/** Collapse plain text into a bounded search/social description. */
function seo_excerpt(string $text, int $maximum = 160): string
{
    if ($maximum < 20 || $maximum > 500) {
        throw new InvalidArgumentException('Invalid excerpt length');
    }
    $collapsed = preg_replace('/\s+/u', ' ', trim($text));
    if (!is_string($collapsed)) {
        return '';
    }
    if (mb_strlen($collapsed, 'UTF-8') <= $maximum) {
        return $collapsed;
    }
    return rtrim(mb_substr($collapsed, 0, $maximum - 1, 'UTF-8')) . '…';
}

/** Convert a trusted SQLite UTC timestamp into an RFC 3339 timestamp. */
function utc_iso8601(string $datetime): string
{
    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $datetime,
        new DateTimeZone('UTC')
    );
    if (!$parsed instanceof DateTimeImmutable) {
        throw new InvalidArgumentException('Invalid database timestamp');
    }
    return $parsed->format('Y-m-d\TH:i:s\Z');
}

/** Return the single public path for a published message. */
function public_message_path(int $id, bool $fragment = false): string
{
    if ($id < 1) {
        throw new InvalidArgumentException('Invalid message identifier');
    }
    $path = '/messages/' . $id;
    return $fragment ? $path . '#m' . $id : $path;
}

/** Return the private management path for one newly submitted message. */
function message_manage_path(int $id): string
{
    if ($id < 1) {
        throw new InvalidArgumentException('Invalid message identifier');
    }
    return '/messages/' . $id . '/manage';
}

/** Return the canonical path for one UTC calendar month of public messages. */
function public_archive_path(int $year, int $month, int $page = 1): string
{
    if ($year < 1000 || $year > 2999 || $month < 1 || $month > 12 || $page < 1) {
        throw new InvalidArgumentException('Invalid archive path');
    }
    $path = sprintf('/archive/%04d/%02d', $year, $month);
    return $page === 1 ? $path : $path . '?page=' . $page;
}

/** Return the trusted metadata for public, sessionless information pages. */
function public_info_pages(): array
{
    return [
        'about' => [
            'path' => '/about',
            'title' => 'About · Micuțu Guestbook',
            'heading' => 'About this guestbook',
            'description' => 'How Micuțu Guestbook works, what it publishes and the open formats it supports.',
            'schema_type' => 'AboutPage',
        ],
        'privacy' => [
            'path' => '/privacy',
            'title' => 'Privacy · Micuțu Guestbook',
            'heading' => 'Privacy and data use',
            'description' => 'What Micuțu Guestbook publishes, which technical records it keeps and for how long.',
            'schema_type' => 'WebPage',
        ],
        'moderation' => [
            'path' => '/moderation',
            'title' => 'Moderation · Micuțu Guestbook',
            'heading' => 'Moderation policy',
            'description' => 'How public reports, automated risk signals and human moderation work on Micuțu Guestbook.',
            'schema_type' => 'WebPage',
        ],
    ];
}

/** Return a bounded newest-first list of UTC months containing published records. */
function public_archive_months(PDO $pdo, int $limit = 120): array
{
    if ($limit < 1 || $limit > 240) {
        throw new InvalidArgumentException('Invalid archive month limit');
    }
    $stmt = $pdo->prepare(
        "SELECT archive_month, COUNT(*) AS message_count, MAX(updated_at) AS updated_at
         FROM (
             SELECT substr(created_at, 1, 7) AS archive_month,
                    MAX(
                        created_at,
                        COALESCE(moderated_at, created_at),
                        COALESCE(feature_changed_at, created_at)
                    ) AS updated_at
             FROM messages
             WHERE status = 'published'
               AND (created_at GLOB '[12][0-9][0-9][0-9]-0[1-9]-*'
                    OR created_at GLOB '[12][0-9][0-9][0-9]-1[0-2]-*')
         )
         GROUP BY archive_month
         ORDER BY archive_month DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Build a bounded sitemap from published records only. */
function public_sitemap_xml(PDO $pdo): string
{
    $infoPages = public_info_pages();
    $archiveMonths = public_archive_months($pdo);
    $messageLimit = 49999 - count($infoPages) - count($archiveMonths);
    $stmt = $pdo->prepare(
        "SELECT id,
                MAX(
                    created_at,
                    COALESCE(moderated_at, created_at),
                    COALESCE(feature_changed_at, created_at)
                ) AS updated_at
         FROM messages
         WHERE status = 'published'
         ORDER BY id ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $messageLimit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $latest = $pdo->query(
        "SELECT MAX(updated_at)
         FROM (
             SELECT MAX(
                        created_at,
                        COALESCE(moderated_at, created_at),
                        COALESCE(feature_changed_at, created_at)
                    ) AS updated_at
             FROM messages
             WHERE status = 'published'
         )"
    )->fetchColumn();
    $latest = is_string($latest) && $latest !== '' ? $latest : null;

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    $xml .= "  <url><loc>" . xml_e(APP_ORIGIN . '/') . "</loc>";
    if ($latest !== null) {
        $xml .= '<lastmod>' . utc_iso8601($latest) . '</lastmod>';
    }
    $xml .= "</url>\n";
    foreach ($infoPages as $infoPage) {
        $xml .= '  <url><loc>' . xml_e(APP_ORIGIN . $infoPage['path']) . "</loc></url>\n";
    }
    foreach ($archiveMonths as $archiveMonth) {
        if (preg_match('/\A([12][0-9]{3})-(0[1-9]|1[0-2])\z/D', (string) $archiveMonth['archive_month'], $match) !== 1) {
            continue;
        }
        $url = APP_ORIGIN . public_archive_path((int) $match[1], (int) $match[2]);
        $xml .= '  <url><loc>' . xml_e($url) . '</loc><lastmod>'
            . utc_iso8601((string) $archiveMonth['updated_at']) . "</lastmod></url>\n";
    }
    foreach ($rows as $row) {
        $url = APP_ORIGIN . public_message_path((int) $row['id']);
        $xml .= '  <url><loc>' . xml_e($url) . '</loc><lastmod>'
            . utc_iso8601((string) $row['updated_at']) . "</lastmod></url>\n";
    }
    return $xml . "</urlset>\n";
}

/** Build a deterministic, sessionless RSS feed from published records only. */
function public_rss_xml(PDO $pdo, int $limit = 20): string
{
    if ($limit < 1 || $limit > 50) {
        throw new InvalidArgumentException('Invalid feed limit');
    }
    $stmt = $pdo->prepare(
        "SELECT id, author, content, created_at
         FROM messages
         WHERE status = 'published'
         ORDER BY created_at DESC, id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll();

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<rss version=\"2.0\">\n<channel>\n";
    $xml .= "  <title>Micuțu Guestbook</title>\n";
    $xml .= '  <link>' . xml_e(APP_ORIGIN . '/') . "</link>\n";
    $xml .= "  <description>Recent public guestbook messages</description>\n";
    $xml .= "  <language>en</language>\n";
    if ($messages !== []) {
        $builtAt = strtotime((string) $messages[0]['created_at'] . ' UTC');
        $xml .= '  <lastBuildDate>' . gmdate(DATE_RSS, $builtAt === false ? 0 : $builtAt)
            . "</lastBuildDate>\n";
    }
    foreach ($messages as $message) {
        $url = APP_ORIGIN . public_message_path((int) $message['id']);
        $publishedAt = strtotime((string) $message['created_at'] . ' UTC');
        $xml .= "  <item>\n";
        $xml .= '    <title>' . xml_e((string) ($message['author'] ?: 'Anonymous')) . "</title>\n";
        $xml .= '    <link>' . xml_e($url) . "</link>\n";
        $xml .= '    <guid isPermaLink="true">' . xml_e($url) . "</guid>\n";
        $xml .= '    <description>' . xml_e((string) $message['content']) . "</description>\n";
        $xml .= '    <pubDate>' . gmdate(DATE_RSS, $publishedAt === false ? 0 : $publishedAt)
            . "</pubDate>\n  </item>\n";
    }
    return $xml . "</channel>\n</rss>\n";
}

/** Build a deterministic JSON Feed 1.1 document from published records only. */
function public_json_feed(PDO $pdo, int $limit = 20): string
{
    if ($limit < 1 || $limit > 50) {
        throw new InvalidArgumentException('Invalid feed limit');
    }
    $stmt = $pdo->prepare(
        "SELECT id, author, content, created_at
         FROM messages
         WHERE status = 'published'
         ORDER BY created_at DESC, id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $message) {
        $author = (string) ($message['author'] ?: 'Anonymous');
        $url = APP_ORIGIN . public_message_path((int) $message['id']);
        $items[] = [
            'id' => $url,
            'url' => $url,
            'title' => 'Message by ' . seo_excerpt($author, 60),
            'content_text' => (string) $message['content'],
            'date_published' => utc_iso8601((string) $message['created_at']),
            'authors' => [['name' => $author]],
        ];
    }

    return json_encode(
        [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'Micuțu Guestbook',
            'home_page_url' => APP_ORIGIN . '/',
            'feed_url' => APP_ORIGIN . '/feed.json',
            'description' => 'Recent public guestbook messages',
            'language' => 'en',
            'items' => $items,
        ],
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR
    ) . "\n";
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

/** Stable logical actor for author withdrawals; never identifies a visitor. */
function message_owner_actor_hash(): string
{
    return hash_hmac('sha256', "message-owner-actor\0self-service", APP_SECRET);
}

/** Create an unguessable URL-safe capability used only in an HttpOnly cookie. */
function message_owner_token_create(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/** Accept only canonical 256-bit base64url ownership capabilities. */
function message_owner_token_valid(string $token): bool
{
    return preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) === 1;
}

/** Store only a purpose-separated keyed digest of the ownership capability. */
function message_owner_token_hash(string $token): string
{
    if (!message_owner_token_valid($token)) {
        throw new InvalidArgumentException('Invalid message ownership capability');
    }
    return hash_hmac('sha256', "message-owner-token\0" . $token, APP_SECRET);
}

/** Bind the destructive action to both the browser session and capability. */
function message_owner_csrf_token(int $messageId, string $ownerToken): string
{
    if ($messageId < 1 || !message_owner_token_valid($ownerToken)) {
        throw new InvalidArgumentException('Invalid message ownership request');
    }
    return hash_hmac(
        'sha256',
        "message-owner-csrf\0" . $messageId . "\0" . csrf_token(),
        $ownerToken
    );
}

/** Verify the capability-bound token without type coercion. */
function message_owner_csrf_valid(int $messageId, string $ownerToken): bool
{
    $provided = string_input($_POST, 'owner_csrf');
    return $provided !== null
        && strlen($provided) === 64
        && hash_equals(message_owner_csrf_token($messageId, $ownerToken), $provided);
}

/** Read the path-scoped ownership cookie without accepting malformed values. */
function message_owner_cookie_token(int $messageId): ?string
{
    if ($messageId < 1) {
        return null;
    }
    $token = $_COOKIE['gbowner_' . $messageId] ?? null;
    return is_string($token) && message_owner_token_valid($token) ? $token : null;
}

/** Set the raw capability only as a path-scoped, HttpOnly, strict cookie. */
function message_owner_cookie_set(int $messageId, string $ownerToken): bool
{
    if ($messageId < 1 || !message_owner_token_valid($ownerToken)) {
        throw new InvalidArgumentException('Invalid message ownership cookie');
    }
    return setcookie('gbowner_' . $messageId, $ownerToken, [
        'expires' => time() + MESSAGE_OWNER_COOKIE_SECONDS,
        'path' => message_manage_path($messageId),
        'secure' => str_starts_with(APP_ORIGIN, 'https://'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/** Expire the capability at the same path and with the same security flags. */
function message_owner_cookie_expire(int $messageId): bool
{
    if ($messageId < 1) {
        throw new InvalidArgumentException('Invalid message ownership cookie');
    }
    return setcookie('gbowner_' . $messageId, '', [
        'expires' => 1,
        'path' => message_manage_path($messageId),
        'secure' => str_starts_with(APP_ORIGIN, 'https://'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
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
    return ['spam', 'harassment', 'privacy', 'policy', 'requested', 'risk', 'other', 'restored'];
}

/** Fixed, non-PII submission-risk reason codes. */
function submission_risk_flags(): array
{
    return [
        'duplicate',
        'unicode_control',
        'many_links',
        'multiple_links',
        'link_heavy',
        'author_address',
        'repeated_run',
        'fast_submit',
    ];
}

/** Purpose-separated keyed fingerprint used only for the 24-hour duplicate window. */
function submission_fingerprint(string $content): string
{
    $normalized = Normalizer::normalize($content, Normalizer::FORM_C);
    if (!is_string($normalized)) {
        throw new InvalidArgumentException('Invalid submission encoding');
    }
    return hash_hmac('sha256', "submission-content\0" . $normalized, APP_SECRET);
}

/**
 * Compute an explainable, bounded risk score. Flags are fixed codes and do not
 * contain submitted text. This function never changes moderation state itself.
 *
 * @return array{score:int, flags:list<string>, fingerprint:string}
 */
function assess_submission_risk(
    PDO $pdo,
    string $author,
    string $content,
    array $validationSignals = [],
    ?int $formAgeSeconds = null,
    ?int $now = null
): array {
    $allowedSignals = submission_risk_flags();
    foreach ($validationSignals as $signal) {
        if (!is_string($signal) || !in_array($signal, $allowedSignals, true)) {
            throw new InvalidArgumentException('Invalid submission risk signal');
        }
    }
    if ($formAgeSeconds !== null && ($formAgeSeconds < 0 || $formAgeSeconds > 86400)) {
        throw new InvalidArgumentException('Invalid form age');
    }

    $now ??= time();
    $fingerprint = submission_fingerprint($content);
    $score = 0;
    $flags = [];
    $add = static function (string $flag, int $points) use (&$score, &$flags): void {
        if (!isset($flags[$flag])) {
            $flags[$flag] = true;
            $score += $points;
        }
    };

    $duplicate = $pdo->prepare(
        "SELECT 1 FROM messages
         WHERE content_fingerprint = :fingerprint
           AND created_at >= datetime(:cutoff, 'unixepoch')
         LIMIT 1"
    );
    $duplicate->execute([
        ':fingerprint' => $fingerprint,
        ':cutoff' => $now - 86400,
    ]);
    if ($duplicate->fetchColumn() !== false) {
        $add('duplicate', 50);
    }

    if (in_array('unicode_control', $validationSignals, true)) {
        $add('unicode_control', 45);
    }

    $linkCount = preg_match_all(
        "~(?:https?://|www\\.)[^\\s<>\"']+~iu",
        $content,
        $linkMatches
    );
    $linkCount = $linkCount === false ? 0 : $linkCount;
    if ($linkCount >= 3) {
        $add('many_links', 45);
    } elseif ($linkCount === 2) {
        $add('multiple_links', 25);
    }
    if ($linkCount > 0) {
        $linkCharacters = 0;
        foreach ($linkMatches[0] as $link) {
            $linkCharacters += mb_strlen($link, 'UTF-8');
        }
        $contentLength = max(1, mb_strlen($content, 'UTF-8'));
        if ($linkCharacters / $contentLength >= 0.55) {
            $add('link_heavy', 25);
        }
    }

    if (preg_match("~(?:https?://|www\\.)|(?:[a-z0-9-]+\\.)+[a-z]{2,}~iu", $author) === 1) {
        $add('author_address', 20);
    }
    if (preg_match('/([\p{L}\p{N}\p{P}])\1{11,}/u', $content) === 1) {
        $add('repeated_run', 20);
    }
    if ($formAgeSeconds !== null && $formAgeSeconds <= 2) {
        $add('fast_submit', 10);
    }

    return [
        'score' => min(100, $score),
        'flags' => array_keys($flags),
        'fingerprint' => $fingerprint,
    ];
}

/**
 * Persist a validated submission and apply only a reversible pending/public choice.
 * Duplicate assessment and insertion are serialized to avoid a race between posts.
 *
 * @return array{id:int, status:string, score:int, flags:list<string>, owner_token:string}
 */
function create_submission(
    PDO $pdo,
    string $author,
    string $content,
    string $ipHash,
    array $validationSignals = [],
    ?int $formAgeSeconds = null,
    ?int $now = null
): array {
    if ($author === '' || $content === ''
        || preg_match('/\A[0-9a-f]{64}\z/D', $ipHash) !== 1) {
        throw new InvalidArgumentException('Invalid validated submission');
    }
    $now ??= time();
    $ownerToken = message_owner_token_create();
    $ownerTokenHash = message_owner_token_hash($ownerToken);

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $risk = assess_submission_risk(
            $pdo,
            $author,
            $content,
            $validationSignals,
            $formAgeSeconds,
            $now
        );
        $queued = $risk['score'] >= RISK_REVIEW_THRESHOLD;
        $status = $queued ? 'pending' : 'published';
        $stmt = $pdo->prepare(
            "INSERT INTO messages
                (author, content, ip_hash, created_at, status, moderation_reason,
                 content_fingerprint, risk_score, risk_flags, owner_token_hash)
             VALUES
                (:author, :content, :ip_hash, datetime(:created, 'unixepoch'), :status,
                 :reason, :fingerprint, :score, :flags, :owner_token_hash)"
        );
        $stmt->execute([
            ':author' => $author,
            ':content' => $content,
            ':ip_hash' => $ipHash,
            ':created' => $now,
            ':status' => $status,
            ':reason' => $queued ? 'risk' : null,
            ':fingerprint' => $risk['fingerprint'],
            ':score' => $risk['score'],
            ':flags' => json_encode($risk['flags'], JSON_THROW_ON_ERROR),
            ':owner_token_hash' => $ownerTokenHash,
        ]);
        $messageId = (int) $pdo->lastInsertId();
        if ($queued) {
            security_event($pdo, 'message.queued', 'success', null, $messageId, $now);
        }
        $pdo->exec('COMMIT');
        return [
            'id' => $messageId,
            'status' => $status,
            'score' => $risk['score'],
            'flags' => $risk['flags'],
            'owner_token' => $ownerToken,
        ];
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // Preserve the original exception.
        }
        throw $e;
    }
}

/** Return minimal private metadata only when the supplied capability owns it. */
function owned_message(PDO $pdo, int $messageId, string $ownerToken): ?array
{
    if ($messageId < 1 || !message_owner_token_valid($ownerToken)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, status, created_at, owner_token_hash, owner_withdrawn_at
         FROM messages WHERE id = :id'
    );
    $stmt->execute([':id' => $messageId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($message === false
        || !is_string($message['owner_token_hash'])
        || !hash_equals($message['owner_token_hash'], message_owner_token_hash($ownerToken))) {
        return null;
    }
    unset($message['owner_token_hash']);
    return $message;
}

/**
 * Irreversibly withdraw an owned message from every application-controlled
 * public surface while retaining its text for the documented audit lifecycle.
 */
function withdraw_owned_message(
    PDO $pdo,
    int $messageId,
    string $ownerToken,
    ?int $now = null
): bool {
    if ($messageId < 1 || !message_owner_token_valid($ownerToken)) {
        return false;
    }
    $now ??= time();

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $lookup = $pdo->prepare(
            'SELECT status, featured_at, owner_token_hash, owner_withdrawn_at
             FROM messages WHERE id = :id'
        );
        $lookup->execute([':id' => $messageId]);
        $message = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($message === false
            || $message['owner_withdrawn_at'] !== null
            || !is_string($message['owner_token_hash'])
            || !hash_equals($message['owner_token_hash'], message_owner_token_hash($ownerToken))) {
            $pdo->exec('COMMIT');
            return false;
        }
        $previousState = $message['status'];
        if (!is_string($previousState) || !in_array($previousState, moderation_states(), true)) {
            throw new RuntimeException('Message has an invalid moderation state');
        }
        $wasFeatured = is_string($message['featured_at']) && $message['featured_at'] !== '';

        $update = $pdo->prepare(
            "UPDATE messages
             SET status = 'deleted',
                 deleted_at = datetime(:now, 'unixepoch'),
                 moderation_reason = 'requested',
                 moderated_at = datetime(:now, 'unixepoch'),
                 owner_withdrawn_at = datetime(:now, 'unixepoch'),
                 owner_token_hash = NULL,
                 ip_hash = NULL,
                 content_fingerprint = NULL,
                 featured_at = NULL,
                 feature_changed_at = CASE
                     WHEN featured_at IS NOT NULL THEN datetime(:now, 'unixepoch')
                     ELSE feature_changed_at
                 END
             WHERE id = :id AND owner_token_hash = :owner_token_hash
               AND owner_withdrawn_at IS NULL"
        );
        $update->execute([
            ':now' => $now,
            ':id' => $messageId,
            ':owner_token_hash' => $message['owner_token_hash'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Concurrent message withdrawal');
        }

        $actorHash = message_owner_actor_hash();
        if ($wasFeatured) {
            admin_audit_event(
                $pdo,
                'message.unfeature',
                'success',
                $actorHash,
                $messageId,
                null,
                null,
                null,
                $now
            );
        }
        admin_audit_event(
            $pdo,
            'message.delete',
            'success',
            $actorHash,
            $messageId,
            $previousState,
            'deleted',
            'requested',
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

/** Read and validate a scalar enum input without coercion. */
function enum_input(array $source, string $key, array $allowed): ?string
{
    $value = string_input($source, $key);
    return $value !== null && in_array($value, $allowed, true) ? $value : null;
}

/**
 * Convert a visitor's plain-text search into bounded FTS5 prefix terms.
 *
 * Raw FTS syntax is never accepted: operators, quotes, column selectors and
 * punctuation are discarded before the generated expression reaches MATCH.
 */
function fts5_query_from_text(string $input): ?string
{
    $input = mb_substr(trim($input), 0, 100);
    if ($input === '') {
        return null;
    }

    $matched = preg_match_all('/[\p{L}\p{N}]+/u', $input, $matches);
    if ($matched === false || $matched === 0) {
        return null;
    }

    $terms = [];
    foreach ($matches[0] as $rawTerm) {
        $term = mb_strtolower(mb_substr($rawTerm, 0, 48, 'UTF-8'), 'UTF-8');
        if ($term === '' || isset($terms[$term])) {
            continue;
        }
        $terms[$term] = '"' . $term . '"*';
        if (count($terms) === 8) {
            break;
        }
    }

    return $terms === [] ? null : implode(' AND ', array_values($terms));
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
        $lookup = $pdo->prepare(
            'SELECT status, featured_at, owner_withdrawn_at FROM messages WHERE id = :id'
        );
        $lookup->execute([':id' => $messageId]);
        $message = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($message === false
            || $message['status'] === $newState
            || ($newState === 'published' && $message['owner_withdrawn_at'] !== null)) {
            $pdo->exec('COMMIT');
            return false;
        }
        $previousState = $message['status'];
        if (!is_string($previousState) || !in_array($previousState, moderation_states(), true)) {
            throw new RuntimeException('Message has an invalid moderation state');
        }
        $wasFeatured = is_string($message['featured_at']) && $message['featured_at'] !== '';

        $update = $pdo->prepare(
            "UPDATE messages
             SET status = :new_state,
                 moderation_reason = :reason,
                 moderated_at = datetime(:now, 'unixepoch'),
                 deleted_at = CASE
                     WHEN :new_state = 'deleted' THEN datetime(:now, 'unixepoch')
                     ELSE NULL
                 END,
                 ip_hash = CASE WHEN :new_state = 'published' THEN ip_hash ELSE NULL END,
                 featured_at = CASE
                     WHEN :new_state = 'published' THEN featured_at
                     ELSE NULL
                 END,
                 feature_changed_at = CASE
                     WHEN :new_state != 'published' AND featured_at IS NOT NULL
                         THEN datetime(:now, 'unixepoch')
                     ELSE feature_changed_at
                 END
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

        if ($wasFeatured && $newState !== 'published') {
            admin_audit_event(
                $pdo,
                'message.unfeature',
                'success',
                $actorHash,
                $messageId,
                null,
                null,
                null,
                $now
            );
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

/**
 * Feature or unfeature one public message and append the change to the admin
 * audit trail. Featuring a new message atomically replaces the previous one.
 */
function set_message_featured(
    PDO $pdo,
    int $messageId,
    bool $featured,
    string $actorHash,
    ?int $now = null
): bool {
    if ($messageId < 1 || preg_match('/\A[0-9a-f]{64}\z/D', $actorHash) !== 1) {
        throw new InvalidArgumentException('Invalid featured-message change');
    }
    $now ??= time();

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $lookup = $pdo->prepare(
            'SELECT status, featured_at FROM messages WHERE id = :id'
        );
        $lookup->execute([':id' => $messageId]);
        $message = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($message === false || $message['status'] !== 'published') {
            $pdo->exec('COMMIT');
            return false;
        }

        $currentlyFeatured = is_string($message['featured_at'])
            && $message['featured_at'] !== '';
        if ($currentlyFeatured === $featured) {
            $pdo->exec('COMMIT');
            return false;
        }

        if ($featured) {
            $previousId = $pdo->query(
                'SELECT id FROM messages WHERE featured_at IS NOT NULL LIMIT 1'
            )->fetchColumn();
            if ($previousId !== false) {
                $clear = $pdo->prepare(
                    "UPDATE messages
                     SET featured_at = NULL,
                         feature_changed_at = datetime(:now, 'unixepoch')
                     WHERE id = :id AND featured_at IS NOT NULL"
                );
                $clear->execute([':now' => $now, ':id' => (int) $previousId]);
                if ($clear->rowCount() !== 1) {
                    throw new RuntimeException('Concurrent featured-message replacement');
                }
                admin_audit_event(
                    $pdo,
                    'message.unfeature',
                    'success',
                    $actorHash,
                    (int) $previousId,
                    null,
                    null,
                    null,
                    $now
                );
            }

            $update = $pdo->prepare(
                "UPDATE messages
                 SET featured_at = datetime(:now, 'unixepoch'),
                     feature_changed_at = datetime(:now, 'unixepoch')
                 WHERE id = :id AND status = 'published' AND featured_at IS NULL"
            );
        } else {
            $update = $pdo->prepare(
                "UPDATE messages
                 SET featured_at = NULL,
                     feature_changed_at = datetime(:now, 'unixepoch')
                 WHERE id = :id AND status = 'published' AND featured_at IS NOT NULL"
            );
        }
        $update->execute([':now' => $now, ':id' => $messageId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Concurrent featured-message change');
        }

        admin_audit_event(
            $pdo,
            $featured ? 'message.feature' : 'message.unfeature',
            'success',
            $actorHash,
            $messageId,
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
        "UPDATE messages SET ip_hash = NULL, content_fingerprint = NULL
         WHERE (ip_hash IS NOT NULL OR content_fingerprint IS NOT NULL)
           AND created_at < datetime(:cutoff, 'unixepoch')"
    );
    $identifiers->execute([':cutoff' => $now - 86400]);
    // Browser capabilities expire after one year. A one-day safety margin avoids
    // invalidating a cookie whose response was issued seconds after the DB row.
    $ownerCapabilities = $pdo->prepare(
        "UPDATE messages SET owner_token_hash = NULL
         WHERE owner_token_hash IS NOT NULL
           AND created_at < datetime(:cutoff, 'unixepoch')"
    );
    $ownerCapabilities->execute([':cutoff' => $now - 366 * 86400]);
    return [
        'rate_limits' => $limits->rowCount(),
        'security_events' => $events->rowCount(),
        'abuse_reports' => $reports->rowCount(),
        'message_identifiers' => $identifiers->rowCount(),
        'owner_capabilities' => $ownerCapabilities->rowCount(),
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
 * Validate + normalise a submitted message.
 * Returns [author, content, error, fixed risk signals].
 * On error, author/content are empty and error is a user-facing string.
 */
function validate_submission(array $post): array
{
    $rawAuthor = string_input($post, 'author');
    $rawContent = string_input($post, 'message');
    if ($rawContent === null || ($rawAuthor === null && array_key_exists('author', $post))) {
        return ['', '', 'Invalid form data. Please try again.', []];
    }

    $normalizedAuthor = Normalizer::normalize($rawAuthor ?? '', Normalizer::FORM_C);
    $normalizedContent = Normalizer::normalize($rawContent, Normalizer::FORM_C);
    if (!is_string($normalizedAuthor) || !is_string($normalizedContent)) {
        return ['', '', 'Invalid form data. Please try again.', []];
    }
    $author = trim($normalizedAuthor);
    $content = trim($normalizedContent);

    // Collapse Windows newlines and strip controls, including invisible Unicode
    // formatting characters that can visually spoof moderator-facing text.
    $signals = [];
    $unicodeControls = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u';
    if (preg_match($unicodeControls, $author . $content) === 1) {
        $signals[] = 'unicode_control';
    }
    $content = str_replace("\r\n", "\n", $content);
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content) ?? $content;
    $author  = preg_replace('/[\x00-\x1F\x7F]/u', '', $author) ?? $author;
    $content = preg_replace($unicodeControls, '', $content) ?? $content;
    $author = preg_replace($unicodeControls, '', $author) ?? $author;

    if ($content === '') {
        return ['', '', 'Message content cannot be empty.', []];
    }
    if (mb_strlen($content) > MESSAGE_MAX) {
        return ['', '', 'Message is too long (max ' . MESSAGE_MAX . ' characters).', []];
    }
    if (mb_strlen($author) > AUTHOR_MAX) {
        return ['', '', 'Name is too long (max ' . AUTHOR_MAX . ' characters).', []];
    }
    if ($author === '') {
        $author = 'Anonymous';
    }
    // Clamp runaway blank lines.
    $content = preg_replace("/\n{4,}/", "\n\n\n", $content) ?? $content;

    return [$author, $content, null, $signals];
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
        'share'   => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="10.51" x2="15.42" y2="6.49"/><line x1="8.59" y1="13.49" x2="15.42" y2="17.51"/>',
        'star'    => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
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
