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

$_SESSION = [];
$_POST = [];
$_SERVER = [];

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

check(e('<script>"x"</script>') === '&lt;script&gt;&quot;x&quot;&lt;/script&gt;', 'HTML is escaped');
check(xml_e('<tag>&"') === '&lt;tag&gt;&amp;&quot;', 'XML is escaped');
check(
    hash_file('sha256', APP_ROOT . '/assets/analytics.js')
        === 'be444c289ac019af8486b50fe2bbf2fdb2890812fb945dc78940b6781a68ac52',
    'reviewed analytics tracker digest is pinned'
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
$invalidMessagePathRejected = false;
try {
    public_message_path(0);
} catch (InvalidArgumentException) {
    $invalidMessagePathRejected = true;
}
check($invalidMessagePathRejected, 'message path rejects invalid identifiers');
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

check(string_input(['value' => 'ok'], 'value') === 'ok', 'string input is accepted');
check(string_input(['value' => ['nested']], 'value') === null, 'array input is rejected');
check(positive_int_input(['page' => '42'], 'page') === 42, 'positive decimal is parsed');
check(positive_int_input(['page' => '1e3'], 'page', 7) === 7, 'numeric notation is rejected');
check(positive_int_input(['page' => ['2']], 'page', 7) === 7, 'nested integer is rejected');
check(id_input(['id' => '9223372036854775807'], 'id') === PHP_INT_MAX, '64-bit identifier is parsed');
check(id_input(['id' => '0'], 'id') === null, 'zero identifier is rejected');
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
        'risk_flags',
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
$sitemapFixture = public_sitemap_xml($seoDb);
check(
    str_contains($sitemapFixture, 'https://php.micutu.com/messages/1')
        && !str_contains($sitemapFixture, 'message=2')
        && !str_contains($sitemapFixture, 'Pending secret'),
    'sitemap contains canonical published permalinks only'
);
$rssFixture = public_rss_xml($seoDb);
check(
    str_contains($rssFixture, 'Public &amp; indexable')
        && str_contains($rssFixture, 'https://php.micutu.com/messages/1')
        && !str_contains($rssFixture, 'Pending secret'),
    'RSS contains escaped published records only'
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
        && $firstSubmission['flags'] === [],
    'a normal submission publishes without risk flags'
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
    "SELECT status, moderation_reason, moderated_at, ip_hash
     FROM messages WHERE id = {$moderatedId}"
)->fetch(PDO::FETCH_ASSOC);
check(
    $moderated !== false
        && $moderated['status'] === 'hidden'
        && $moderated['moderation_reason'] === 'spam'
        && $moderated['moderated_at'] === '1970-01-01 00:05:00'
        && $moderated['ip_hash'] === null,
    'moderation records reason/time and detaches the posting identifier'
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
    (int) $db->query('SELECT COUNT(*) FROM admin_audit_events')->fetchColumn() === 3,
    'moderation and report reviews append audit events'
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
    "INSERT INTO messages (author, content, created_at)
     VALUES ('API first', 'first page fixture', '2030-01-01 00:00:00'),
            ('API second', 'second page fixture', '2030-01-01 00:00:00')"
);
$db->exec(
    "INSERT INTO messages (author, content, created_at, status)
     VALUES ('API hidden', 'must never leak', '2031-01-01 00:00:00', 'hidden')"
);
$apiPageOne = api_list_messages($db, 1);
check(
    count($apiPageOne['data']) === 1
        && $apiPageOne['data'][0]['author'] === 'API second'
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
    "INSERT INTO messages (author, content, ip_hash, content_fingerprint, created_at)
     VALUES ('Alice', 'old', 'pseudonymous', '" . str_repeat('a', 64) . "',
             '1970-01-01 00:00:00')"
);
$pruned = prune_security_records($db, 100 + 91 * 86400);
check($pruned['security_events'] === 1, 'expired security events are pruned');
check($pruned['abuse_reports'] === 1, 'old reviewed abuse reports are pruned');
check($pruned['message_identifiers'] === 1, 'old message identifier is detached');
check(
    (int) $db->query(
        "SELECT COUNT(*) FROM messages
         WHERE content = 'old' AND ip_hash IS NULL AND content_fingerprint IS NULL"
    )->fetchColumn() === 1,
    'posting and duplicate identifiers are both detached after 24 hours'
);

fwrite(STDOUT, "\n{$tests} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
