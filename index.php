<?php

declare(strict_types=1);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '';
$isApiV1 = $requestPath === '/api/v1/messages';
$isSitemap = $requestPath === '/sitemap.xml';
$isFeed = $requestPath === '/feed.xml';
$messagePathId = null;
if (preg_match('#\A/messages/([1-9][0-9]{0,18})\z#D', $requestPath, $messagePathMatch) === 1) {
    $parsedMessagePathId = filter_var($messagePathMatch[1], FILTER_VALIDATE_INT);
    if ($parsedMessagePathId !== false) {
        $messagePathId = $parsedMessagePathId;
    }
}
$isInteractiveUi = in_array($requestPath, ['/', '/index.php'], true)
    || $messagePathId !== null;
$rawFormatHint = $_GET['format'] ?? null;
$isStatelessLegacyRead = $isInteractiveUi
    && in_array($method, ['GET', 'HEAD'], true)
    && is_string($rawFormatHint)
    && in_array($rawFormatHint, ['json', 'rss'], true);
$legacyMessageHint = $_GET['message'] ?? null;
$isLegacyMessageRedirect = in_array($requestPath, ['/', '/index.php'], true)
    && in_array($method, ['GET', 'HEAD'], true)
    && count($_GET) === 1
    && is_string($legacyMessageHint)
    && preg_match('/\A[1-9][0-9]{0,18}\z/D', $legacyMessageHint) === 1
    && filter_var($legacyMessageHint, FILTER_VALIDATE_INT) !== false;

define('GUESTBOOK_APP', true);
define(
    'GUESTBOOK_STATELESS_REQUEST',
    !$isInteractiveUi || $isStatelessLegacyRead || $isLegacyMessageRedirect
);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/api.php';

if ($isLegacyMessageRedirect) {
    header('Location: ' . public_message_path((int) $legacyMessageHint), true, 301);
    exit;
}

if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    $readOnlyRoute = $isApiV1 || $isSitemap || $isFeed;
    header('Allow: ' . ($readOnlyRoute ? 'GET, HEAD' : 'GET, HEAD, POST'));
    if ($isApiV1) {
        api_error_response(405, 'method_not_allowed', 'Only GET and HEAD are supported.');
    }
    abort_response(405, 'Method not allowed.');
}
if (!$isApiV1 && !$isSitemap && !$isFeed && $messagePathId === null
    && !empty($_SERVER['PATH_INFO'])) {
    abort_response(404, 'Not found.');
}

// ---------------------------------------------------------------------------
// Sessionless public API. This branch must exit before any session-backed UI.
// ---------------------------------------------------------------------------
if ($isApiV1) {
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        api_error_response(
            405,
            'method_not_allowed',
            'Only GET and HEAD are supported.',
            $method === 'HEAD'
        );
    }

    foreach (array_keys($_GET) as $queryKey) {
        if (!is_string($queryKey) || !in_array($queryKey, ['limit', 'cursor'], true)) {
            api_error_response(400, 'invalid_query', 'Unsupported query parameter.', $method === 'HEAD');
        }
    }

    $limit = 20;
    if (array_key_exists('limit', $_GET)) {
        $rawLimit = string_input($_GET, 'limit');
        if ($rawLimit === null || preg_match('/\A(?:[1-9]|[1-4][0-9]|50)\z/D', $rawLimit) !== 1) {
            api_error_response(400, 'invalid_limit', 'Limit must be between 1 and 50.', $method === 'HEAD');
        }
        $limit = (int) $rawLimit;
    }

    $cursor = null;
    if (array_key_exists('cursor', $_GET)) {
        $rawCursor = string_input($_GET, 'cursor');
        if ($rawCursor === null) {
            api_error_response(400, 'invalid_cursor', 'Cursor is invalid.', $method === 'HEAD');
        }
        try {
            $cursor = api_cursor_decode($rawCursor);
        } catch (InvalidArgumentException) {
            api_error_response(400, 'invalid_cursor', 'Cursor is invalid.', $method === 'HEAD');
        }
    }

    $document = ['api_version' => '1.0'] + api_list_messages($pdo, $limit, $cursor);
    $json = json_encode(
        $document,
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR
    );
    $etag = '"' . hash('sha256', $json) . '"';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
    header('ETag: ' . $etag);
    header('Vary: Accept-Encoding');
    header('X-Robots-Tag: noindex');

    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if (is_string($ifNoneMatch) && api_etag_matches($ifNoneMatch, $etag)) {
        http_response_code(304);
        exit;
    }
    if ($method !== 'HEAD') {
        echo $json;
    }
    exit;
}

/** Send a deterministic public document with conditional-GET support. */
function public_document_response(
    string $body,
    string $contentType,
    string $method,
    bool $noindex = false
): never
{
    $etag = '"' . hash('sha256', $body) . '"';
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
    header('ETag: ' . $etag);
    header('Vary: Accept-Encoding');
    if ($noindex) {
        header('X-Robots-Tag: noindex');
    }
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if (is_string($ifNoneMatch) && api_etag_matches($ifNoneMatch, $etag)) {
        http_response_code(304);
        exit;
    }
    if ($method !== 'HEAD') {
        echo $body;
    }
    exit;
}

if ($isSitemap || $isFeed) {
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        abort_response(405, 'Method not allowed.');
    }
    if ($_GET !== []) {
        abort_response(400, 'Unsupported query parameter.');
    }
    if ($isSitemap) {
        public_document_response(
            public_sitemap_xml($pdo),
            'application/xml; charset=utf-8',
            $method
        );
    }
    public_document_response(
        public_rss_xml($pdo),
        'application/rss+xml; charset=utf-8',
        $method,
        true
    );
}

if (!$isInteractiveUi) {
    abort_response(404, 'Not found.');
}

// ---------------------------------------------------------------------------
// Controller (all mutations are POST, CSRF-protected, and end in a redirect).
// ---------------------------------------------------------------------------
if ($method === 'POST') {
    if (!same_origin_request() || !csrf_valid()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('/');
    }

    $action = string_input($_POST, 'action');
    if ($action === null) {
        abort_response(400, 'Invalid form data.');
    }

    if ($action === 'login') {
        $loginSubject = client_ip_hash('admin-login');
        $retryAfter = rate_limit_retry_after($pdo, 'admin-login', $loginSubject);
        if ($retryAfter > 0) {
            security_event($pdo, 'admin.login', 'blocked', $loginSubject);
            flash_set('error', 'Too many attempts. Please try again later.');
            redirect('/');
        }
        $password = string_input($_POST, 'password');
        $hash = env('ADMIN_PASSWORD_HASH') ?? '';
        $ok = $password !== null
            && strlen($password) <= 1024
            && $hash !== ''
            && password_verify($password, $hash);
        if ($ok) {
            rate_limit_clear($pdo, 'admin-login', $loginSubject);
            security_event($pdo, 'admin.login', 'success', $loginSubject);
            admin_audit_event(
                $pdo,
                'admin.login',
                'success',
                admin_actor_hash()
            );
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_authenticated_at'] = time();
            $_SESSION['admin_last_seen_at'] = time();
            $_SESSION['admin_session_rotated_at'] = time();
            csrf_rotate();
            flash_set('success', 'Signed in as admin.');
        } else {
            $retryAfter = rate_limit_record_failure(
                $pdo,
                'admin-login',
                $loginSubject,
                5,
                900,
                900
            );
            security_event(
                $pdo,
                'admin.login',
                $retryAfter > 0 ? 'blocked' : 'failure',
                $loginSubject
            );
            flash_set('error', $retryAfter > 0
                ? 'Too many attempts. Please try again later.'
                : 'Incorrect password.');
        }
        redirect('/');
    }

    if ($action === 'logout') {
        $adminSubject = client_ip_hash('admin-action');
        security_event($pdo, 'admin.logout', 'success', $adminSubject);
        if (!empty($_SESSION['is_admin'])) {
            admin_audit_event($pdo, 'admin.logout', 'success', admin_actor_hash());
        }
        $_SESSION = [];
        session_regenerate_id(true);
        csrf_rotate();
        flash_set('success', 'Signed out.');
        redirect('/');
    }

    if (in_array($action, ['moderate', 'delete', 'restore'], true)) {
        $adminSubject = client_ip_hash('admin-action');
        if (empty($_SESSION['is_admin'])) {
            security_event($pdo, 'message.moderate', 'blocked', $adminSubject);
            flash_set('error', 'Not authorised.');
            redirect('/');
        }

        $id = id_input($_POST, 'id');
        $newState = $action === 'delete'
            ? 'deleted'
            : ($action === 'restore'
                ? 'published'
                : enum_input($_POST, 'status', moderation_states()));
        $reason = $action === 'delete'
            ? 'policy'
            : ($action === 'restore'
                ? 'restored'
                : enum_input($_POST, 'reason', moderation_reasons()));

        $validPair = $newState !== null
            && $reason !== null
            && (($newState === 'published' && $reason === 'restored')
                || ($newState !== 'published' && $reason !== 'restored'));
        if ($id === null || !$validPair) {
            security_event($pdo, 'message.moderate', 'failure', $adminSubject, $id);
            flash_set('error', 'Invalid moderation request.');
            redirect('/');
        }

        $changed = moderate_message($pdo, $id, $newState, $reason, admin_actor_hash());
        security_event(
            $pdo,
            'message.moderate',
            $changed ? 'success' : 'failure',
            $adminSubject,
            $id
        );
        flash_set($changed ? 'success' : 'error', $changed
            ? 'Moderation state updated.'
            : 'Message was not found or already had that state.');
        redirect('/');
    }

    if ($action === 'report') {
        $id = id_input($_POST, 'id');
        $category = enum_input($_POST, 'category', abuse_report_categories());
        if ($id === null || $category === null) {
            flash_set('error', 'Invalid report. Please try again.');
            redirect('/');
        }

        $reportSubject = client_ip_hash('abuse-report');
        if (rate_limit_retry_after($pdo, 'abuse-report', $reportSubject) > 0) {
            security_event($pdo, 'abuse.report', 'blocked', null, $id);
            flash_set('error', 'Too many reports. Please try again later.');
            redirect(public_message_path($id, true));
        }

        $created = create_abuse_report($pdo, $id, $category);
        if ($created) {
            rate_limit_record_failure($pdo, 'abuse-report', $reportSubject, 5, 3600, 3600);
            security_event($pdo, 'abuse.report', 'success', null, $id);
            flash_set('success', 'Report received. Thank you for helping keep this space safe.');
        } else {
            security_event($pdo, 'abuse.report', 'failure', null, $id);
            flash_set('error', 'That message is no longer available for reporting.');
        }
        redirect(public_message_path($id, true));
    }

    if ($action === 'review_report') {
        $adminSubject = client_ip_hash('admin-action');
        if (empty($_SESSION['is_admin'])) {
            security_event($pdo, 'report.review', 'blocked', $adminSubject);
            flash_set('error', 'Not authorised.');
            redirect('/');
        }

        $reportId = id_input($_POST, 'id');
        $decision = enum_input($_POST, 'decision', ['resolved', 'dismissed']);
        if ($reportId === null || $decision === null) {
            security_event($pdo, 'report.review', 'failure', $adminSubject, $reportId);
            flash_set('error', 'Invalid report review.');
            redirect('/');
        }

        $changed = review_abuse_report($pdo, $reportId, $decision, admin_actor_hash());
        security_event(
            $pdo,
            'report.review',
            $changed ? 'success' : 'failure',
            $adminSubject,
            $reportId
        );
        flash_set($changed ? 'success' : 'error', $changed
            ? 'Report marked as ' . $decision . '.'
            : 'Report was not found or was already reviewed.');
        redirect('/');
    }

    if ($action === 'post') {
        // Honeypot: bots fill hidden fields humans never see.
        $website = string_input($_POST, 'website');
        if ($website === null && array_key_exists('website', $_POST)) {
            abort_response(400, 'Invalid form data.');
        }
        if ($website !== null && $website !== '') {
            flash_set('success', 'Thanks for your message!'); // silently drop
            redirect('/');
        }

        if ($limitError = rate_limit_check($pdo)) {
            flash_set('error', $limitError);
            redirect('/');
        }

        [$author, $content, $error, $validationSignals] = validate_submission($_POST);
        if ($error !== null) {
            old_input_set($_POST);
            flash_set('error', $error);
            redirect('/');
        }

        $submittedAt = time();
        $issuedAt = filter_var(
            $_SESSION['compose_issued_at'] ?? null,
            FILTER_VALIDATE_INT
        );
        $formAgeSeconds = $issuedAt === false
            ? null
            : max(0, min(86400, $submittedAt - (int) $issuedAt));
        $result = create_submission(
            $pdo,
            $author,
            $content,
            client_ip_hash(),
            $validationSignals,
            $formAgeSeconds,
            $submittedAt
        );
        $_SESSION['last_post_at'] = $submittedAt;
        $_SESSION['compose_issued_at'] = $submittedAt;
        $_SESSION['clear_compose_draft'] = true;
        flash_set('success', $result['status'] === 'pending'
            ? 'Message received and queued for moderator review.'
            : 'Message posted!');
        redirect('/');
    }

    redirect('/');
}

// Used only as a weak, low-weight automation signal. It is session-local, never
// persisted and cannot queue an otherwise normal submission by itself.
$_SESSION['compose_issued_at'] = time();

// ---------------------------------------------------------------------------
// Read model — search + pagination
// ---------------------------------------------------------------------------
$isAdmin = !empty($_SESSION['is_admin']);

$rawQuery = string_input($_GET, 'q');
if ($rawQuery === null && array_key_exists('q', $_GET)) {
    abort_response(400, 'Invalid query.');
}
$q      = mb_substr(trim($rawQuery ?? ''), 0, 100);
$messageId = $messagePathId;
if ($messageId !== null && $_GET !== []) {
    abort_response(400, 'Unsupported permalink parameters.');
}
if (array_key_exists('message', $_GET)) {
    $legacyMessageId = id_input($_GET, 'message');
    if ($legacyMessageId === null) {
        abort_response(400, 'Invalid message.');
    }
    abort_response(400, 'Unsupported permalink parameters.');
}
$from = 'messages AS m';
$whereParts = ["m.status = :status"];
$params = [':status' => 'published'];
if ($messageId !== null) {
    $whereParts[] = 'm.id = :message_id';
    $params[':message_id'] = $messageId;
}
if ($q !== '') {
    $ftsQuery = fts5_query_from_text($q);
    if ($ftsQuery === null) {
        $whereParts[] = '0 = 1';
    } else {
        $from = 'messages_fts JOIN messages AS m ON m.id = messages_fts.rowid';
        $whereParts[] = 'messages_fts MATCH :fts_query';
        $params[':fts_query'] = $ftsQuery;
    }
}
$where = 'WHERE ' . implode(' AND ', $whereParts);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$from} {$where}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
if ($messageId !== null && $total === 0) {
    abort_response(404, 'Message not found.');
}

$perPage = PER_PAGE;
$pages   = max(1, (int) ceil($total / $perPage));
if (array_key_exists('page', $_GET) && !is_string($_GET['page'])) {
    abort_response(400, 'Invalid page.');
}
$page = positive_int_input($_GET, 'page');
if ($page > $pages) {
    abort_response(404, 'Page not found.');
}
$offset  = ($page - 1) * $perPage;

$order = $q !== '' && isset($params[':fts_query'])
    ? 'bm25(messages_fts, 3.0, 1.0), m.created_at DESC, m.id DESC'
    : 'm.created_at DESC, m.id DESC';
$stmt = $pdo->prepare(
    "SELECT m.* FROM {$from} {$where} ORDER BY {$order} LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$messages = $stmt->fetchAll();

$moderatedMessages = [];
$openReports = [];
$adminAuditEvents = [];
$securityEvents = [];
$moderationCounts = array_fill_keys(moderation_states(), 0);
$openReportTotal = 0;
if ($isAdmin) {
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM messages GROUP BY status') as $row) {
        if (array_key_exists((string) $row['status'], $moderationCounts)) {
            $moderationCounts[(string) $row['status']] = (int) $row['total'];
        }
    }
    $moderatedMessages = $pdo->query(
        "SELECT id, author, content, status, moderation_reason, moderated_at, created_at,
                risk_score, risk_flags
         FROM messages WHERE status != 'published'
         ORDER BY COALESCE(moderated_at, deleted_at, created_at) DESC, id DESC LIMIT 30"
    )->fetchAll();
    foreach ($moderatedMessages as &$moderatedMessage) {
        $decodedFlags = json_decode((string) $moderatedMessage['risk_flags'], true);
        $moderatedMessage['risk_flag_codes'] = is_array($decodedFlags)
            ? array_values(array_filter(
                $decodedFlags,
                static fn($flag): bool => is_string($flag)
                    && in_array($flag, submission_risk_flags(), true)
            ))
            : [];
    }
    unset($moderatedMessage);
    $openReports = $pdo->query(
        "SELECT r.id, r.message_id, r.category, r.created_at,
                m.author, m.content, m.status AS message_status
         FROM abuse_reports AS r
         JOIN messages AS m ON m.id = r.message_id
         WHERE r.status = 'open'
         ORDER BY r.created_at ASC, r.id ASC LIMIT 30"
    )->fetchAll();
    $openReportTotal = (int) $pdo->query(
        "SELECT COUNT(*) FROM abuse_reports WHERE status = 'open'"
    )->fetchColumn();
    $adminAuditEvents = $pdo->query(
        "SELECT event_type, outcome, related_id, previous_state, new_state,
                reason_code, created_at
         FROM admin_audit_events ORDER BY id DESC LIMIT 30"
    )->fetchAll();
    $securityEvents = $pdo->query(
        "SELECT event_type, outcome, related_id, created_at
         FROM security_events ORDER BY id DESC LIMIT 20"
    )->fetchAll();
}

// ---------------------------------------------------------------------------
// Read-only JSON API: /?format=json[&q=...&page=N]
// ---------------------------------------------------------------------------
$format = string_input($_GET, 'format');
if ($format === null && array_key_exists('format', $_GET)) {
    abort_response(400, 'Invalid format.');
}
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('Deprecation: true');
    header('Link: </api/v1/messages>; rel="successor-version"');
    echo json_encode([
        'total'    => $total,
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $perPage,
        'query'    => $q,
        'messages' => array_map(static fn(array $m): array => [
            'id'         => (int) $m['id'],
            'author'     => $m['author'],
            'content'    => $m['content'],
            'created_at' => str_replace(' ', 'T', (string) $m['created_at']) . 'Z',
        ], $messages),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
if ($format === 'rss') {
    header('Location: /feed.xml', true, 301);
    exit;
}
if ($format !== null && $format !== '') {
    abort_response(400, 'Unsupported format.');
}

if ($messageId !== null) {
    $canonicalPath = public_message_path($messageId);
} elseif ($q !== '') {
    $canonicalParams = ['q' => $q];
    if ($page > 1) {
        $canonicalParams['page'] = $page;
    }
    $canonicalPath = '/?' . http_build_query($canonicalParams);
} elseif ($page > 1) {
    $canonicalPath = '/?page=' . $page;
} else {
    $canonicalPath = '/';
}
$canonicalUrl = APP_ORIGIN . $canonicalPath;
$primaryMessage = $messageId !== null && isset($messages[0]) ? $messages[0] : null;
$siteName = 'Micuțu Guestbook';
if (is_array($primaryMessage)) {
    $primaryAuthor = (string) ($primaryMessage['author'] ?: 'Anonymous');
    $pageTitle = 'Message by ' . seo_excerpt($primaryAuthor, 60) . ' · ' . $siteName;
    $pageDescription = seo_excerpt((string) $primaryMessage['content']);
} elseif ($q !== '') {
    $pageTitle = 'Search results · ' . $siteName;
    $pageDescription = 'Search results from the public Micuțu guestbook.';
} elseif ($page > 1) {
    $pageTitle = 'Guestbook archive · Page ' . $page . ' · Micuțu';
    $pageDescription = 'Older public messages in the Micuțu guestbook.';
} else {
    $pageTitle = 'Micuțu Guestbook · Leave a public message';
    $pageDescription = 'A small public guestbook for messages, thoughts and hellos.';
}
$robotsPolicy = $isAdmin || $q !== ''
    ? 'noindex, follow'
    : 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1';
$isCanonicalHome = $messageId === null && $q === '' && $page === 1;

/** Build a URL for the feed preserving the active search query. */
function feed_url(int $page, string $q): string
{
    $params = array_filter(['q' => $q, 'page' => $page > 1 ? $page : null],
        static fn($v) => $v !== null && $v !== '');
    return '/' . ($params ? '?' . http_build_query($params) : '');
}

$flash = flash_get();
$oldInput = old_input_get();
$clearComposeDraft = !empty($_SESSION['clear_compose_draft']);
unset($_SESSION['clear_compose_draft']);
$statusLabels = [
    'pending' => 'Pending',
    'published' => 'Published',
    'hidden' => 'Hidden',
    'deleted' => 'Deleted',
    'spam' => 'Spam',
];
$reasonLabels = [
    'spam' => 'Spam',
    'harassment' => 'Harassment',
    'privacy' => 'Privacy',
    'policy' => 'Policy violation',
    'requested' => 'Author request',
    'risk' => 'Automated review',
    'other' => 'Other',
    'restored' => 'Restore to public',
];
$riskFlagLabels = [
    'duplicate' => 'Recent duplicate',
    'unicode_control' => 'Invisible Unicode controls removed',
    'many_links' => 'Three or more links',
    'multiple_links' => 'Multiple links',
    'link_heavy' => 'Link-heavy content',
    'author_address' => 'Address-like name',
    'repeated_run' => 'Long repeated run',
    'fast_submit' => 'Very fast submission',
];
$reportLabels = [
    'spam' => 'Spam or scam',
    'harassment' => 'Harassment',
    'privacy' => 'Personal/private information',
    'other' => 'Other policy concern',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4f46e5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0e18" media="(prefers-color-scheme: dark)">
    <meta name="description" content="<?php echo e($pageDescription); ?>">
    <meta name="robots" content="<?php echo e($robotsPolicy); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo e($siteName); ?>">
    <meta property="og:locale" content="en_US">
    <meta property="og:title" content="<?php echo e($pageTitle); ?>">
    <meta property="og:description" content="<?php echo e($pageDescription); ?>">
    <meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?php echo e($pageTitle); ?>">
    <meta name="twitter:description" content="<?php echo e($pageDescription); ?>">
    <title><?php echo e($pageTitle); ?></title>
    <link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
    <link rel="alternate" type="application/rss+xml" title="Micuțu Guestbook RSS" href="/feed.xml">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml" sizes="any">
    <link rel="stylesheet" href="<?php echo e(asset('assets/style.css')); ?>">
    <script src="<?php echo e(asset('assets/theme-init.js')); ?>"></script>
    <script defer src="<?php echo e(asset('assets/app.js')); ?>"></script>
    <?php if (ANALYTICS_WEBSITE_ID !== ''): ?>
    <script defer src="<?php echo e(asset('assets/analytics.js')); ?>"
            data-website-id="<?php echo e(ANALYTICS_WEBSITE_ID); ?>"
            data-host-url="https://analytics.micutu.com"
            data-domains="php.micutu.com"
            data-do-not-track="true"
            data-exclude-search="true"
            data-exclude-hash="true"
            data-performance="true"></script>
    <?php endif; ?>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <main class="app<?php echo $isAdmin ? ' app-admin' : ''; ?>" id="main-content" tabindex="-1">
        <header class="header"<?php echo $isCanonicalHome ? ' itemscope itemtype="https://schema.org/WebSite"' : ''; ?>>
            <?php if ($isCanonicalHome): ?>
            <link itemprop="url" href="<?php echo e(APP_ORIGIN . '/'); ?>">
            <?php endif; ?>
            <h1 class="brand">
                <span class="logo"><?php echo icon('book'); ?></span>
                <span class="brand-text"<?php echo $isCanonicalHome ? ' itemprop="name"' : ''; ?>><?php echo e($siteName); ?></span>
            </h1>
            <div class="header-actions">
                <span class="badge"><?php echo $total; ?> message<?php echo $total === 1 ? '' : 's'; ?></span>
                <?php if ($isAdmin): ?>
                    <form method="POST"><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="logout">
                        <button class="linkbtn" type="submit">Sign out</button>
                    </form>
                <?php endif; ?>
                <button type="button" class="icon-btn" data-theme-toggle aria-label="Switch color theme" aria-pressed="false">
                    <?php echo icon('sun', 'icon-sun'); ?><?php echo icon('moon', 'icon-moon'); ?>
                </button>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="flash <?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>"
                 role="<?php echo $flash['type'] === 'error' ? 'alert' : 'status'; ?>"
                 <?php echo $flash['type'] === 'error' ? '' : 'data-auto-dismiss'; ?>>
                <?php echo icon($flash['type'] === 'error' ? 'alert' : 'check'); ?>
                <span><?php echo e($flash['message']); ?></span>
            </div>
        <?php endif; ?>
        <p class="sr-only" aria-live="polite" aria-atomic="true" data-share-status></p>

        <?php if ($messageId !== null): ?>
        <nav class="breadcrumbs" aria-label="Breadcrumb"
             itemscope itemtype="https://schema.org/BreadcrumbList">
            <ol>
                <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                    <a itemprop="item" href="/"><span itemprop="name">Guestbook</span></a>
                    <meta itemprop="position" content="1">
                </li>
                <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem"
                    aria-current="page">
                    <span itemprop="name">Message #<?php echo $messageId; ?></span>
                    <meta itemprop="position" content="2">
                </li>
            </ol>
        </nav>
        <?php endif; ?>

        <form method="POST" class="compose" data-compose-form<?php echo $clearComposeDraft ? ' data-clear-local-draft="true"' : ''; ?>>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="post">
            <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">

            <div class="field">
                <label class="label" for="author">Name <span>(optional)</span></label>
                <div class="input-wrap">
                    <?php echo icon('user', 'field-icon'); ?>
                    <input class="input" id="author" name="author" type="text"
                           maxlength="<?php echo AUTHOR_MAX; ?>" placeholder="Your name"
                           value="<?php echo e((string) ($oldInput['author'] ?? '')); ?>" autocomplete="name">
                </div>
            </div>

            <div class="field">
                <label class="label" for="message">
                    <span>Message <span class="req">*</span></span>
                    <span class="counter" id="msg-counter"></span>
                </label>
                <div class="input-wrap">
                    <?php echo icon('message', 'field-icon'); ?>
                    <textarea class="textarea" id="message" name="message" required rows="3"
                              maxlength="<?php echo MESSAGE_MAX; ?>" data-counter="msg-counter"
                              aria-describedby="msg-counter" enterkeyhint="send"
                              placeholder="What's on your mind?"><?php echo e((string) ($oldInput['message'] ?? '')); ?></textarea>
                </div>
            </div>

            <div class="draft-tools" data-draft-tools hidden>
                <label class="draft-opt-in">
                    <input type="checkbox" data-draft-opt-in>
                    <span>Save a draft on this device</span>
                </label>
                <span class="draft-privacy">Optional · expires after 24 hours</span>
                <button class="linkbtn draft-delete" type="button" data-draft-delete hidden>Delete saved draft</button>
                <span class="draft-status" data-draft-status aria-live="polite" aria-atomic="true"></span>
            </div>

            <div class="compose-actions">
                <button class="btn" type="submit"><?php echo icon('send'); ?> Post message</button>
                <button class="btn secondary preview-toggle" type="button" data-preview-toggle
                        aria-expanded="false" aria-controls="message-preview" hidden>Preview</button>
            </div>

            <section class="message-preview" id="message-preview" data-message-preview
                     aria-labelledby="preview-title" hidden>
                <p class="preview-label" id="preview-title">Private preview</p>
                <div class="preview-card">
                    <p class="preview-author" data-preview-author>Anonymous</p>
                    <p class="preview-body" data-preview-body>Start typing to preview your message.</p>
                </div>
            </section>
        </form>

        <section>
            <div class="feed-head">
                <h2 class="section-title"><?php echo icon('history'); ?> <?php echo $q !== '' ? 'Search results' : 'Recent posts'; ?></h2>
                <form method="GET" class="search" role="search">
                    <?php echo icon('search', 'field-icon'); ?>
                    <input class="input search-input" type="search" name="q" value="<?php echo e($q); ?>"
                           maxlength="100" placeholder="Search messages…" aria-label="Search messages">
                    <?php if ($q !== ''): ?>
                        <a class="search-clear" href="/" aria-label="Clear search">&times;</a>
                    <?php endif; ?>
                    <button class="search-submit" type="submit">Search</button>
                </form>
            </div>
            <?php if (count($messages) > 0): ?>
                <div class="feed">
                    <?php foreach ($messages as $msg): ?>
                        <?php $name = $msg['author'] ?? 'Anonymous'; ?>
                        <?php $isPrimaryPosting = $messageId !== null && (int) $msg['id'] === $messageId; ?>
                        <article class="msg" id="m<?php echo (int) $msg['id']; ?>"<?php echo $isPrimaryPosting ? ' itemscope itemtype="https://schema.org/DiscussionForumPosting"' : ''; ?>>
                            <?php if ($isPrimaryPosting): ?>
                            <meta itemprop="mainEntityOfPage" content="<?php echo e($canonicalUrl); ?>">
                            <link itemprop="url" href="<?php echo e($canonicalUrl); ?>">
                            <?php endif; ?>
                            <div class="msg-head">
                                <div class="avatar av-<?php echo avatar_index($name); ?>"><?php echo e(avatar_initial($name)); ?></div>
                                <div class="msg-meta"<?php echo $isPrimaryPosting ? ' itemprop="author" itemscope itemtype="https://schema.org/Person"' : ''; ?>>
                                    <p class="msg-author"<?php echo $isPrimaryPosting ? ' itemprop="name"' : ''; ?>><?php echo e($name); ?></p>
                                    <p class="msg-time">
                                        <?php echo icon('clock'); ?>
                                        <time datetime="<?php echo e(str_replace(' ', 'T', (string) $msg['created_at']) . 'Z'); ?>"
                                              <?php echo $isPrimaryPosting ? 'itemprop="datePublished" ' : ''; ?>
                                              title="<?php echo e($msg['created_at']); ?> UTC"><?php echo e(time_ago($msg['created_at'])); ?></time>
                                    </p>
                                </div>
                            </div>
                            <p class="msg-body"<?php echo $isPrimaryPosting ? ' itemprop="text"' : ''; ?>><?php echo e($msg['content']); ?></p>
                            <div class="message-actions">
                                <div class="message-primary-actions">
                                    <a class="permalink" href="<?php echo e(public_message_path((int) $msg['id'], true)); ?>" aria-label="#<?php echo (int) $msg['id']; ?> · Permalink to message by <?php echo e($name); ?>">#<?php echo (int) $msg['id']; ?> · Permalink</a>
                                    <button class="share-button" type="button" hidden
                                            data-share-path="<?php echo e(public_message_path((int) $msg['id'])); ?>"
                                            data-share-title="Micuțu Guestbook message #<?php echo (int) $msg['id']; ?>"
                                            aria-label="Share message #<?php echo (int) $msg['id']; ?>">
                                        <?php echo icon('share'); ?> Share
                                    </button>
                                </div>
                                <?php if (!$isAdmin): ?>
                                <details class="report-menu">
                                    <summary>Report</summary>
                                    <form method="POST" class="report-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="report">
                                        <input type="hidden" name="id" value="<?php echo (int) $msg['id']; ?>">
                                        <label for="report-category-<?php echo (int) $msg['id']; ?>">Why are you reporting this?</label>
                                        <select class="select" id="report-category-<?php echo (int) $msg['id']; ?>" name="category" required>
                                            <?php foreach ($reportLabels as $value => $label): ?>
                                            <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p>No free-text report or reporter identifier is stored.</p>
                                        <button class="btn secondary compact" type="submit">Submit report</button>
                                    </form>
                                </details>
                                <?php endif; ?>
                            </div>
                            <?php if ($isAdmin): ?>
                            <form method="POST" class="moderation-control">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="moderate">
                                <input type="hidden" name="id" value="<?php echo (int) $msg['id']; ?>">
                                <label>
                                    <span>Move to</span>
                                    <select class="select" name="status" required>
                                        <option value="pending">Pending</option>
                                        <option value="hidden">Hidden</option>
                                        <option value="spam">Spam</option>
                                        <option value="deleted">Deleted</option>
                                    </select>
                                </label>
                                <label>
                                    <span>Reason</span>
                                    <select class="select" name="reason" required>
                                        <?php foreach ($reasonLabels as $value => $label): ?>
                                            <?php if ($value !== 'restored'): ?>
                                            <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="btn secondary compact" type="submit">Apply</button>
                            </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($pages > 1): ?>
                <nav class="pager" aria-label="Pagination">
                    <?php if ($page > 1): ?>
                        <a class="pager-btn" href="<?php echo e(feed_url($page - 1, $q)); ?>" rel="prev">&larr; Newer</a>
                    <?php else: ?>
                        <span class="pager-btn disabled">&larr; Newer</span>
                    <?php endif; ?>
                    <span class="pager-info">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                    <?php if ($page < $pages): ?>
                        <a class="pager-btn" href="<?php echo e(feed_url($page + 1, $q)); ?>" rel="next">Older &rarr;</a>
                    <?php else: ?>
                        <span class="pager-btn disabled">Older &rarr;</span>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
            <?php elseif ($q !== ''): ?>
                <div class="empty">
                    <?php echo icon('search'); ?>
                    <h3>No matches</h3>
                    <p>Nothing found for &ldquo;<?php echo e($q); ?>&rdquo;. <a href="/">Clear search</a></p>
                </div>
            <?php else: ?>
                <div class="empty">
                    <?php echo icon('inbox'); ?>
                    <h3>It's pretty quiet here</h3>
                    <p>Be the first to leave a message in the guestbook!</p>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($isAdmin): ?>
        <section class="moderation" aria-labelledby="moderation-title">
            <div class="moderation-head">
                <div>
                    <p class="eyebrow">Admin workspace</p>
                    <h2 id="moderation-title">Moderation &amp; audit</h2>
                </div>
                <span class="badge"><?php echo $openReportTotal; ?> open report<?php echo $openReportTotal === 1 ? '' : 's'; ?></span>
            </div>

            <div class="moderation-stats" aria-label="Message status totals">
                <?php foreach ($moderationCounts as $status => $count): ?>
                <div><strong><?php echo $count; ?></strong><span><?php echo e($statusLabels[$status]); ?></span></div>
                <?php endforeach; ?>
            </div>

            <details class="admin-panel"<?php echo $openReportTotal > 0 ? ' open' : ''; ?>>
                <summary>Abuse reports · <?php echo $openReportTotal; ?> open</summary>
                <?php if ($openReports): ?>
                    <div class="moderation-list">
                    <?php foreach ($openReports as $report): ?>
                        <article class="moderation-item report-item">
                            <div>
                                <strong><?php echo e($reportLabels[$report['category']] ?? 'Policy concern'); ?></strong>
                                <span>Report #<?php echo (int) $report['id']; ?> · <?php echo e(time_ago('@' . $report['created_at'])); ?></span>
                            </div>
                            <p><span class="status-pill status-<?php echo e($report['message_status']); ?>"><?php echo e($report['message_status']); ?></span>
                                Message #<?php echo (int) $report['message_id']; ?> by <?php echo e($report['author'] ?: 'Anonymous'); ?><br>
                                <?php echo e($report['content']); ?></p>
                            <div class="review-actions">
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="review_report">
                                <input type="hidden" name="id" value="<?php echo (int) $report['id']; ?>">
                                <input type="hidden" name="decision" value="resolved">
                                <button class="btn secondary compact" type="submit">Resolve</button>
                            </form>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="review_report">
                                <input type="hidden" name="id" value="<?php echo (int) $report['id']; ?>">
                                <input type="hidden" name="decision" value="dismissed">
                                <button class="linkbtn compact" type="submit">Dismiss</button>
                            </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="panel-empty">No open reports.</p>
                <?php endif; ?>
            </details>

            <details class="admin-panel"<?php echo $moderatedMessages ? ' open' : ''; ?>>
                <summary>Moderated messages · <?php echo count($moderatedMessages); ?> recent</summary>
                <?php if ($moderatedMessages): ?>
                    <div class="moderation-list">
                    <?php foreach ($moderatedMessages as $moderated): ?>
                        <article class="moderation-item">
                            <div>
                                <strong><?php echo e($moderated['author'] ?: 'Anonymous'); ?></strong>
                                <span>#<?php echo (int) $moderated['id']; ?> ·
                                    <span class="status-pill status-<?php echo e($moderated['status']); ?>"><?php echo e($moderated['status']); ?></span>
                                    <?php echo e($reasonLabels[$moderated['moderation_reason']] ?? 'Unspecified'); ?>
                                </span>
                            </div>
                            <p><?php echo e($moderated['content']); ?></p>
                            <?php if ((int) $moderated['risk_score'] > 0): ?>
                            <div class="risk-summary" aria-label="Automated review signals">
                                <strong>Review score <?php echo (int) $moderated['risk_score']; ?>/100</strong>
                                <?php foreach ($moderated['risk_flag_codes'] as $riskFlag): ?>
                                <span><?php echo e($riskFlagLabels[$riskFlag]); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="review-actions moderation-actions">
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="moderate">
                                <input type="hidden" name="id" value="<?php echo (int) $moderated['id']; ?>">
                                <input type="hidden" name="status" value="published">
                                <input type="hidden" name="reason" value="restored">
                                <button class="btn secondary compact" type="submit"><?php echo icon('restore'); ?> Publish</button>
                            </form>
                            <form method="POST" class="moderation-control">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="moderate">
                                <input type="hidden" name="id" value="<?php echo (int) $moderated['id']; ?>">
                                <label>
                                    <span>Move to</span>
                                    <select class="select" name="status" required>
                                        <?php foreach ($statusLabels as $value => $label): ?>
                                            <?php if ($value !== 'published' && $value !== $moderated['status']): ?>
                                            <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Reason</span>
                                    <select class="select" name="reason" required>
                                        <?php foreach ($reasonLabels as $value => $label): ?>
                                            <?php if ($value !== 'restored'): ?>
                                            <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="btn secondary compact" type="submit">Update</button>
                            </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="panel-empty">No messages are currently moderated.</p>
                <?php endif; ?>
            </details>

            <details class="admin-panel">
                <summary>Append-only admin audit</summary>
                <?php if ($adminAuditEvents): ?>
                    <ol class="audit-list">
                    <?php foreach ($adminAuditEvents as $event): ?>
                        <li>
                            <span class="audit-outcome <?php echo e($event['outcome']); ?>"><?php echo e($event['outcome']); ?></span>
                            <code><?php echo e($event['event_type']); ?></code>
                            <span>
                                <?php if ($event['related_id'] !== null): ?>#<?php echo (int) $event['related_id']; ?><?php endif; ?>
                                <?php if ($event['previous_state'] !== null && $event['new_state'] !== null): ?>
                                    · <?php echo e($event['previous_state']); ?> → <?php echo e($event['new_state']); ?>
                                <?php endif; ?>
                                <?php if ($event['reason_code'] !== null): ?> · <?php echo e($event['reason_code']); ?><?php endif; ?>
                            </span>
                            <time datetime="<?php echo e(gmdate('c', (int) $event['created_at'])); ?>"><?php echo e(time_ago('@' . $event['created_at'])); ?></time>
                        </li>
                    <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <p class="panel-empty">No admin audit events recorded yet.</p>
                <?php endif; ?>
            </details>

            <details class="admin-panel">
                <summary>Recent security events</summary>
                <?php if ($securityEvents): ?>
                    <ol class="audit-list">
                    <?php foreach ($securityEvents as $event): ?>
                        <li>
                            <span class="audit-outcome <?php echo e($event['outcome']); ?>"><?php echo e($event['outcome']); ?></span>
                            <code><?php echo e($event['event_type']); ?></code>
                            <?php if ($event['related_id'] !== null): ?><span>#<?php echo (int) $event['related_id']; ?></span><?php endif; ?>
                            <time datetime="<?php echo e(gmdate('c', (int) $event['created_at'])); ?>"><?php echo e(time_ago('@' . $event['created_at'])); ?></time>
                        </li>
                    <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <p class="panel-empty">No security events recorded yet.</p>
                <?php endif; ?>
            </details>
        </section>
        <?php endif; ?>

        <?php if (!$isAdmin): ?>
        <details class="admin">
            <summary>Admin</summary>
            <form method="POST" class="admin-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="login">
                <input class="input" type="password" name="password" placeholder="Admin password"
                       autocomplete="current-password" maxlength="1024" required aria-label="Admin password">
                <button class="btn secondary" type="submit">Sign in</button>
            </form>
        </details>
        <?php endif; ?>

        <section class="site-info" id="about" aria-labelledby="about-title">
            <h2 id="about-title">About this space</h2>
            <p>A small public guestbook operated on Micuțu infrastructure. Be kind, avoid personal information, and use Report when a public message needs review.</p>
            <p>Published messages are also available through the <a href="/api/v1/messages">sessionless JSON API</a> with a machine-readable <a href="/openapi.json">OpenAPI contract</a>.</p>
            <p>Security concerns can be reported using the contact route in <a href="/.well-known/security.txt">security.txt</a>.</p>
        </section>

        <details class="privacy" id="privacy">
            <summary>Privacy, moderation &amp; data use</summary>
            <p>Messages and names are public. A keyed, non-reversible network identifier is used for abuse prevention and detached from messages after 24 hours. Submissions may be queued for human review using fixed signals such as recent duplication, link density and invisible Unicode controls; no message is automatically deleted. The keyed duplicate fingerprint is also removed after 24 hours. Security events are retained for up to 90 days.</p>
            <p>Abuse reports contain only the selected category and message number. They do not store a reporter identifier or free-text explanation. Reviewed reports are deleted after 90 days; minimal append-only admin actions remain for accountability.</p>
            <p>Draft saving is optional. When enabled, the name and message stay only in this browser's local storage, expire after 24 hours and are removed after a successful submission. Previewing never sends additional data or renders message HTML.</p>
            <?php if (ANALYTICS_WEBSITE_ID !== ''): ?>
            <p>This deployment loads a reviewed, versioned analytics tracker from this site and sends aggregate page and performance data to the self-hosted <code>analytics.micutu.com</code> endpoint. It respects Do Not Track and excludes query strings, URL fragments and form content.</p>
            <?php else: ?>
            <p>Optional analytics is disabled on this deployment.</p>
            <?php endif; ?>
        </details>
    </main>
</body>
</html>
