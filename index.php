<?php

declare(strict_types=1);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '';
$isApiV1 = $requestPath === '/api/v1/messages';
$isSitemap = $requestPath === '/sitemap.xml';
$isFeed = $requestPath === '/feed.xml';
$isJsonFeed = $requestPath === '/feed.json';
$isArchiveIndex = $requestPath === '/archive';
$publicInfoPageSlug = match ($requestPath) {
    '/about' => 'about',
    '/privacy' => 'privacy',
    '/moderation' => 'moderation',
    default => null,
};
$isPublicInfoPage = $publicInfoPageSlug !== null;
$messagePathId = null;
$messageManagePathId = null;
$archiveYear = null;
$archiveMonth = null;
if (preg_match('#\A/messages/([1-9][0-9]{0,18})\z#D', $requestPath, $messagePathMatch) === 1) {
    $parsedMessagePathId = filter_var($messagePathMatch[1], FILTER_VALIDATE_INT);
    if ($parsedMessagePathId !== false) {
        $messagePathId = $parsedMessagePathId;
    }
}
if (preg_match('#\A/messages/([1-9][0-9]{0,18})/manage\z#D', $requestPath, $messageManagePathMatch) === 1) {
    $parsedMessageManagePathId = filter_var($messageManagePathMatch[1], FILTER_VALIDATE_INT);
    if ($parsedMessageManagePathId !== false) {
        $messageManagePathId = $parsedMessageManagePathId;
    }
}
if (preg_match('#\A/archive/([12][0-9]{3})/(0[1-9]|1[0-2])\z#D', $requestPath, $archivePathMatch) === 1) {
    $archiveYear = (int) $archivePathMatch[1];
    $archiveMonth = (int) $archivePathMatch[2];
}
$isArchiveMonth = $archiveYear !== null && $archiveMonth !== null;
$ownerCookieHint = $messageManagePathId === null
    ? null
    : ($_COOKIE['gbowner_' . $messageManagePathId] ?? null);
$hasOwnerCookieHint = is_string($ownerCookieHint)
    && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $ownerCookieHint) === 1;
$isInteractiveUi = in_array($requestPath, ['/', '/index.php'], true)
    || $messagePathId !== null
    || $isArchiveMonth
    || ($messageManagePathId !== null && $hasOwnerCookieHint);
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
    $readOnlyRoute = $isApiV1 || $isSitemap || $isFeed || $isJsonFeed
        || $isPublicInfoPage || $isArchiveIndex;
    header('Allow: ' . ($readOnlyRoute ? 'GET, HEAD' : 'GET, HEAD, POST'));
    if ($isApiV1) {
        api_error_response(405, 'method_not_allowed', 'Only GET and HEAD are supported.');
    }
    abort_response(405, 'Method not allowed.');
}
if (!$isApiV1 && !$isSitemap && !$isFeed && !$isJsonFeed && !$isPublicInfoPage
    && !$isArchiveIndex && $messagePathId === null
    && $messageManagePathId === null
    && !$isArchiveMonth
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
    bool $noindex = false,
    bool $noTransform = false
): never
{
    $etag = '"' . hash('sha256', $body) . '"';
    header('Content-Type: ' . $contentType);
    header(
        'Cache-Control: public, max-age=30, stale-while-revalidate=60'
            . ($noTransform ? ', no-transform' : '')
    );
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

if ($isPublicInfoPage) {
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        abort_response(405, 'Method not allowed.');
    }
    if ($_GET !== []) {
        abort_response(400, 'Unsupported query parameter.');
    }
    $infoPages = public_info_pages();
    $infoPage = $infoPages[$publicInfoPageSlug];
    ob_start();
    require __DIR__ . '/public-info.php';
    $infoPageBody = ob_get_clean();
    if (!is_string($infoPageBody)) {
        abort_response(500, 'Unable to render page.');
    }
    public_document_response(
        $infoPageBody,
        'text/html; charset=utf-8',
        $method,
        noTransform: true
    );
}

if ($isArchiveIndex) {
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        abort_response(405, 'Method not allowed.');
    }
    if ($_GET !== []) {
        abort_response(400, 'Unsupported archive parameter.');
    }
    $archiveIndexMonths = public_archive_months($pdo);
    if ($archiveIndexMonths === []) {
        abort_response(404, 'Archive not found.');
    }
    ob_start();
    require __DIR__ . '/archive-index.php';
    $archiveIndexBody = ob_get_clean();
    if (!is_string($archiveIndexBody)) {
        abort_response(500, 'Unable to render archive.');
    }
    public_document_response(
        $archiveIndexBody,
        'text/html; charset=utf-8',
        $method,
        noTransform: true
    );
}

if ($isSitemap || $isFeed || $isJsonFeed) {
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
    if ($isJsonFeed) {
        public_document_response(
            public_json_feed($pdo),
            'application/feed+json; charset=utf-8',
            $method,
            true
        );
    }
    public_document_response(
        public_rss_xml($pdo),
        'application/rss+xml; charset=utf-8',
        $method,
        true
    );
}

// ---------------------------------------------------------------------------
// Private author management. Invalid capabilities deliberately look missing.
// ---------------------------------------------------------------------------
if ($messageManagePathId !== null) {
    if ($_GET !== []) {
        abort_response(400, 'Unsupported query parameter.');
    }
    $ownerToken = message_owner_cookie_token($messageManagePathId);
    $managedMessage = $ownerToken === null
        ? null
        : owned_message($pdo, $messageManagePathId, $ownerToken);
    if ($managedMessage === null) {
        if ($method === 'POST') {
            $ownerSubject = client_ip_hash('message-owner-remove');
            $retryAfter = rate_limit_retry_after($pdo, 'message-owner-remove', $ownerSubject);
            if ($retryAfter === 0) {
                $retryAfter = rate_limit_record_failure(
                    $pdo,
                    'message-owner-remove',
                    $ownerSubject,
                    10,
                    900,
                    900
                );
            }
            security_event(
                $pdo,
                'message.owner_remove',
                $retryAfter > 0 ? 'blocked' : 'failure',
                $ownerSubject,
                $messageManagePathId
            );
        }
        abort_response(404, 'Not found.');
    }

    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Type: text/html; charset=utf-8');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, noarchive');
    header('Referrer-Policy: same-origin');
    if ($method === 'POST') {
        if (!same_origin_request()
            || !csrf_valid()
            || !message_owner_csrf_valid($messageManagePathId, $ownerToken)) {
            security_event(
                $pdo,
                'message.owner_remove',
                'failure',
                client_ip_hash('message-owner-remove'),
                $messageManagePathId
            );
            abort_response(403, 'Unable to verify this request.');
        }
        if (string_input($_POST, 'action') !== 'owner_remove') {
            abort_response(400, 'Invalid form data.');
        }
        if (!withdraw_owned_message($pdo, $messageManagePathId, $ownerToken)) {
            security_event(
                $pdo,
                'message.owner_remove',
                'failure',
                client_ip_hash('message-owner-remove'),
                $messageManagePathId
            );
            abort_response(404, 'Not found.');
        }
        security_event($pdo, 'message.owner_remove', 'success', null, $messageManagePathId);
        message_owner_cookie_expire($messageManagePathId);
        flash_set('success', 'Your message was removed from the public guestbook.');
        redirect('/');
    }

    $ownerCsrf = message_owner_csrf_token($messageManagePathId, $ownerToken);
    $flash = flash_get();
    ob_start();
    require __DIR__ . '/message-manage.php';
    $manageBody = ob_get_clean();
    if (!is_string($manageBody)) {
        abort_response(500, 'Unable to render page.');
    }
    if ($method !== 'HEAD') {
        echo $manageBody;
    }
    exit;
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

    if (in_array($action, ['feature', 'unfeature'], true)) {
        $adminSubject = client_ip_hash('admin-action');
        $eventType = $action === 'feature' ? 'message.feature' : 'message.unfeature';
        if (empty($_SESSION['is_admin'])) {
            security_event($pdo, $eventType, 'blocked', $adminSubject);
            flash_set('error', 'Not authorised.');
            redirect('/');
        }

        $id = id_input($_POST, 'id');
        if ($id === null) {
            security_event($pdo, $eventType, 'failure', $adminSubject);
            flash_set('error', 'Invalid featured-message request.');
            redirect('/');
        }

        $changed = set_message_featured(
            $pdo,
            $id,
            $action === 'feature',
            admin_actor_hash()
        );
        security_event(
            $pdo,
            $eventType,
            $changed ? 'success' : 'failure',
            $adminSubject,
            $id
        );
        flash_set($changed ? 'success' : 'error', $changed
            ? ($action === 'feature' ? 'Featured message updated.' : 'Featured message removed.')
            : 'Only a published message can be changed, or it already has that state.');
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
        if (!message_owner_cookie_set($result['id'], $result['owner_token'])) {
            throw new RuntimeException('Unable to set message ownership cookie');
        }
        flash_set('success', $result['status'] === 'pending'
            ? 'Message received and queued for moderator review.'
            : 'Message posted!');
        redirect(message_manage_path($result['id']));
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
$adminReviewStatus = 'non_public';
$adminReviewQuery = '';
$adminReviewSort = 'newest';
$adminReviewPage = 1;
$adminReviewPerPage = 10;
$adminReviewParameterKeys = ['admin_status', 'admin_q', 'admin_sort', 'admin_page'];
$hasAdminReviewParameters = false;
foreach ($adminReviewParameterKeys as $adminParameterKey) {
    if (array_key_exists($adminParameterKey, $_GET)) {
        $hasAdminReviewParameters = true;
        break;
    }
}
if ($hasAdminReviewParameters && !$isAdmin) {
    abort_response(400, 'Unsupported query parameter.');
}
if ($isAdmin) {
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: same-origin');

    if (array_key_exists('admin_status', $_GET)) {
        $adminReviewStatusInput = string_input($_GET, 'admin_status');
        if ($adminReviewStatusInput === null
            || !in_array(
                $adminReviewStatusInput,
                array_merge(['non_public', 'all'], moderation_states()),
                true
            )) {
            abort_response(400, 'Invalid admin status filter.');
        }
        $adminReviewStatus = $adminReviewStatusInput;
    }
    if (array_key_exists('admin_q', $_GET)) {
        $adminReviewQueryInput = string_input($_GET, 'admin_q');
        if ($adminReviewQueryInput === null
            || mb_strlen($adminReviewQueryInput, 'UTF-8') > 100) {
            abort_response(400, 'Invalid admin search.');
        }
        $adminReviewQuery = trim($adminReviewQueryInput);
    }
    if (array_key_exists('admin_sort', $_GET)) {
        $adminReviewSortInput = string_input($_GET, 'admin_sort');
        if ($adminReviewSortInput === null
            || !in_array($adminReviewSortInput, ['newest', 'oldest', 'risk'], true)) {
            abort_response(400, 'Invalid admin sort.');
        }
        $adminReviewSort = $adminReviewSortInput;
    }
    if (array_key_exists('admin_page', $_GET)) {
        $adminReviewPageInput = string_input($_GET, 'admin_page');
        if ($adminReviewPageInput === null
            || preg_match('/\A[1-9][0-9]{0,5}\z/D', $adminReviewPageInput) !== 1) {
            abort_response(400, 'Invalid admin page.');
        }
        $adminReviewPage = (int) $adminReviewPageInput;
    }
}
$adminReviewFiltersActive = $adminReviewStatus !== 'non_public'
    || $adminReviewQuery !== ''
    || $adminReviewSort !== 'newest'
    || $adminReviewPage !== 1;

if ($isArchiveMonth) {
    foreach (array_keys($_GET) as $queryKey) {
        if (!is_string($queryKey) || $queryKey !== 'page') {
            abort_response(400, 'Unsupported archive parameter.');
        }
    }
}
$rawQuery = string_input($_GET, 'q');
if ($rawQuery === null && array_key_exists('q', $_GET)) {
    abort_response(400, 'Invalid query.');
}
$q      = mb_substr(trim($rawQuery ?? ''), 0, 100);
$messageId = $messagePathId;
$archiveDate = null;
$archiveLabel = null;
$archiveStart = null;
$archiveEnd = null;
if ($isArchiveMonth) {
    $archiveDate = DateTimeImmutable::createFromFormat(
        '!Y-m',
        sprintf('%04d-%02d', $archiveYear, $archiveMonth),
        new DateTimeZone('UTC')
    );
    if (!$archiveDate instanceof DateTimeImmutable) {
        abort_response(404, 'Archive not found.');
    }
    $archiveLabel = $archiveDate->format('F Y');
    $archiveStart = $archiveDate->format('Y-m-01 00:00:00');
    $archiveEnd = $archiveDate->modify('first day of next month')->format('Y-m-01 00:00:00');
}
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
if ($isArchiveMonth) {
    $whereParts[] = 'm.created_at >= :archive_start';
    $whereParts[] = 'm.created_at < :archive_end';
    $params[':archive_start'] = $archiveStart;
    $params[':archive_end'] = $archiveEnd;
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
if (($messageId !== null || $isArchiveMonth) && $total === 0) {
    abort_response(404, $messageId !== null ? 'Message not found.' : 'Archive not found.');
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
    : ($messageId === null
        ? ($isArchiveMonth
            ? 'm.created_at DESC, m.id DESC'
            : 'CASE WHEN m.featured_at IS NULL THEN 1 ELSE 0 END, '
                . 'm.featured_at DESC, m.created_at DESC, m.id DESC')
        : 'm.created_at DESC, m.id DESC');
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
$hasFeaturedMessage = false;
foreach ($messages as $message) {
    if ($message['featured_at'] !== null) {
        $hasFeaturedMessage = true;
        break;
    }
}
$archiveMonths = public_archive_months($pdo, 12);

$moderatedMessages = [];
$moderatedMessageTotal = 0;
$moderatedMessagePages = 1;
$openReports = [];
$adminAuditEvents = [];
$securityEvents = [];
$moderationCounts = array_fill_keys(moderation_states(), 0);
$openReportTotal = 0;
$adminOperations = [
    'pending_total' => 0,
    'high_risk_pending' => 0,
    'oldest_pending_at' => null,
    'open_reports' => 0,
    'oldest_report_at' => null,
    'security_exception_total' => 0,
    'security_activity' => [],
    'active_throttle_total' => 0,
    'active_throttles' => [],
];
if ($isAdmin) {
    $adminOperations = admin_operations_snapshot($pdo);
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM messages GROUP BY status') as $row) {
        if (array_key_exists((string) $row['status'], $moderationCounts)) {
            $moderationCounts[(string) $row['status']] = (int) $row['total'];
        }
    }
    try {
        $adminReview = admin_review_messages(
            $pdo,
            $adminReviewStatus,
            $adminReviewQuery,
            $adminReviewSort,
            $adminReviewPage,
            $adminReviewPerPage
        );
    } catch (OutOfRangeException) {
        abort_response(404, 'Admin review page not found.');
    }
    $moderatedMessages = $adminReview['items'];
    $moderatedMessageTotal = $adminReview['total'];
    $moderatedMessagePages = $adminReview['pages'];
    $openReports = $pdo->query(
        "SELECT r.id, r.message_id, r.category, r.created_at,
                m.author, m.content, m.status AS message_status
         FROM abuse_reports AS r
         JOIN messages AS m ON m.id = r.message_id
         WHERE r.status = 'open'
         ORDER BY r.created_at ASC, r.id ASC LIMIT 30"
    )->fetchAll();
    $openReportTotal = $adminOperations['open_reports'];
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
            'featured'   => $m['featured_at'] !== null,
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
} elseif ($isArchiveMonth) {
    $canonicalPath = public_archive_path($archiveYear, $archiveMonth, $page);
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
$socialImageUrl = APP_ORIGIN . asset('assets/social-card.png');
$socialImageAlt = 'An open guestbook surrounded by friendly message cards.';
if ($isAdmin) {
    $pageTitle = 'Admin workspace · ' . $siteName;
    $pageDescription = 'Private moderation and audit workspace.';
} elseif (is_array($primaryMessage)) {
    $primaryAuthor = (string) ($primaryMessage['author'] ?: 'Anonymous');
    $pageTitle = 'Message by ' . seo_excerpt($primaryAuthor, 60) . ' · ' . $siteName;
    $pageDescription = seo_excerpt((string) $primaryMessage['content']);
} elseif ($q !== '') {
    $pageTitle = 'Search results · ' . $siteName;
    $pageDescription = 'Search results from the public Micuțu guestbook.';
} elseif ($isArchiveMonth) {
    $pageTitle = $archiveLabel . ' archive · ' . $siteName;
    $pageDescription = 'Public guestbook messages from ' . $archiveLabel . '.';
} elseif ($page > 1) {
    $pageTitle = 'Guestbook archive · Page ' . $page . ' · Micuțu';
    $pageDescription = 'Older public messages in the Micuțu guestbook.';
} else {
    $pageTitle = 'Micuțu Guestbook · Leave a public message';
    $pageDescription = 'A small public guestbook for messages, thoughts and hellos.';
}
$robotsPolicy = $isAdmin
    ? 'noindex, nofollow, noarchive'
    : ($q !== ''
        ? 'noindex, follow'
        : 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1');
$isCanonicalHome = $messageId === null && !$isArchiveMonth && $q === '' && $page === 1;
$feedTitle = $q !== ''
    ? 'Search results'
    : ($messageId !== null
        ? 'Message'
        : ($isArchiveMonth
            ? $archiveLabel . ' archive'
            : ($hasFeaturedMessage && $page === 1 ? 'Featured & recent posts' : 'Recent posts')));

/** Build a URL for the active listing while preserving its bounded filters. */
function feed_url(int $page, string $q, ?int $archiveYear, ?int $archiveMonth): string
{
    if ($archiveYear !== null && $archiveMonth !== null) {
        return public_archive_path($archiveYear, $archiveMonth, $page);
    }
    $params = array_filter(['q' => $q, 'page' => $page > 1 ? $page : null],
        static fn($v) => $v !== null && $v !== '');
    return '/' . ($params ? '?' . http_build_query($params) : '');
}

/** Build a same-origin URL for the private moderation workspace. */
function admin_review_url(string $status, string $query, string $sort, int $page): string
{
    $params = [];
    if ($status !== 'non_public') {
        $params['admin_status'] = $status;
    }
    if ($query !== '') {
        $params['admin_q'] = $query;
    }
    if ($sort !== 'newest') {
        $params['admin_sort'] = $sort;
    }
    if ($page > 1) {
        $params['admin_page'] = $page;
    }
    return '/' . ($params === [] ? '' : '?' . http_build_query($params))
        . '#moderation-title';
}
$previousPageUrl = $page > 1
    ? APP_ORIGIN . feed_url($page - 1, $q, $archiveYear, $archiveMonth)
    : null;
$nextPageUrl = $page < $pages
    ? APP_ORIGIN . feed_url($page + 1, $q, $archiveYear, $archiveMonth)
    : null;

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
$adminReviewStatusLabels = ['non_public' => 'All non-public', 'all' => 'All states']
    + $statusLabels;
$adminReviewSortLabels = [
    'newest' => 'Newest activity',
    'oldest' => 'Oldest activity',
    'risk' => 'Highest review score',
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
    <meta property="og:type" content="<?php echo is_array($primaryMessage) ? 'article' : 'website'; ?>">
    <meta property="og:site_name" content="<?php echo e($siteName); ?>">
    <meta property="og:locale" content="en_US">
    <meta property="og:title" content="<?php echo e($pageTitle); ?>">
    <meta property="og:description" content="<?php echo e($pageDescription); ?>">
    <meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
    <meta property="og:image" content="<?php echo e($socialImageUrl); ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?php echo e($socialImageAlt); ?>">
    <?php if (is_array($primaryMessage)): ?>
    <meta property="article:published_time" content="<?php echo e(utc_iso8601((string) $primaryMessage['created_at'])); ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo e($pageTitle); ?>">
    <meta name="twitter:description" content="<?php echo e($pageDescription); ?>">
    <meta name="twitter:image" content="<?php echo e($socialImageUrl); ?>">
    <meta name="twitter:image:alt" content="<?php echo e($socialImageAlt); ?>">
    <title><?php echo e($pageTitle); ?></title>
    <link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
    <?php if ($previousPageUrl !== null): ?>
    <link rel="prev" href="<?php echo e($previousPageUrl); ?>">
    <?php endif; ?>
    <?php if ($nextPageUrl !== null): ?>
    <link rel="next" href="<?php echo e($nextPageUrl); ?>">
    <?php endif; ?>
    <link rel="alternate" type="application/rss+xml" title="Micuțu Guestbook RSS" href="/feed.xml">
    <link rel="alternate" type="application/feed+json" title="Micuțu Guestbook JSON Feed" href="/feed.json">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml" sizes="any">
    <link rel="stylesheet" href="<?php echo e(asset('assets/style.css')); ?>">
    <script src="<?php echo e(asset('assets/theme-init.js')); ?>"></script>
    <script defer src="<?php echo e(asset('assets/app.js')); ?>"></script>
    <?php if (ANALYTICS_WEBSITE_ID !== '' && !$isAdmin): ?>
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

        <?php if ($messageId !== null || $isArchiveMonth): ?>
        <nav class="breadcrumbs" aria-label="Breadcrumb"
             itemscope itemtype="https://schema.org/BreadcrumbList">
            <ol>
                <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                    <a itemprop="item" href="/"><span itemprop="name">Guestbook</span></a>
                    <meta itemprop="position" content="1">
                </li>
                <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem"
                    aria-current="page">
                    <span itemprop="name"><?php echo e($messageId !== null ? 'Message #' . $messageId : $archiveLabel . ' archive'); ?></span>
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

        <?php if ($archiveMonths !== []): ?>
        <nav class="archive-browser" aria-labelledby="archive-title">
            <div class="archive-heading">
                <h2 id="archive-title">Browse the archive</h2>
                <a href="/archive">View full archive</a>
            </div>
            <ul class="archive-list">
                <?php foreach ($archiveMonths as $monthSummary): ?>
                    <?php
                    $monthKey = (string) $monthSummary['archive_month'];
                    $monthDate = DateTimeImmutable::createFromFormat(
                        '!Y-m',
                        $monthKey,
                        new DateTimeZone('UTC')
                    );
                    if (!$monthDate instanceof DateTimeImmutable) {
                        continue;
                    }
                    $monthYear = (int) $monthDate->format('Y');
                    $monthNumber = (int) $monthDate->format('m');
                    $monthIsCurrent = $isArchiveMonth
                        && $archiveYear === $monthYear
                        && $archiveMonth === $monthNumber;
                    ?>
                <li>
                    <a class="archive-link<?php echo $monthIsCurrent ? ' is-current' : ''; ?>"
                       href="<?php echo e(public_archive_path($monthYear, $monthNumber)); ?>"
                       <?php echo $monthIsCurrent ? 'aria-current="page"' : ''; ?>>
                        <time datetime="<?php echo e($monthKey); ?>"><?php echo e($monthDate->format('F Y')); ?></time>
                        <span><?php echo (int) $monthSummary['message_count']; ?> message<?php echo (int) $monthSummary['message_count'] === 1 ? '' : 's'; ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php endif; ?>

        <section>
            <div class="feed-head">
                <h2 class="section-title"><?php echo icon('history'); ?> <?php echo e($feedTitle); ?></h2>
                <form method="GET" action="/" class="search" role="search">
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
                        <article class="msg<?php echo $msg['featured_at'] !== null ? ' is-featured' : ''; ?>" id="m<?php echo (int) $msg['id']; ?>"<?php echo $isPrimaryPosting ? ' itemscope itemtype="https://schema.org/DiscussionForumPosting"' : ''; ?>>
                            <?php if ($isPrimaryPosting): ?>
                            <meta itemprop="mainEntityOfPage" content="<?php echo e($canonicalUrl); ?>">
                            <link itemprop="url" href="<?php echo e($canonicalUrl); ?>">
                            <link itemprop="image" href="<?php echo e($socialImageUrl); ?>">
                            <?php endif; ?>
                            <?php if ($msg['featured_at'] !== null): ?>
                            <p class="featured-badge"><?php echo icon('star'); ?> Featured message</p>
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
                            <div class="admin-message-controls">
                            <form method="POST" class="feature-control"<?php echo $msg['featured_at'] === null ? ' data-confirm="Feature this message? It will replace the current featured message."' : ''; ?>>
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="<?php echo $msg['featured_at'] !== null ? 'unfeature' : 'feature'; ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $msg['id']; ?>">
                                <button class="btn secondary compact" type="submit">
                                    <?php echo icon('star'); ?>
                                    <?php echo $msg['featured_at'] !== null ? 'Remove feature' : 'Feature message'; ?>
                                </button>
                            </form>
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
                            </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($pages > 1): ?>
                <nav class="pager" aria-label="Pagination">
                    <?php if ($page > 1): ?>
                        <a class="pager-btn" href="<?php echo e(feed_url($page - 1, $q, $archiveYear, $archiveMonth)); ?>" rel="prev">&larr; Newer</a>
                    <?php else: ?>
                        <span class="pager-btn disabled">&larr; Newer</span>
                    <?php endif; ?>
                    <span class="pager-info">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                    <?php if ($page < $pages): ?>
                        <a class="pager-btn" href="<?php echo e(feed_url($page + 1, $q, $archiveYear, $archiveMonth)); ?>" rel="next">Older &rarr;</a>
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

            <section class="operations-overview" aria-labelledby="operations-overview-title">
                <div class="operations-overview-head">
                    <h3 id="operations-overview-title">Operational overview</h3>
                    <span>Private aggregates · rolling 24 hours</span>
                </div>
                <div class="operations-stats">
                    <div<?php echo $adminOperations['high_risk_pending'] > 0 ? ' class="has-attention"' : ''; ?>>
                        <span>High-risk pending</span>
                        <strong><?php echo $adminOperations['high_risk_pending']; ?></strong>
                        <small>Score ≥ <?php echo RISK_REVIEW_THRESHOLD; ?></small>
                    </div>
                    <div<?php echo $adminOperations['pending_total'] > 0 ? ' class="has-attention"' : ''; ?>>
                        <span>Oldest pending</span>
                        <strong><?php echo $adminOperations['oldest_pending_at'] === null
                            ? 'Clear'
                            : e(time_ago($adminOperations['oldest_pending_at'])); ?></strong>
                        <small><?php echo $adminOperations['pending_total']; ?> waiting</small>
                    </div>
                    <div<?php echo $adminOperations['open_reports'] > 0 ? ' class="has-attention"' : ''; ?>>
                        <span>Oldest report</span>
                        <strong><?php echo $adminOperations['oldest_report_at'] === null
                            ? 'Clear'
                            : e(time_ago('@' . $adminOperations['oldest_report_at'])); ?></strong>
                        <small><?php echo $adminOperations['open_reports']; ?> open</small>
                    </div>
                    <div<?php echo $adminOperations['security_exception_total'] > 0 ? ' class="has-attention"' : ''; ?>>
                        <span>Security exceptions</span>
                        <strong><?php echo $adminOperations['security_exception_total']; ?></strong>
                        <small>Failure/blocked · 24h</small>
                    </div>
                    <div<?php echo $adminOperations['active_throttle_total'] > 0 ? ' class="has-attention"' : ''; ?>>
                        <span>Active throttles</span>
                        <strong><?php echo $adminOperations['active_throttle_total']; ?></strong>
                        <small>Aggregated subjects</small>
                    </div>
                </div>
            </section>

            <details class="admin-panel"<?php echo $adminOperations['security_exception_total'] > 0
                || $adminOperations['active_throttle_total'] > 0 ? ' open' : ''; ?>>
                <summary>Suspicious activity · <?php echo $adminOperations['security_exception_total']; ?> exception<?php echo $adminOperations['security_exception_total'] === 1 ? '' : 's'; ?> · <?php echo $adminOperations['active_throttle_total']; ?> active throttle<?php echo $adminOperations['active_throttle_total'] === 1 ? '' : 's'; ?></summary>
                <p class="activity-privacy">This summary uses rolling 24-hour aggregates and shows up to 20 groups per list. It does not select visitor hashes, message identifiers, names or content.</p>
                <div class="activity-columns">
                    <section aria-labelledby="security-activity-title">
                        <h3 id="security-activity-title">Failure and blocked event groups</h3>
                        <?php if ($adminOperations['security_activity']): ?>
                        <ol class="activity-list">
                            <?php foreach ($adminOperations['security_activity'] as $activity): ?>
                            <li>
                                <code><?php echo e($activity['event_type']); ?></code>
                                <span class="audit-outcome <?php echo e($activity['outcome']); ?>"><?php echo e($activity['outcome']); ?></span>
                                <strong><?php echo $activity['total']; ?></strong>
                                <time datetime="<?php echo e(gmdate('c', $activity['last_seen'])); ?>">last <?php echo e(time_ago('@' . $activity['last_seen'])); ?></time>
                            </li>
                            <?php endforeach; ?>
                        </ol>
                        <?php else: ?>
                        <p class="panel-empty">No failure or blocked events in the last 24 hours.</p>
                        <?php endif; ?>
                    </section>
                    <section aria-labelledby="active-throttles-title">
                        <h3 id="active-throttles-title">Active throttle scopes</h3>
                        <?php if ($adminOperations['active_throttles']): ?>
                        <ol class="activity-list throttle-list">
                            <?php foreach ($adminOperations['active_throttles'] as $throttle): ?>
                            <li>
                                <code><?php echo e($throttle['scope']); ?></code>
                                <strong><?php echo $throttle['total']; ?></strong>
                                <span>active</span>
                            </li>
                            <?php endforeach; ?>
                        </ol>
                        <?php else: ?>
                        <p class="panel-empty">No subjects are currently throttled.</p>
                        <?php endif; ?>
                    </section>
                </div>
            </details>

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

            <details class="admin-panel"<?php echo $moderatedMessages || $adminReviewFiltersActive ? ' open' : ''; ?>>
                <summary>Review messages · <?php echo $moderatedMessageTotal; ?> match<?php echo $moderatedMessageTotal === 1 ? '' : 'es'; ?></summary>
                <form class="admin-filters" method="GET" action="/" role="search" aria-label="Filter moderation workspace">
                    <label class="admin-filter-search" for="admin-review-query">
                        <span>Search names and messages</span>
                        <input class="input" id="admin-review-query" name="admin_q" type="search"
                               value="<?php echo e($adminReviewQuery); ?>" maxlength="100"
                               autocomplete="off" placeholder="Name or message text">
                    </label>
                    <label for="admin-review-status">
                        <span>Status</span>
                        <select class="select" id="admin-review-status" name="admin_status">
                            <?php foreach ($adminReviewStatusLabels as $value => $label): ?>
                            <option value="<?php echo e($value); ?>"<?php echo $adminReviewStatus === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="admin-review-sort">
                        <span>Order</span>
                        <select class="select" id="admin-review-sort" name="admin_sort">
                            <?php foreach ($adminReviewSortLabels as $value => $label): ?>
                            <option value="<?php echo e($value); ?>"<?php echo $adminReviewSort === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="admin-filter-actions">
                        <button class="btn secondary compact" type="submit">Apply filters</button>
                        <?php if ($adminReviewFiltersActive): ?>
                        <a class="linkbtn compact" href="/#moderation-title">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php
                    $adminReviewFirst = $moderatedMessageTotal === 0
                        ? 0
                        : (($adminReviewPage - 1) * $adminReviewPerPage) + 1;
                    $adminReviewLast = $moderatedMessageTotal === 0
                        ? 0
                        : min(
                            $moderatedMessageTotal,
                            $adminReviewFirst + count($moderatedMessages) - 1
                        );
                ?>
                <p class="admin-result-count" aria-live="polite">
                    Showing <?php echo $adminReviewFirst; ?>–<?php echo $adminReviewLast; ?> of <?php echo $moderatedMessageTotal; ?>.
                    Search stays on this server and analytics is disabled for authenticated admin views.
                </p>
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
                            <?php if ($moderated['owner_withdrawn_at'] !== null): ?>
                            <p class="owner-withdrawn-note">Withdrawn by its author · cannot be republished.</p>
                            <?php else: ?>
                            <div class="review-actions moderation-actions">
                            <?php if ($moderated['status'] !== 'published'): ?>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="moderate">
                                <input type="hidden" name="id" value="<?php echo (int) $moderated['id']; ?>">
                                <input type="hidden" name="status" value="published">
                                <input type="hidden" name="reason" value="restored">
                                <button class="btn secondary compact" type="submit"><?php echo icon('restore'); ?> Publish</button>
                            </form>
                            <?php endif; ?>
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
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="panel-empty">No messages match these review filters.</p>
                <?php endif; ?>
                <?php if ($moderatedMessagePages > 1): ?>
                <nav class="admin-pager" aria-label="Moderation results pages">
                    <?php if ($adminReviewPage > 1): ?>
                    <a class="pager-btn" rel="prev" href="<?php echo e(admin_review_url(
                        $adminReviewStatus,
                        $adminReviewQuery,
                        $adminReviewSort,
                        $adminReviewPage - 1
                    )); ?>">&larr; Previous page</a>
                <?php else: ?>
                    <span class="pager-btn disabled">&larr; Previous page</span>
                    <?php endif; ?>
                    <span class="pager-info">Page <?php echo $adminReviewPage; ?> of <?php echo $moderatedMessagePages; ?></span>
                    <?php if ($adminReviewPage < $moderatedMessagePages): ?>
                    <a class="pager-btn" rel="next" href="<?php echo e(admin_review_url(
                        $adminReviewStatus,
                        $adminReviewQuery,
                        $adminReviewSort,
                        $adminReviewPage + 1
                    )); ?>">Next page &rarr;</a>
                <?php else: ?>
                    <span class="pager-btn disabled">Next page &rarr;</span>
                    <?php endif; ?>
                </nav>
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
            <p>Follow published messages through <a href="/feed.xml">RSS</a> or <a href="/feed.json">JSON Feed</a>. Developers can use the <a href="/api/v1/messages">sessionless JSON API</a> with its machine-readable <a href="/openapi.json">OpenAPI contract</a>.</p>
            <p>Read more <a href="/about">about the project</a>, its <a href="/privacy">privacy and data use</a>, and the <a href="/moderation">moderation policy</a>. Security concerns can be reported using the contact route in <a href="/.well-known/security.txt">security.txt</a>.</p>
        </section>

        <details class="privacy" id="privacy">
            <summary>Privacy, moderation &amp; data use</summary>
            <p>Messages and names are public. A keyed, non-reversible network identifier is used for abuse prevention and detached from messages after 24 hours. Submissions may be queued for human review using fixed signals such as recent duplication, link density and invisible Unicode controls; no message is automatically deleted. The keyed duplicate fingerprint is also removed after 24 hours. Security events are retained for up to 90 days.</p>
            <p>Abuse reports contain only the selected category and message number. They do not store a reporter identifier or free-text explanation. Reviewed reports are deleted after 90 days; minimal append-only admin actions remain for accountability.</p>
            <p>Draft saving is optional. When enabled, the name and message stay only in this browser's local storage, expire after 24 hours and are removed after a successful submission. Previewing never sends additional data or renders message HTML.</p>
            <p>New submissions receive a private management page backed by a random HttpOnly, SameSite Strict cookie scoped to that path; only a keyed digest is stored in the database. The posting browser can withdraw its message from every application-controlled public surface, after which ordinary moderation cannot republish it. This is a soft withdrawal, not complete statutory erasure: audit records, backups and external copies can remain.</p>
            <?php if (ANALYTICS_WEBSITE_ID !== '' && !$isAdmin): ?>
            <p>This deployment loads a reviewed, versioned analytics tracker from this site and sends aggregate page and performance data to the self-hosted <code>analytics.micutu.com</code> endpoint. It respects Do Not Track and excludes query strings, URL fragments and form content.</p>
            <?php elseif (ANALYTICS_WEBSITE_ID !== ''): ?>
            <p>Analytics is disabled while the private admin workspace is authenticated.</p>
            <?php else: ?>
            <p>Optional analytics is disabled on this deployment.</p>
            <?php endif; ?>
            <p>The standalone <a href="/privacy">privacy page</a> documents retention, backups and the current limits of the removal workflow.</p>
        </details>

        <footer class="site-footer">
            <nav aria-label="Site information">
                <a href="/archive">Archive</a>
                <a href="/about">About</a>
                <a href="/privacy">Privacy</a>
                <a href="/moderation">Moderation</a>
                <a href="/feed.xml">RSS</a>
                <a href="/feed.json">JSON Feed</a>
                <a href="/api/v1/messages">API</a>
                <a href="/.well-known/security.txt">Security</a>
            </nav>
        </footer>
    </main>
</body>
</html>
