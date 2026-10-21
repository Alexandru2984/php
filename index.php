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
        if (time() < ($_SESSION['login_lock_until'] ?? 0)) {
            flash_set('error', 'Too many attempts. Please wait a minute.');
            redirect('/');
        }
        $password = string_input($_POST, 'password');
        $hash = env('ADMIN_PASSWORD_HASH') ?? '';
        $ok = $password !== null
            && strlen($password) <= 1024
            && $hash !== ''
            && password_verify($password, $hash);
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_authenticated_at'] = time();
            $_SESSION['admin_last_seen_at'] = time();
            $_SESSION['admin_session_rotated_at'] = time();
            csrf_rotate();
            unset($_SESSION['login_fails'], $_SESSION['login_lock_until']);
            flash_set('success', 'Signed in as admin.');
        } else {
            $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
            if ($_SESSION['login_fails'] >= 5) {
                $_SESSION['login_lock_until'] = time() + 60;
                $_SESSION['login_fails']      = 0;
            }
            flash_set('error', 'Incorrect password.');
        }
        redirect('/');
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        csrf_rotate();
        flash_set('success', 'Signed out.');
        redirect('/');
    }

    if ($action === 'delete') {
        if (empty($_SESSION['is_admin'])) {
            flash_set('error', 'Not authorised.');
            redirect('/');
        }
        $rawId = string_input($_POST, 'id');
        $id = $rawId !== null && preg_match('/\A[1-9][0-9]{0,18}\z/D', $rawId) === 1
            ? filter_var($rawId, FILTER_VALIDATE_INT)
            : false;
        if ($id !== false && $id !== null) {
            $stmt = $pdo->prepare('DELETE FROM messages WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            flash_set('success', 'Message deleted.');
        } else {
            flash_set('error', 'Invalid message id.');
        }
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
$where  = '';
$params = [];
if ($q !== '') {
    $where          = "WHERE author LIKE :q ESCAPE '\\' OR content LIKE :q ESCAPE '\\'";
    $params[':q']   = '%' . addcslashes($q, '%_\\') . '%';
}

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

/** Build a URL for the feed preserving the active search query. */
function feed_url(int $page, string $q): string
{
    $params = array_filter(['q' => $q, 'page' => $page > 1 ? $page : null],
        static fn($v) => $v !== null && $v !== '');
    return '/' . ($params ? '?' . http_build_query($params) : '');
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="A little guestbook — leave a message.">
    <title>Guestbook</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📖</text></svg>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/style.css')); ?>">
    <script src="<?php echo e(asset('assets/theme-init.js')); ?>"></script>
    <script defer src="<?php echo e(asset('assets/app.js')); ?>"></script>
    <script defer src="https://analytics.micutu.com/script.js" data-website-id="c4e3a772-26b3-47e7-afec-b82097684551"></script>
</head>
<body>
    <main class="app">
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
                <button type="button" class="icon-btn" data-theme-toggle aria-label="Toggle theme">
                    <?php echo icon('sun', 'icon-sun'); ?><?php echo icon('moon', 'icon-moon'); ?>
                </button>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="flash <?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>" role="alert">
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
                           maxlength="<?php echo AUTHOR_MAX; ?>" placeholder="Your name">
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
                              placeholder="What's on your mind?"></textarea>
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
                                    <p class="msg-time" title="<?php echo e($msg['created_at']); ?> UTC">
                                        <?php echo icon('clock'); ?> <?php echo e(time_ago($msg['created_at'])); ?>
                                    </p>
                                </div>
                            </div>
                            <p class="msg-body"><?php echo e($msg['content']); ?></p>
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
    </main>
</body>
</html>
