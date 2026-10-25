<?php
/**
 * Pure admin-session lifetime policy shared by bootstrap and regression tests.
 */

declare(strict_types=1);

if (!defined('GUESTBOOK_APP')) {
    http_response_code(404);
    exit;
}

/**
 * Accept only coherent, server-generated timestamps within both lifetime bounds.
 *
 * Session serialization preserves integer types, so accepting numeric strings would
 * hide corrupt or attacker-influenced state instead of failing closed.
 *
 * @param array<string, mixed> $session
 */
function admin_session_is_current(
    array $session,
    int $now,
    int $idleSeconds,
    int $absoluteSeconds
): bool {
    $authenticatedAt = $session['admin_authenticated_at'] ?? null;
    $lastSeenAt = $session['admin_last_seen_at'] ?? null;

    if ($now < 1 || $idleSeconds < 1 || $absoluteSeconds < 1
        || !is_int($authenticatedAt) || !is_int($lastSeenAt)) {
        return false;
    }

    if ($authenticatedAt < 1
        || $authenticatedAt > $lastSeenAt
        || $lastSeenAt > $now) {
        return false;
    }

    return $now - $lastSeenAt <= $idleSeconds
        && $now - $authenticatedAt <= $absoluteSeconds;
}
