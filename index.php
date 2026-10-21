<?php

declare(strict_types=1);

define('GUESTBOOK_APP', true);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    abort_response(405, 'Method not allowed.');
}
if (!empty($_SERVER['PATH_INFO'])) {
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
        security_event($pdo, 'admin.logout', 'success', client_ip_hash('admin-action'));
        $_SESSION = [];
        session_regenerate_id(true);
        csrf_rotate();
        flash_set('success', 'Signed out.');
        redirect('/');
    }

    if ($action === 'delete') {
        if (empty($_SESSION['is_admin'])) {
            security_event($pdo, 'message.delete', 'blocked', client_ip_hash('admin-action'));
            flash_set('error', 'Not authorised.');
            redirect('/');
        }
        $id = id_input($_POST, 'id');
        if ($id !== null) {
            $stmt = $pdo->prepare(
                "UPDATE messages
                 SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, ip_hash = NULL
                 WHERE id = :id AND status = 'published'"
            );
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $changed = $stmt->rowCount() === 1;
            security_event(
                $pdo,
                'message.delete',
                $changed ? 'success' : 'failure',
                client_ip_hash('admin-action'),
                $id
            );
            flash_set($changed ? 'success' : 'error', $changed
                ? 'Message moved to moderation trash.'
                : 'Message was not found or was already deleted.');
        } else {
            security_event($pdo, 'message.delete', 'failure', client_ip_hash('admin-action'));
            flash_set('error', 'Invalid message id.');
        }
        redirect('/');
    }

    if ($action === 'restore') {
        if (empty($_SESSION['is_admin'])) {
            security_event($pdo, 'message.restore', 'blocked', client_ip_hash('admin-action'));
            flash_set('error', 'Not authorised.');
            redirect('/');
        }
        $id = id_input($_POST, 'id');
        if ($id === null) {
            security_event($pdo, 'message.restore', 'failure', client_ip_hash('admin-action'));
            flash_set('error', 'Invalid message id.');
            redirect('/');
        }

        $stmt = $pdo->prepare(
            "UPDATE messages
             SET status = 'published', deleted_at = NULL
             WHERE id = :id AND status = 'deleted'"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $changed = $stmt->rowCount() === 1;
        security_event(
            $pdo,
            'message.restore',
            $changed ? 'success' : 'failure',
            client_ip_hash('admin-action'),
            $id
        );
        flash_set($changed ? 'success' : 'error', $changed
            ? 'Message restored.'
            : 'Message was not found or is already published.');
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

        [$author, $content, $error] = validate_submission($_POST);
        if ($error !== null) {
            old_input_set($_POST);
            flash_set('error', $error);
            redirect('/');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO messages (author, content, ip_hash) VALUES (:a, :c, :h)'
        );
        $stmt->execute([
            ':a' => $author,
            ':c' => $content,
            ':h' => client_ip_hash(),
        ]);
        $_SESSION['last_post_at'] = time();
        flash_set('success', 'Message posted!');
        redirect('/');
    }

    redirect('/');
}

// ---------------------------------------------------------------------------
// Read model — search + pagination
// ---------------------------------------------------------------------------
$isAdmin = !empty($_SESSION['is_admin']);

$rawQuery = string_input($_GET, 'q');
if ($rawQuery === null && array_key_exists('q', $_GET)) {
    abort_response(400, 'Invalid query.');
}
$q      = mb_substr(trim($rawQuery ?? ''), 0, 100);
$messageId = null;
if (array_key_exists('message', $_GET)) {
    $messageId = id_input($_GET, 'message');
    if ($messageId === null) {
        abort_response(400, 'Invalid message.');
    }
}
$whereParts = ["status = :status"];
$params = [':status' => 'published'];
if ($messageId !== null) {
    $whereParts[] = 'id = :message_id';
    $params[':message_id'] = $messageId;
}
if ($q !== '') {
    $whereParts[]   = "(author LIKE :q ESCAPE '\\' OR content LIKE :q ESCAPE '\\')";
    $params[':q']   = '%' . addcslashes($q, '%_\\') . '%';
}
$where = 'WHERE ' . implode(' AND ', $whereParts);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$perPage = PER_PAGE;
$pages   = max(1, (int) ceil($total / $perPage));
if (array_key_exists('page', $_GET) && !is_string($_GET['page'])) {
    abort_response(400, 'Invalid page.');
}
$page    = max(1, min($pages, positive_int_input($_GET, 'page')));
$offset  = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT * FROM messages $where ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$messages = $stmt->fetchAll();

$deletedMessages = [];
$securityEvents = [];
$deletedTotal = 0;
if ($isAdmin) {
    $deletedTotal = (int) $pdo->query(
        "SELECT COUNT(*) FROM messages WHERE status = 'deleted'"
    )->fetchColumn();
    $deletedMessages = $pdo->query(
        "SELECT id, author, content, created_at, deleted_at
         FROM messages WHERE status = 'deleted'
         ORDER BY deleted_at DESC, id DESC LIMIT 20"
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
    header('Content-Type: application/rss+xml; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    ?>
<rss version="2.0">
<channel>
    <title>Guestbook</title>
    <link><?php echo xml_e(APP_ORIGIN . '/'); ?></link>
    <description>Recent public guestbook messages</description>
    <language>en</language>
    <lastBuildDate><?php echo gmdate(DATE_RSS); ?></lastBuildDate>
    <?php foreach ($messages as $message): ?>
    <item>
        <title><?php echo xml_e((string) ($message['author'] ?: 'Anonymous')); ?></title>
        <link><?php echo xml_e(APP_ORIGIN . '/?message=' . (int) $message['id'] . '#m' . (int) $message['id']); ?></link>
        <guid isPermaLink="true"><?php echo xml_e(APP_ORIGIN . '/?message=' . (int) $message['id'] . '#m' . (int) $message['id']); ?></guid>
        <description><?php echo xml_e((string) $message['content']); ?></description>
        <pubDate><?php echo gmdate(DATE_RSS, strtotime((string) $message['created_at'] . ' UTC') ?: 0); ?></pubDate>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
    <?php
    exit;
}
if ($format !== null && $format !== '') {
    abort_response(400, 'Unsupported format.');
}

/** Build a URL for the feed preserving the active search query. */
function feed_url(int $page, string $q): string
{
    $params = array_filter(['q' => $q, 'page' => $page > 1 ? $page : null],
        static fn($v) => $v !== null && $v !== '');
    return '/' . ($params ? '?' . http_build_query($params) : '');
}

$flash = flash_get();
$oldInput = old_input_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4f46e5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0e18" media="(prefers-color-scheme: dark)">
    <meta name="description" content="A little guestbook — leave a message.">
    <title><?php echo $messageId !== null ? 'Message #' . $messageId . ' · ' : ''; ?>Guestbook</title>
    <link rel="canonical" href="<?php echo e(APP_ORIGIN . ($messageId !== null ? '/?message=' . $messageId : '/')); ?>">
    <link rel="alternate" type="application/rss+xml" title="Guestbook RSS" href="/?format=rss">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📖</text></svg>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/style.css')); ?>">
    <script src="<?php echo e(asset('assets/theme-init.js')); ?>"></script>
    <script defer src="<?php echo e(asset('assets/app.js')); ?>"></script>
    <?php if (ANALYTICS_WEBSITE_ID !== ''): ?>
    <script defer src="https://analytics.micutu.com/script.js" data-website-id="<?php echo e(ANALYTICS_WEBSITE_ID); ?>"></script>
    <?php endif; ?>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <main class="app" id="main-content">
        <header class="header">
            <h1 class="brand">
                <span class="logo"><?php echo icon('book'); ?></span>
                <span class="brand-text">Guestbook</span>
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

        <form method="POST" class="compose">
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

            <button class="btn" type="submit"><?php echo icon('send'); ?> Post message</button>
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
                        <article class="msg" id="m<?php echo (int) $msg['id']; ?>">
                            <?php if ($isAdmin): ?>
                            <form method="POST" data-confirm="Delete this message?">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $msg['id']; ?>">
                                <button class="msg-del" type="submit" title="Delete" aria-label="Delete message"><?php echo icon('trash'); ?></button>
                            </form>
                            <?php endif; ?>
                            <div class="msg-head">
                                <div class="avatar av-<?php echo avatar_index($name); ?>"><?php echo e(avatar_initial($name)); ?></div>
                                <div class="msg-meta">
                                    <p class="msg-author"><?php echo e($name); ?></p>
                                    <p class="msg-time">
                                        <?php echo icon('clock'); ?>
                                        <time datetime="<?php echo e(str_replace(' ', 'T', (string) $msg['created_at']) . 'Z'); ?>"
                                              title="<?php echo e($msg['created_at']); ?> UTC"><?php echo e(time_ago($msg['created_at'])); ?></time>
                                    </p>
                                </div>
                            </div>
                            <p class="msg-body"><?php echo e($msg['content']); ?></p>
                            <a class="permalink" href="/?message=<?php echo (int) $msg['id']; ?>#m<?php echo (int) $msg['id']; ?>" aria-label="Permanent link to message by <?php echo e($name); ?>">#<?php echo (int) $msg['id']; ?> · Permalink</a>
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
                <span class="badge"><?php echo $deletedTotal; ?> in trash</span>
            </div>

            <details class="admin-panel"<?php echo $deletedTotal > 0 ? ' open' : ''; ?>>
                <summary>Recently deleted messages</summary>
                <?php if ($deletedMessages): ?>
                    <div class="moderation-list">
                    <?php foreach ($deletedMessages as $deleted): ?>
                        <article class="moderation-item">
                            <div>
                                <strong><?php echo e($deleted['author'] ?: 'Anonymous'); ?></strong>
                                <span>#<?php echo (int) $deleted['id']; ?> · deleted <?php echo e(time_ago((string) $deleted['deleted_at'])); ?></span>
                            </div>
                            <p><?php echo e($deleted['content']); ?></p>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="id" value="<?php echo (int) $deleted['id']; ?>">
                                <button class="btn secondary compact" type="submit"><?php echo icon('restore'); ?> Restore</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="panel-empty">Moderation trash is empty.</p>
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

        <details class="privacy">
            <summary>Privacy &amp; data use</summary>
            <p>Messages and names are public. A keyed, non-reversible network identifier is used for abuse prevention and detached from messages after 24 hours. Security events are retained for up to 90 days.</p>
            <?php if (ANALYTICS_WEBSITE_ID !== ''): ?>
            <p>This deployment also loads first-party analytics from <code>analytics.micutu.com</code>.</p>
            <?php else: ?>
            <p>Optional analytics is disabled on this deployment.</p>
            <?php endif; ?>
        </details>
    </main>
</body>
</html>
