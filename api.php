<?php
/**
 * Public API helpers: signed cursor pagination and conditional-request handling.
 * Included by index.php and the dependency-free unit tests only.
 */

declare(strict_types=1);

if (!defined('GUESTBOOK_APP')) {
    http_response_code(404);
    exit;
}

/** Base64url without padding, suitable for opaque query parameters. */
function api_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** Strict base64url decoding. */
function api_base64url_decode(string $value): ?string
{
    if ($value === '' || strlen($value) > 256 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
        return null;
    }
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    return $decoded === false ? null : $decoded;
}

/** Create a tamper-evident cursor without exposing the application secret. */
function api_cursor_encode(string $createdAt, int $id): string
{
    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $createdAt,
        new DateTimeZone('UTC')
    );
    if ($id < 1 || $parsed === false || $parsed->format('Y-m-d H:i:s') !== $createdAt) {
        throw new InvalidArgumentException('Invalid cursor source');
    }

    $payload = $createdAt . "\0" . $id;
    $signature = hash_hmac('sha256', "api-cursor\0" . $payload, APP_SECRET, true);
    return api_base64url_encode($payload) . '.' . api_base64url_encode($signature);
}

/** Decode and authenticate a cursor. Invalid cursors are always rejected. */
function api_cursor_decode(string $cursor): array
{
    if (strlen($cursor) > 384 || substr_count($cursor, '.') !== 1) {
        throw new InvalidArgumentException('Invalid cursor');
    }
    [$encodedPayload, $encodedSignature] = explode('.', $cursor, 2);
    $payload = api_base64url_decode($encodedPayload);
    $signature = api_base64url_decode($encodedSignature);
    if ($payload === null || $signature === null || strlen($signature) !== 32) {
        throw new InvalidArgumentException('Invalid cursor');
    }
    $expected = hash_hmac('sha256', "api-cursor\0" . $payload, APP_SECRET, true);
    if (!hash_equals($expected, $signature) || substr_count($payload, "\0") !== 1) {
        throw new InvalidArgumentException('Invalid cursor');
    }

    [$createdAt, $rawId] = explode("\0", $payload, 2);
    if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $rawId) !== 1) {
        throw new InvalidArgumentException('Invalid cursor');
    }
    $id = filter_var($rawId, FILTER_VALIDATE_INT);
    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $createdAt,
        new DateTimeZone('UTC')
    );
    if ($id === false || $parsed === false || $parsed->format('Y-m-d H:i:s') !== $createdAt) {
        throw new InvalidArgumentException('Invalid cursor');
    }

    return ['created_at' => $createdAt, 'id' => $id];
}

/**
 * Return a bounded page of public messages. The extra row determines whether a
 * next cursor exists without an unbounded COUNT query.
 */
function api_list_messages(PDO $pdo, int $limit, ?array $cursor = null): array
{
    if ($limit < 1 || $limit > 50) {
        throw new InvalidArgumentException('API limit must be between 1 and 50');
    }
    if ($cursor !== null
        && (!isset($cursor['created_at'], $cursor['id'])
            || !is_string($cursor['created_at'])
            || !is_int($cursor['id']))) {
        throw new InvalidArgumentException('Invalid decoded cursor');
    }

    $where = "status = 'published'";
    if ($cursor !== null) {
        $where .= ' AND (created_at < :cursor_created
                     OR (created_at = :cursor_created AND id < :cursor_id))';
    }
    $stmt = $pdo->prepare(
        "SELECT id, author, content, created_at
         FROM messages
         WHERE {$where}
         ORDER BY created_at DESC, id DESC
         LIMIT :limit"
    );
    if ($cursor !== null) {
        $stmt->bindValue(':cursor_created', $cursor['created_at'], PDO::PARAM_STR);
        $stmt->bindValue(':cursor_id', $cursor['id'], PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }
    $nextCursor = null;
    if ($hasMore && $rows !== []) {
        $last = $rows[array_key_last($rows)];
        $nextCursor = api_cursor_encode((string) $last['created_at'], (int) $last['id']);
    }

    return [
        'data' => array_map(static fn(array $message): array => [
            'id' => (int) $message['id'],
            'author' => (string) $message['author'],
            'content' => (string) $message['content'],
            'created_at' => str_replace(' ', 'T', (string) $message['created_at']) . 'Z',
            'url' => APP_ORIGIN . public_message_path((int) $message['id']),
        ], $rows),
        'pagination' => [
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
        ],
    ];
}

/** Weak comparison for If-None-Match on safe GET/HEAD requests. */
function api_etag_matches(string $ifNoneMatch, string $currentEtag): bool
{
    if ($ifNoneMatch === '' || strlen($ifNoneMatch) > 8192) {
        return false;
    }
    $normalize = static function (string $tag): string {
        $tag = trim($tag);
        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    };
    $current = $normalize($currentEtag);
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '*' || $normalize($candidate) === $current) {
            return true;
        }
    }
    return false;
}

/** Send a stable JSON error document without cache or session side effects. */
function api_error_response(int $status, string $code, string $message, bool $head = false): never
{
    if ($status < 400 || $status > 599
        || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D', $code) !== 1) {
        throw new InvalidArgumentException('Invalid API error');
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    if (!$head) {
        echo json_encode(
            ['error' => ['code' => $code, 'message' => $message]],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
    exit;
}
