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

$_SESSION = [];
$_POST = [];
$_SERVER = [];

require dirname(__DIR__) . '/lib.php';

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
        'moderation_reason', 'moderated_at',
    ],
    'migrated schema has expected columns'
);
check(
    (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('admin_audit_events', 'abuse_reports')")->fetchColumn() === 2,
    'moderation audit and abuse-report tables exist'
);

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
$db->exec(
    "INSERT INTO messages (author, content, ip_hash, created_at)
     VALUES ('Bob', 'moderate me', 'temporary', '2026-10-21 10:00:00')"
);
$moderatedId = (int) $db->lastInsertId();
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
    "INSERT INTO messages (author, content, ip_hash, created_at)
     VALUES ('Alice', 'old', 'pseudonymous', '1970-01-01 00:00:00')"
);
$pruned = prune_security_records($db, 100 + 91 * 86400);
check($pruned['security_events'] === 1, 'expired security events are pruned');
check($pruned['abuse_reports'] === 1, 'old reviewed abuse reports are pruned');
check($pruned['message_identifiers'] === 1, 'old message identifier is detached');

fwrite(STDOUT, "\n{$tests} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
