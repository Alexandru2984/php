#!/usr/bin/env php
<?php

declare(strict_types=1);

define('GUESTBOOK_APP', true);
define('APP_ROOT', dirname(__DIR__));
define('APP_SECRET', str_repeat('a', 64));
define('APP_ORIGIN', 'https://php.micutu.com');
define('AUTHOR_MAX', 60);
define('MESSAGE_MAX', 2000);
define('RATE_LIMIT_SECONDS', 15);
define('RATE_LIMIT_PER_HOUR', 12);
define('RISK_REVIEW_THRESHOLD', 40);
define('MESSAGE_OWNER_COOKIE_SECONDS', 31536000);

$_SESSION = [];
$_POST = [];
$_SERVER = [];

require dirname(__DIR__) . '/session-policy.php';
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/api.php';

$tests = 0;
$failures = 0;

function check(bool $condition, string $description): void
{
    global $tests, $failures;
    $tests++;
    if ($condition) {
        fwrite(STDOUT, ".");
        return;
    }
    $failures++;
    fwrite(STDERR, "\nFAIL: {$description}\n");
}

$sessionNow = 2_000_000_000;
$validAdminSession = [
    'is_admin' => true,
    'admin_authenticated_at' => $sessionNow - 3600,
    'admin_last_seen_at' => $sessionNow - 300,
];
check(
    admin_session_is_current($validAdminSession, $sessionNow, 1800, 43200),
    'coherent admin session is current'
);
$idleBoundarySession = $validAdminSession;
$idleBoundarySession['admin_last_seen_at'] = $sessionNow - 1800;
check(
    admin_session_is_current($idleBoundarySession, $sessionNow, 1800, 43200),
    'admin session remains current at the idle boundary'
);
$idleExpiredSession = $idleBoundarySession;
$idleExpiredSession['admin_last_seen_at']--;
check(
    !admin_session_is_current($idleExpiredSession, $sessionNow, 1800, 43200),
    'admin session expires beyond the idle boundary'
);
$absoluteExpiredSession = $validAdminSession;
$absoluteExpiredSession['admin_authenticated_at'] = $sessionNow - 43201;
check(
    !admin_session_is_current($absoluteExpiredSession, $sessionNow, 1800, 43200),
    'admin session expires beyond the absolute boundary'
);
foreach ([
    'missing timestamps' => ['is_admin' => true],
    'numeric-string timestamp' => [
        'admin_authenticated_at' => (string) ($sessionNow - 600),
        'admin_last_seen_at' => $sessionNow - 300,
    ],
    'negative timestamp' => [
        'admin_authenticated_at' => -1,
        'admin_last_seen_at' => $sessionNow - 300,
    ],
    'future authentication' => [
        'admin_authenticated_at' => $sessionNow + 1,
        'admin_last_seen_at' => $sessionNow + 1,
    ],
    'future last-seen time' => [
        'admin_authenticated_at' => $sessionNow - 300,
        'admin_last_seen_at' => $sessionNow + 1,
    ],
    'reversed timestamps' => [
        'admin_authenticated_at' => $sessionNow - 100,
        'admin_last_seen_at' => $sessionNow - 200,
    ],
] as $case => $session) {
    check(
        !admin_session_is_current($session, $sessionNow, 1800, 43200),
        "admin session rejects {$case}"
    );
}

check(e('<script>"x"</script>') === '&lt;script&gt;&quot;x&quot;&lt;/script&gt;', 'HTML is escaped');
check(xml_e('<tag>&"') === '&lt;tag&gt;&amp;&quot;', 'XML is escaped');
check(
    hash_file('sha256', APP_ROOT . '/assets/analytics.js')
        === 'be444c289ac019af8486b50fe2bbf2fdb2890812fb945dc78940b6781a68ac52',
    'reviewed analytics tracker digest is pinned'
);
$socialImage = getimagesize(APP_ROOT . '/assets/social-card.png');
check(
    is_array($socialImage)
        && ($socialImage[0] ?? null) === 1200
        && ($socialImage[1] ?? null) === 630
        && ($socialImage['mime'] ?? null) === 'image/png'
        && hash_file('sha256', APP_ROOT . '/assets/social-card.png')
            === 'ec387ed5b4788647dd9ca9bdfef8f2f38cfe36c4393735f94eaa13fcb23de3e9',
    'social preview image dimensions, format and digest are pinned'
);
check(
    seo_excerpt("  A message\nwith   spacing  ") === 'A message with spacing',
    'SEO descriptions collapse whitespace'
);
check(
    seo_excerpt(str_repeat('a', 25), 20) === str_repeat('a', 19) . '…',
    'SEO descriptions are bounded without invalid Unicode truncation'
);
check(public_message_path(42) === '/messages/42', 'message path is stable and clean');
check(public_message_path(42, true) === '/messages/42#m42', 'message fragment matches its card');
check(message_manage_path(42) === '/messages/42/manage', 'private management path is stable and clean');
check(
    public_archive_path(2026, 10) === '/archive/2026/10'
        && public_archive_path(2026, 10, 3) === '/archive/2026/10?page=3',
    'calendar archive paths are stable, clean and paginated'
);
$infoPages = public_info_pages();
check(
    array_keys($infoPages) === ['about', 'privacy', 'moderation']
        && array_column($infoPages, 'path') === ['/about', '/privacy', '/moderation']
        && ($infoPages['about']['schema_type'] ?? null) === 'AboutPage'
        && ($infoPages['privacy']['schema_type'] ?? null) === 'WebPage'
        && count(array_unique(array_column($infoPages, 'title'))) === 3,
    'public information pages have unique canonical metadata and trusted schema types'
);
$invalidMessagePathRejected = false;
try {
    public_message_path(0);
} catch (InvalidArgumentException) {
    $invalidMessagePathRejected = true;
}
check($invalidMessagePathRejected, 'message path rejects invalid identifiers');
$invalidManagePathRejected = false;
try {
    message_manage_path(0);
} catch (InvalidArgumentException) {
    $invalidManagePathRejected = true;
}
check($invalidManagePathRejected, 'management path rejects invalid identifiers');
$invalidArchivePathRejected = false;
try {
    public_archive_path(2026, 13);
} catch (InvalidArgumentException) {
    $invalidArchivePathRejected = true;
}
check($invalidArchivePathRejected, 'archive path rejects invalid calendar values');
check(
    utc_iso8601('2026-10-23 08:00:00') === '2026-10-23T08:00:00Z',
    'database timestamps become RFC 3339 UTC values'
);

$firstToken = csrf_token();
check(strlen($firstToken) === 64 && ctype_xdigit($firstToken), 'CSRF token is 32 random bytes');
$_POST['csrf'] = $firstToken;
check(csrf_valid(), 'matching CSRF token is valid');
$secondToken = csrf_rotate();
check($secondToken !== $firstToken && !csrf_valid(), 'CSRF rotation invalidates the old token');
$ownerToken = message_owner_token_create();
check(
    strlen($ownerToken) === 43 && message_owner_token_valid($ownerToken),
    'message ownership capability is canonical 256-bit base64url'
);
check(
    strlen(message_owner_token_hash($ownerToken)) === 64
        && message_owner_token_hash($ownerToken) !== hash('sha256', $ownerToken),
    'message ownership capability is stored as a purpose-separated keyed digest'
);
$ownerCsrf = message_owner_csrf_token(42, $ownerToken);
$_POST['owner_csrf'] = $ownerCsrf;
check(message_owner_csrf_valid(42, $ownerToken), 'owner action binds session CSRF to the capability');
csrf_rotate();
check(!message_owner_csrf_valid(42, $ownerToken), 'owner action token expires with its browser session CSRF');
$_POST = [];

check(string_input(['value' => 'ok'], 'value') === 'ok', 'string input is accepted');
check(string_input(['value' => ['nested']], 'value') === null, 'array input is rejected');
check(positive_int_input(['page' => '42'], 'page') === 42, 'positive decimal is parsed');
check(positive_int_input(['page' => '1e3'], 'page', 7) === 7, 'numeric notation is rejected');
check(positive_int_input(['page' => ['2']], 'page', 7) === 7, 'nested integer is rejected');
check(id_input(['id' => '9223372036854775807'], 'id') === PHP_INT_MAX, '64-bit identifier is parsed');
check(id_input(['id' => '0'], 'id') === null, 'zero identifier is rejected');
check(
    id_list_input(['ids' => ['7', '42']], 'ids') === [7, 42],
    'identifier lists preserve explicit canonical selections'
);
check(
    id_list_input(['ids' => ['7', '7']], 'ids') === null
        && id_list_input(['ids' => ['7', ['8']]], 'ids') === null
        && id_list_input(['ids' => '7'], 'ids') === null,
    'identifier lists reject duplicates, nested values and scalar coercion'
);
check(
    id_list_input(['ids' => array_fill(0, 21, '7')], 'ids') === null,
    'identifier lists reject oversized batches before parsing values'
);
check(
    enum_input(['category' => 'privacy'], 'category', abuse_report_categories()) === 'privacy',
    'allowed enum input is accepted'
);
check(
    enum_input(['category' => ['privacy']], 'category', abuse_report_categories()) === null,
    'nested enum input is rejected'
);
check(
    enum_input(['category' => 'malware'], 'category', abuse_report_categories()) === null,
    'unknown enum input is rejected'
);

old_input_set(['author' => 'Alice', 'message' => 'Remember me']);
$oldInput = old_input_get();
check($oldInput === ['author' => 'Alice', 'message' => 'Remember me'], 'form input survives one redirect');
check(old_input_get() === [], 'preserved form input is one-shot');

$_SERVER['HTTP_ORIGIN'] = 'https://php.micutu.com';
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
check(same_origin_request(), 'configured origin is accepted');
$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
check(!same_origin_request(), 'foreign origin is rejected');
unset($_SERVER['HTTP_ORIGIN']);
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
check(!same_origin_request(), 'cross-site Fetch Metadata is rejected');
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'none';
check(same_origin_request(), 'non-browser clients may rely on CSRF validation');

$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
check(client_ip() === '2001:db8::1', 'valid IPv6 address is accepted');
$postingHash = client_ip_hash('posting');
$loginHash = client_ip_hash('admin-login');
check(strlen($postingHash) === 64 && $postingHash !== $loginHash, 'client hashes are keyed and purpose-separated');
$logicalAdmin = admin_actor_hash();
$_SERVER['REMOTE_ADDR'] = '2001:db8::2';
check(
    admin_actor_hash() === $logicalAdmin && admin_actor_hash() !== client_ip_hash('admin-action'),
    'permanent admin actor is stable and independent from network identity'
);
$_SERVER['REMOTE_ADDR'] = "not-an-ip\n";
check(client_ip() === '0.0.0.0', 'invalid remote address is rejected');

[$author, $content, $error] = validate_submission([
    'author' => "  Alice\x00  ",
    'message' => "Hello\r\n\r\n\r\n\r\nworld",
]);
check($error === null && $author === 'Alice', 'valid author is normalized');
check($content === "Hello\n\n\nworld", 'newlines and controls are normalized');

[$author, $content, $error, $signals] = validate_submission([
    'author' => "Ali\u{202E}ce",
    'message' => "safe\u{200B} text",
]);
check(
    $error === null
        && $author === 'Alice'
        && $content === 'safe text'
        && $signals === ['unicode_control'],
    'invisible Unicode controls are removed and signalled with a fixed code'
);

[, $content, $error] = validate_submission([
    'author' => 'Unicode fixture',
    'message' => "Cafe\u{0301}",
]);
check(
    $error === null && $content === "Caf\u{00E9}",
    'canonically equivalent Unicode is normalized to NFC'
);

[, , $error] = validate_submission(['author' => [], 'message' => 'hello']);
check($error !== null, 'nested author input is rejected');
[, , $error] = validate_submission(['author' => 'Alice', 'message' => []]);
check($error !== null, 'nested message input is rejected');
[, , $error] = validate_submission(['author' => '', 'message' => '   ']);
check($error !== null, 'blank message is rejected');
[, , $error] = validate_submission(['author' => str_repeat('a', 61), 'message' => 'hello']);
check($error !== null, 'overlong author is rejected');

$db = new PDO('sqlite::memory:');
$migrationFiles = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($migrationFiles, SORT_STRING);
foreach ($migrationFiles as $migrationFile) {
    $schema = file_get_contents($migrationFile);
    check($schema !== false && $db->exec($schema) !== false, basename($migrationFile) . ' executes');
}
$columns = $db->query('PRAGMA table_info(messages)')->fetchAll(PDO::FETCH_COLUMN, 1);
check(
    $columns === [
        'id', 'author', 'content', 'ip_hash', 'created_at', 'status', 'deleted_at',
        'moderation_reason', 'moderated_at', 'content_fingerprint', 'risk_score',
        'risk_flags', 'featured_at', 'feature_changed_at', 'owner_token_hash',
        'owner_withdrawn_at',
    ],
    'migrated schema has expected columns'
);
check(
    (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('admin_audit_events', 'abuse_reports')")->fetchColumn() === 2,
    'moderation audit and abuse-report tables exist'
);
check(
    (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'messages_fts'")->fetchColumn() === 1,
    'FTS5 search index exists'
);
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM sqlite_master
         WHERE (type = 'index' AND name = 'idx_messages_single_featured')
            OR (type = 'trigger' AND name IN (
                'messages_featured_public_after_insert',
                'messages_featured_public_after_update'
            ))"
    )->fetchColumn() === 3,
    'featured-message uniqueness and rollback-compatible cleanup exist'
);
check(
    (int) $db->query(
         "SELECT COUNT(*) FROM sqlite_master
         WHERE (type = 'index' AND name = 'idx_messages_owner_token_hash')
            OR (type = 'trigger' AND name IN (
                'messages_owner_withdrawal_no_publish',
                'messages_owner_withdrawal_no_insert_publish',
                'messages_owner_withdrawal_marker_immutable'
            ))"
    )->fetchColumn() === 4,
    'ownership capability uniqueness and irreversible-withdrawal guard exist'
);

$seoDb = new PDO('sqlite::memory:');
$seoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$seoDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ($migrationFiles as $migrationFile) {
    $seoDb->exec((string) file_get_contents($migrationFile));
}
$seoDb->exec(
    "INSERT INTO messages (author, content, created_at, status)
     VALUES ('Public author', 'Public & indexable', '2026-10-23 08:00:00', 'published'),
            ('Private author', 'Pending secret', '2026-10-23 09:00:00', 'pending')"
);
$seoDb->exec(
    "UPDATE messages
     SET featured_at = '2026-10-23 10:00:00',
         feature_changed_at = '2026-10-23 10:00:00'
     WHERE id = 1"
);
$sitemapFixture = public_sitemap_xml($seoDb);
check(
    str_contains($sitemapFixture, 'https://php.micutu.com/messages/1')
        && str_contains($sitemapFixture, '<loc>https://php.micutu.com/archive</loc>')
        && str_contains($sitemapFixture, 'https://php.micutu.com/archive/2026/10')
        && str_contains($sitemapFixture, 'https://php.micutu.com/about')
        && str_contains($sitemapFixture, 'https://php.micutu.com/privacy')
        && str_contains($sitemapFixture, 'https://php.micutu.com/moderation')
        && str_contains($sitemapFixture, '2026-10-23T10:00:00Z')
        && !str_contains($sitemapFixture, 'message=2')
        && !str_contains($sitemapFixture, 'Pending secret'),
    'sitemap contains canonical published permalinks and calendar archives only'
);
$archiveMonthFixture = public_archive_months($seoDb);
check(
    count($archiveMonthFixture) === 1
        && ($archiveMonthFixture[0]['archive_month'] ?? null) === '2026-10'
        && (int) ($archiveMonthFixture[0]['message_count'] ?? 0) === 1
        && ($archiveMonthFixture[0]['updated_at'] ?? null) === '2026-10-23 10:00:00'
        && substr_count($sitemapFixture, '<url>') === 7,
    'archive summaries and sitemap URL budget reserve static and published URLs'
);
$archivePlan = $seoDb->query(
    "EXPLAIN QUERY PLAN
     SELECT id FROM messages
     WHERE status = 'published'
       AND created_at >= '2026-10-01 00:00:00'
       AND created_at < '2026-11-01 00:00:00'
     ORDER BY created_at DESC, id DESC LIMIT 10"
)->fetchAll();
check(
    str_contains(
        implode(' ', array_map(static fn(array $row): string => (string) $row['detail'], $archivePlan)),
        'idx_messages_status_created'
    ),
    'calendar archive range uses the published timestamp index'
);
$rssFixture = public_rss_xml($seoDb);
check(
    str_contains($rssFixture, 'Public &amp; indexable')
        && str_contains($rssFixture, 'https://php.micutu.com/messages/1')
        && !str_contains($rssFixture, 'Pending secret'),
    'RSS contains escaped published records only'
);
$jsonFeedFixture = json_decode(public_json_feed($seoDb), true, 16, JSON_THROW_ON_ERROR);
check(
    ($jsonFeedFixture['version'] ?? null) === 'https://jsonfeed.org/version/1.1'
        && ($jsonFeedFixture['feed_url'] ?? null) === 'https://php.micutu.com/feed.json'
        && count($jsonFeedFixture['items'] ?? []) === 1
        && ($jsonFeedFixture['items'][0]['url'] ?? null) === 'https://php.micutu.com/messages/1'
        && ($jsonFeedFixture['items'][0]['content_text'] ?? null) === 'Public & indexable'
        && ($jsonFeedFixture['items'][0]['authors'][0]['name'] ?? null) === 'Public author'
        && !str_contains(json_encode($jsonFeedFixture), 'Pending secret'),
    'JSON Feed contains structured published records only'
);
check(
    simplexml_load_string($sitemapFixture) !== false
        && simplexml_load_string($rssFixture) !== false,
    'generated sitemap and RSS are valid XML'
);
check(
    fts5_query_from_text('  Alice, HELLO! Alice ') === '"alice"* AND "hello"*',
    'plain search text becomes deduplicated bounded prefix terms'
);
check(
    fts5_query_from_text('" OR author:* NEAR(') === '"or"* AND "author"* AND "near"*',
    'raw FTS operators and punctuation cannot reach MATCH'
);
check(fts5_query_from_text('!!!') === null, 'punctuation-only search has no executable terms');
check(
    substr_count((string) fts5_query_from_text('one two three four five six seven eight nine'), '"*') === 8,
    'search term count is bounded'
);
$db->exec("INSERT INTO messages (author, content) VALUES ('Ștefan', 'Salutări din București')");
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM messages_fts
         WHERE messages_fts MATCH '\"stef\"* AND \"bucuresti\"*'"
    )->fetchColumn() === 1,
    'FTS tokenizer supports diacritic-insensitive Unicode prefix search'
);
$ftsPlan = $db->prepare(
    "EXPLAIN QUERY PLAN
     SELECT m.id FROM messages_fts
     JOIN messages AS m ON m.id = messages_fts.rowid
     WHERE messages_fts MATCH :query AND m.status = 'published'"
);
$ftsPlan->execute([':query' => '"stef"*']);
$ftsPlanDetails = implode(' ', $ftsPlan->fetchAll(PDO::FETCH_COLUMN, 3));
check(str_contains($ftsPlanDetails, 'VIRTUAL TABLE INDEX'), 'search query uses the FTS virtual index');

$adminDefaultPage = admin_review_messages($seoDb, 'non_public', '', 'newest', 1, 10);
check(
    $adminDefaultPage['total'] === 1
        && count($adminDefaultPage['items']) === 1
        && $adminDefaultPage['items'][0]['author'] === 'Private author'
        && !array_key_exists('ip_hash', $adminDefaultPage['items'][0])
        && !array_key_exists('owner_token_hash', $adminDefaultPage['items'][0])
        && !array_key_exists('content_fingerprint', $adminDefaultPage['items'][0]),
    'admin review defaults to non-public rows with a minimal projection'
);
$adminSearchPage = admin_review_messages($seoDb, 'all', 'pending secret', 'risk', 1, 10);
check(
    $adminSearchPage['total'] === 1
        && $adminSearchPage['items'][0]['status'] === 'pending',
    'admin review searches all moderation states through bounded FTS terms'
);
$adminOldestPage = admin_review_messages($seoDb, 'all', '', 'oldest', 1, 1);
$adminSecondPage = admin_review_messages($seoDb, 'all', '', 'oldest', 2, 1);
check(
    $adminOldestPage['total'] === 2
        && $adminOldestPage['pages'] === 2
        && $adminOldestPage['items'][0]['author'] === 'Public author'
        && $adminSecondPage['items'][0]['author'] === 'Private author',
    'admin review pagination and oldest-first ordering are deterministic'
);
$invalidAdminReviewRejected = 0;
foreach ([
    ['unknown', '', 'newest', 1, 10],
    ['all', '', 'random', 1, 10],
    ['all', '', 'newest', 3, 1],
    ['all', str_repeat('x', 101), 'newest', 1, 10],
] as $invalidReview) {
    try {
        admin_review_messages($seoDb, ...$invalidReview);
    } catch (InvalidArgumentException | OutOfRangeException) {
        $invalidAdminReviewRejected++;
    }
}
check($invalidAdminReviewRejected === 4, 'admin review rejects invalid filters and pages');

$operationsNow = 1_800_000_000;
$snapshotSubjectA = str_repeat('b', 64);
$snapshotSubjectB = str_repeat('c', 64);
$seoDb->exec("UPDATE messages SET risk_score = 55 WHERE status = 'pending'");
$snapshotReport = $seoDb->prepare(
    "INSERT INTO abuse_reports (message_id, category, status, created_at)
     VALUES (1, 'privacy', 'open', :created)"
);
$snapshotReport->execute([':created' => $operationsNow - 7200]);
$snapshotEvent = $seoDb->prepare(
    "INSERT INTO security_events
        (event_type, outcome, subject_hash, related_id, created_at)
     VALUES (:event, :outcome, :subject, :related, :created)"
);
foreach ([
    ['admin.login', 'failure', $snapshotSubjectA, null, $operationsNow - 300],
    ['message.owner_remove', 'blocked', $snapshotSubjectB, 2, $operationsNow - 400],
    ['abuse.report', 'success', null, 1, $operationsNow - 200],
    ['admin.login', 'failure', $snapshotSubjectA, null, $operationsNow - 90000],
    ['admin.login', 'failure', $snapshotSubjectA, null, $operationsNow + 1],
] as [$eventType, $outcome, $eventSubject, $relatedId, $createdAt]) {
    $snapshotEvent->execute([
        ':event' => $eventType,
        ':outcome' => $outcome,
        ':subject' => $eventSubject,
        ':related' => $relatedId,
        ':created' => $createdAt,
    ]);
}
$snapshotLimit = $seoDb->prepare(
    "INSERT INTO rate_limits
        (scope, subject_hash, window_started_at, attempts, penalty_level,
         blocked_until, updated_at)
     VALUES (:scope, :subject, :window, 5, 1, :blocked, :updated)"
);
$snapshotLimit->execute([
    ':scope' => 'admin-login',
    ':subject' => $snapshotSubjectA,
    ':window' => $operationsNow - 600,
    ':blocked' => $operationsNow + 600,
    ':updated' => $operationsNow - 60,
]);
$snapshotLimit->execute([
    ':scope' => 'message-owner-remove',
    ':subject' => $snapshotSubjectB,
    ':window' => $operationsNow - 1200,
    ':blocked' => $operationsNow - 1,
    ':updated' => $operationsNow - 900,
]);
$operationsSnapshot = admin_operations_snapshot($seoDb, $operationsNow);
check(
    $operationsSnapshot['pending_total'] === 1
        && $operationsSnapshot['high_risk_pending'] === 1
        && $operationsSnapshot['oldest_pending_at'] === '2026-10-23 09:00:00'
        && $operationsSnapshot['open_reports'] === 1
        && $operationsSnapshot['oldest_report_at'] === $operationsNow - 7200,
    'admin operations snapshot aggregates queue and report health'
);
check(
    $operationsSnapshot['security_exception_total'] === 2
        && count($operationsSnapshot['security_activity']) === 2
        && array_column($operationsSnapshot['security_activity'], 'event_type')
            === ['admin.login', 'message.owner_remove'],
    'admin operations snapshot bounds security exceptions to failures in the past 24 hours'
);
$operationsJson = json_encode($operationsSnapshot, JSON_THROW_ON_ERROR);
check(
    $operationsSnapshot['active_throttle_total'] === 1
        && $operationsSnapshot['active_throttles']
            === [['scope' => 'admin-login', 'total' => 1]]
        && !str_contains($operationsJson, $snapshotSubjectA)
        && !str_contains($operationsJson, $snapshotSubjectB)
        && !str_contains($operationsJson, 'related_id'),
    'admin operations snapshot aggregates throttles without projecting identifiers'
);
$invalidOperationsTimeRejected = false;
try {
    admin_operations_snapshot($seoDb, -1);
} catch (InvalidArgumentException) {
    $invalidOperationsTimeRejected = true;
}
check($invalidOperationsTimeRejected, 'admin operations snapshot rejects an invalid clock');

$subject = hash('sha256', 'test-subject');
for ($attempt = 1; $attempt <= 4; $attempt++) {
    check(
        rate_limit_record_failure($db, 'admin-login', $subject, 5, 900, 900, 100) === 0,
        "attempt {$attempt} remains below the login threshold"
    );
}
check(
    rate_limit_record_failure($db, 'admin-login', $subject, 5, 900, 900, 100) === 900,
    'fifth failure starts the lockout'
);
$_SESSION = []; // A new cookie/session must not reset the database-backed limiter.
check(rate_limit_retry_after($db, 'admin-login', $subject, 101) === 899, 'lockout survives session reset');
rate_limit_clear($db, 'admin-login', $subject);
check(rate_limit_retry_after($db, 'admin-login', $subject, 101) === 0, 'successful login clears failures');

security_event($db, 'admin.login', 'failure', $subject, null, 100);
check(
    (int) $db->query('SELECT COUNT(*) FROM security_events')->fetchColumn() === 1,
    'security event is persisted'
);

$riskNow = 1800000000;
$firstSubmission = create_submission(
    $db,
    'Risk fixture',
    'A unique normal submission',
    $subject,
    [],
    null,
    $riskNow
);
check(
    $firstSubmission['status'] === 'published'
        && $firstSubmission['score'] === 0
        && $firstSubmission['flags'] === []
        && message_owner_token_valid($firstSubmission['owner_token']),
    'a normal submission publishes without risk flags'
);
check(
    (string) $db->query(
        'SELECT owner_token_hash FROM messages WHERE id = ' . (int) $firstSubmission['id']
    )->fetchColumn() === message_owner_token_hash($firstSubmission['owner_token']),
    'submission stores only the keyed ownership digest'
);
$duplicateSubmission = create_submission(
    $db,
    'Another person',
    'A unique normal submission',
    $subject,
    [],
    null,
    $riskNow + 1
);
check(
    $duplicateSubmission['status'] === 'pending'
        && $duplicateSubmission['score'] === 50
        && $duplicateSubmission['flags'] === ['duplicate'],
    'a recent keyed duplicate is queued instead of deleted'
);
check(
    submission_fingerprint("Cafe\u{0301}") === submission_fingerprint("Caf\u{00E9}"),
    'duplicate fingerprints collapse canonically equivalent Unicode'
);
$duplicateRow = $db->query(
    'SELECT status, moderation_reason, risk_score, risk_flags,
            length(content_fingerprint) AS fingerprint_length
     FROM messages WHERE id = ' . (int) $duplicateSubmission['id']
)->fetch(PDO::FETCH_ASSOC);
check(
    $duplicateRow !== false
        && $duplicateRow['status'] === 'pending'
        && $duplicateRow['moderation_reason'] === 'risk'
        && (int) $duplicateRow['risk_score'] === 50
        && json_decode((string) $duplicateRow['risk_flags'], true) === ['duplicate']
        && (int) $duplicateRow['fingerprint_length'] === 64,
    'queued submission stores only bounded score, fixed flags and keyed fingerprint'
);
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM security_events
         WHERE event_type = 'message.queued' AND subject_hash IS NULL"
    )->fetchColumn() === 1,
    'queued-submission event contains no visitor identifier'
);
$linkRisk = assess_submission_risk(
    $db,
    'Link fixture',
    'https://one.test https://two.test https://three.test',
    [],
    null,
    $riskNow + 2
);
check(
    $linkRisk['score'] === 70
        && $linkRisk['flags'] === ['many_links', 'link_heavy'],
    'three link-heavy URLs cross the human-review threshold with explainable flags'
);
$combinedRisk = assess_submission_risk(
    $db,
    'spam.example',
    '!!!!!!!!!!!!!!!!',
    [],
    1,
    $riskNow + 3
);
check(
    $combinedRisk['score'] === 50
        && $combinedRisk['flags'] === ['author_address', 'repeated_run', 'fast_submit'],
    'weak deterministic signals combine while fast submission cannot queue alone'
);
$unicodeRisk = assess_submission_risk(
    $db,
    'Unicode fixture',
    'cleaned text',
    ['unicode_control'],
    null,
    $riskNow + 4
);
check(
    $unicodeRisk['score'] === 45 && $unicodeRisk['flags'] === ['unicode_control'],
    'removed Unicode formatting controls still route the post to review'
);
$db->exec(
    "INSERT INTO messages (author, content, ip_hash, created_at)
     VALUES ('Bob', 'moderate me', 'temporary', '2026-10-21 10:00:00')"
);
$moderatedId = (int) $db->lastInsertId();
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM messages_fts WHERE messages_fts MATCH '\"bob\"* AND \"moderate\"*'"
    )->fetchColumn() === 1,
    'message insert trigger updates the FTS index'
);
$db->exec(
    "INSERT INTO messages (author, content) VALUES ('Search trigger', 'before update')"
);
$searchTriggerId = (int) $db->lastInsertId();
$db->exec(
    "UPDATE messages SET content = 'after update' WHERE id = {$searchTriggerId}"
);
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM messages_fts WHERE messages_fts MATCH '\"before\"*'"
    )->fetchColumn() === 0
        && (int) $db->query(
            "SELECT COUNT(*) FROM messages_fts WHERE messages_fts MATCH '\"after\"*'"
        )->fetchColumn() === 1,
    'message update trigger replaces indexed content'
);
$db->exec("DELETE FROM messages WHERE id = {$searchTriggerId}");
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM messages_fts WHERE messages_fts MATCH '\"after\"*'"
    )->fetchColumn() === 0,
    'message delete trigger removes indexed content'
);
check(
    set_message_featured($db, $moderatedId, true, $subject, 250),
    'admin can feature a published message atomically'
);
check(
    !set_message_featured($db, $moderatedId, true, $subject, 251),
    'featuring the current selection is an idempotent no-op'
);
check(
    !set_message_featured($db, (int) $duplicateSubmission['id'], true, $subject, 252),
    'a pending message cannot be featured'
);
check(
    set_message_featured($db, (int) $firstSubmission['id'], true, $subject, 260),
    'featuring another message replaces the previous selection'
);
check(
    (int) $db->query('SELECT COUNT(*) FROM messages WHERE featured_at IS NOT NULL')->fetchColumn() === 1
        && (int) $db->query(
            'SELECT id FROM messages WHERE featured_at IS NOT NULL'
        )->fetchColumn() === (int) $firstSubmission['id'],
    'the database retains exactly one featured public message'
);
check(
    set_message_featured($db, (int) $firstSubmission['id'], false, $subject, 270)
        && (int) $db->query(
            'SELECT COUNT(*) FROM messages WHERE featured_at IS NOT NULL'
        )->fetchColumn() === 0,
    'admin can reversibly remove the featured selection'
);
$db->exec(
    "INSERT INTO messages (author, content, featured_at, feature_changed_at)
     VALUES ('Rollback fixture', 'Old release moderation',
             '1970-01-01 00:04:35', '1970-01-01 00:04:35')"
);
$rollbackFixtureId = (int) $db->lastInsertId();
$db->exec("UPDATE messages SET status = 'hidden' WHERE id = {$rollbackFixtureId}");
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM messages
         WHERE id = {$rollbackFixtureId} AND status = 'hidden' AND featured_at IS NULL"
    )->fetchColumn() === 1,
    'database cleanup keeps moderation compatible with the preceding release'
);
check(
    set_message_featured($db, $moderatedId, true, $subject, 280),
    'a public message can be featured again after removal'
);
check(
    create_abuse_report($db, $moderatedId, 'spam', 200),
    'a public message can be reported without storing a reporter identifier'
);
$report = $db->query('SELECT id, message_id, category, status FROM abuse_reports')->fetch(PDO::FETCH_ASSOC);
check(
    $report !== false
        && (int) $report['message_id'] === $moderatedId
        && $report['category'] === 'spam'
        && $report['status'] === 'open',
    'abuse report stores only the message, category and workflow state'
);
check(
    moderate_message($db, $moderatedId, 'hidden', 'spam', $subject, 300),
    'admin can atomically hide a published message'
);
$moderated = $db->query(
    "SELECT status, moderation_reason, moderated_at, ip_hash, featured_at,
            feature_changed_at
     FROM messages WHERE id = {$moderatedId}"
)->fetch(PDO::FETCH_ASSOC);
check(
    $moderated !== false
        && $moderated['status'] === 'hidden'
        && $moderated['moderation_reason'] === 'spam'
        && $moderated['moderated_at'] === '1970-01-01 00:05:00'
        && $moderated['ip_hash'] === null
        && $moderated['featured_at'] === null
        && $moderated['feature_changed_at'] === '1970-01-01 00:05:00',
    'moderation records reason/time, detaches identifiers and clears featured state'
);
check(
    !create_abuse_report($db, $moderatedId, 'spam', 301),
    'a non-public message cannot receive a new report'
);
check(
    moderate_message($db, $moderatedId, 'published', 'restored', $subject, 400),
    'admin can restore a hidden message'
);
check(
    review_abuse_report($db, (int) $report['id'], 'resolved', $subject, 500),
    'admin can resolve an open abuse report'
);
check(
    (int) $db->query('SELECT COUNT(*) FROM admin_audit_events')->fetchColumn() === 9
        && (int) $db->query(
            "SELECT COUNT(*) FROM admin_audit_events
             WHERE event_type = 'message.feature'"
        )->fetchColumn() === 3
        && (int) $db->query(
            "SELECT COUNT(*) FROM admin_audit_events
             WHERE event_type = 'message.unfeature'"
        )->fetchColumn() === 3,
    'moderation, featured changes and report reviews append audit events'
);
$appendOnly = false;
try {
    $db->exec("UPDATE admin_audit_events SET outcome = 'failure' WHERE id = 1");
} catch (PDOException) {
    $appendOnly = true;
}
check($appendOnly, 'admin audit events cannot be updated');
$appendOnly = false;
try {
    $db->exec('DELETE FROM admin_audit_events WHERE id = 1');
} catch (PDOException) {
    $appendOnly = true;
}
check($appendOnly, 'admin audit events cannot be deleted');

$db->exec(
    "INSERT INTO messages (author, content, status, featured_at, feature_changed_at)
     VALUES ('Bulk public', 'first bulk fixture', 'published',
             '1970-01-01 00:09:00', '1970-01-01 00:09:00'),
            ('Bulk pending', 'second bulk fixture', 'pending', NULL, NULL)"
);
$bulkPendingId = (int) $db->lastInsertId();
$bulkPublicId = $bulkPendingId - 1;
check(
    bulk_moderate_messages(
        $db,
        [$bulkPublicId, $bulkPendingId],
        'hidden',
        'policy',
        $subject,
        600
    ) === 2,
    'bulk moderation changes every selected message in one transaction'
);
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM messages
         WHERE id IN ({$bulkPublicId}, {$bulkPendingId})
           AND status = 'hidden' AND moderation_reason = 'policy'
           AND moderated_at = '1970-01-01 00:10:00' AND ip_hash IS NULL"
    )->fetchColumn() === 2
        && (int) $db->query(
            "SELECT COUNT(*) FROM admin_audit_events
             WHERE related_id IN ({$bulkPublicId}, {$bulkPendingId})
               AND event_type = 'message.hide' AND outcome = 'success'"
        )->fetchColumn() === 2
        && (int) $db->query(
            "SELECT COUNT(*) FROM admin_audit_events
             WHERE related_id = {$bulkPublicId} AND event_type = 'message.unfeature'"
        )->fetchColumn() === 1,
    'bulk moderation applies privacy cleanup and per-message append-only audit'
);
$db->exec(
    "INSERT INTO messages (author, content, status)
     VALUES ('Bulk rollback', 'must remain public', 'published')"
);
$bulkRollbackId = (int) $db->lastInsertId();
check(
    bulk_moderate_messages(
        $db,
        [$bulkRollbackId, $bulkPublicId],
        'hidden',
        'policy',
        $subject,
        601
    ) === 0
        && $db->query(
            "SELECT status FROM messages WHERE id = {$bulkRollbackId}"
        )->fetchColumn() === 'published',
    'one ineligible selection rolls back the complete bulk transition'
);
check(
    bulk_moderate_messages(
        $db,
        [$bulkRollbackId, PHP_INT_MAX],
        'spam',
        'spam',
        $subject,
        602
    ) === 0
        && $db->query(
            "SELECT status FROM messages WHERE id = {$bulkRollbackId}"
        )->fetchColumn() === 'published',
    'a missing selection prevents every bulk change'
);
$bulkPublicationRejected = false;
try {
    bulk_moderate_messages(
        $db,
        [$bulkRollbackId],
        'published',
        'restored',
        $subject,
        603
    );
} catch (InvalidArgumentException) {
    $bulkPublicationRejected = true;
}
check($bulkPublicationRejected, 'bulk moderation cannot publish messages');

$ownedSubmission = create_submission(
    $db,
    'Owner fixture',
    'A separately owned message',
    $subject,
    [],
    null,
    $riskNow + 100
);
$ownedId = (int) $ownedSubmission['id'];
$ownedToken = $ownedSubmission['owner_token'];
$wrongOwnerToken = message_owner_token_create();
check(
    owned_message($db, $ownedId, $wrongOwnerToken) === null
        && !withdraw_owned_message($db, $ownedId, $wrongOwnerToken, 699),
    'wrong ownership capability reveals no private metadata and changes nothing'
);
$ownedMetadata = owned_message($db, $ownedId, $ownedToken);
check(
    $ownedMetadata !== null
        && (int) $ownedMetadata['id'] === $ownedId
        && $ownedMetadata['status'] === 'published'
        && !array_key_exists('owner_token_hash', $ownedMetadata)
        && !array_key_exists('content', $ownedMetadata),
    'valid capability returns only minimal private management metadata'
);
check(
    set_message_featured($db, $ownedId, true, $subject, 698)
        && withdraw_owned_message($db, $ownedId, $ownedToken, 700),
    'author can atomically withdraw a currently featured message'
);
$withdrawn = $db->query(
    "SELECT status, deleted_at, moderation_reason, moderated_at, owner_token_hash,
            owner_withdrawn_at, ip_hash, content_fingerprint, featured_at
     FROM messages WHERE id = {$ownedId}"
)->fetch(PDO::FETCH_ASSOC);
check(
    $withdrawn !== false
        && $withdrawn['status'] === 'deleted'
        && $withdrawn['deleted_at'] === '1970-01-01 00:11:40'
        && $withdrawn['moderation_reason'] === 'requested'
        && $withdrawn['moderated_at'] === '1970-01-01 00:11:40'
        && $withdrawn['owner_withdrawn_at'] === '1970-01-01 00:11:40'
        && $withdrawn['owner_token_hash'] === null
        && $withdrawn['ip_hash'] === null
        && $withdrawn['content_fingerprint'] === null
        && $withdrawn['featured_at'] === null,
    'withdrawal clears capabilities and temporary identifiers while hiding the message'
);
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM admin_audit_events
         WHERE event_type = 'message.delete' AND related_id = {$ownedId}
           AND previous_state = 'published' AND new_state = 'deleted'
           AND reason_code = 'requested'"
    )->fetchColumn() === 1,
    'author withdrawal appends a controlled accountability event'
);
check(
    !moderate_message($db, $ownedId, 'published', 'restored', $subject, 701)
        && !withdraw_owned_message($db, $ownedId, $ownedToken, 702),
    'withdrawn message cannot be restored or replayed through application helpers'
);
check(
    bulk_moderate_messages(
        $db,
        [$bulkRollbackId, $ownedId],
        'spam',
        'policy',
        $subject,
        703
    ) === 0
        && $db->query(
            "SELECT status FROM messages WHERE id = {$bulkRollbackId}"
        )->fetchColumn() === 'published',
    'an author-withdrawn selection rejects the complete bulk transition'
);
$directRestoreBlocked = false;
try {
    $db->exec("UPDATE messages SET status = 'published' WHERE id = {$ownedId}");
} catch (PDOException) {
    $directRestoreBlocked = true;
}
check($directRestoreBlocked, 'database blocks publication of an owner-withdrawn message');
$markerRemovalBlocked = false;
try {
    $db->exec("UPDATE messages SET owner_withdrawn_at = NULL WHERE id = {$ownedId}");
} catch (PDOException) {
    $markerRemovalBlocked = true;
}
check($markerRemovalBlocked, 'database keeps the owner-withdrawal marker immutable');

$db->exec(
    "INSERT INTO messages (author, content, created_at)
     VALUES ('API first', 'first page fixture', '2030-01-01 00:00:00'),
            ('API second', 'second page fixture', '2030-01-01 00:00:00')"
);
$apiSecondId = (int) $db->lastInsertId();
$db->exec(
    "UPDATE messages
     SET featured_at = '2030-01-01 01:00:00',
         feature_changed_at = '2030-01-01 01:00:00'
     WHERE id = {$apiSecondId}"
);
$db->exec(
    "INSERT INTO messages (author, content, created_at, status)
     VALUES ('API hidden', 'must never leak', '2031-01-01 00:00:00', 'hidden')"
);
$apiPageOne = api_list_messages($db, 1);
check(
    count($apiPageOne['data']) === 1
        && $apiPageOne['data'][0]['author'] === 'API second'
        && $apiPageOne['data'][0]['featured'] === true
        && $apiPageOne['pagination']['has_more'] === true
        && is_string($apiPageOne['pagination']['next_cursor']),
    'API page is bounded, ordered and exposes a next cursor'
);
$decodedCursor = api_cursor_decode($apiPageOne['pagination']['next_cursor']);
check(
    $decodedCursor['created_at'] === '2030-01-01 00:00:00'
        && is_int($decodedCursor['id']),
    'API cursor round-trips its stable ordering key'
);
$apiPageTwo = api_list_messages($db, 1, $decodedCursor);
check(
    count($apiPageTwo['data']) === 1
        && $apiPageTwo['data'][0]['author'] === 'API first'
        && $apiPageTwo['data'][0]['featured'] === false
        && !str_contains(json_encode($apiPageTwo), 'must never leak'),
    'API cursor advances without exposing moderated messages'
);
$cursorParts = explode('.', $apiPageOne['pagination']['next_cursor'], 2);
$cursorParts[1][0] = $cursorParts[1][0] === 'A' ? 'B' : 'A';
$tamperedCursorRejected = false;
try {
    api_cursor_decode(implode('.', $cursorParts));
} catch (InvalidArgumentException) {
    $tamperedCursorRejected = true;
}
check($tamperedCursorRejected, 'tampered API cursor is rejected');
check(
    api_etag_matches('"older", W/"current"', '"current"'),
    'If-None-Match accepts a weak match from a bounded tag list'
);
check(!api_etag_matches('"older"', '"current"'), 'non-matching ETag is rejected');
$db->exec(
    "INSERT INTO messages
        (author, content, ip_hash, content_fingerprint, owner_token_hash, created_at)
     VALUES ('Alice', 'old', 'pseudonymous', '" . str_repeat('a', 64) . "',
             '" . str_repeat('b', 64) . "',
             '1970-01-01 00:00:00')"
);
$pruned = prune_security_records($db, 100 + 367 * 86400);
check($pruned['security_events'] === 1, 'expired security events are pruned');
check($pruned['abuse_reports'] === 1, 'old reviewed abuse reports are pruned');
check($pruned['message_identifiers'] === 1, 'old message identifier is detached');
check($pruned['owner_capabilities'] === 1, 'expired ownership capability digest is detached');
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM messages
         WHERE content = 'old' AND ip_hash IS NULL AND content_fingerprint IS NULL
           AND owner_token_hash IS NULL"
    )->fetchColumn() === 1,
    'posting and duplicate identifiers are both detached after 24 hours'
);

fwrite(STDOUT, "\n{$tests} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
