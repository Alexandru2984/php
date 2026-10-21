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
$schema = file_get_contents(dirname(__DIR__) . '/migrations/001_initial.sql');
check($schema !== false && $db->exec($schema) !== false, 'initial migration executes');
$columns = $db->query('PRAGMA table_info(messages)')->fetchAll(PDO::FETCH_COLUMN, 1);
check($columns === ['id', 'author', 'content', 'ip_hash', 'created_at'], 'initial schema has expected columns');

fwrite(STDOUT, "\n{$tests} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
