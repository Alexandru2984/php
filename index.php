<?php

declare(strict_types=1);

define('GUESTBOOK_APP', true);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

// ---------------------------------------------------------------------------
// Controller (all mutations are POST, CSRF-protected, and end in a redirect).
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('/');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $hash = env('ADMIN_PASSWORD_HASH') ?? '';
        $ok   = $hash !== '' && password_verify((string) ($_POST['password'] ?? ''), $hash);
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            flash_set('success', 'Signed in as admin.');
        } else {
            flash_set('error', 'Incorrect password.');
        }
        redirect('/');
    }

    if ($action === 'logout') {
        $_SESSION['is_admin'] = false;
        session_regenerate_id(true);
        flash_set('success', 'Signed out.');
        redirect('/');
    }

    if ($action === 'delete') {
        if (empty($_SESSION['is_admin'])) {
            flash_set('error', 'Not authorised.');
            redirect('/');
        }
        $id   = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
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
        if (!empty($_POST['website'])) {
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
// Read model
// ---------------------------------------------------------------------------
$isAdmin  = !empty($_SESSION['is_admin']);
$messages = $pdo->query('SELECT * FROM messages ORDER BY created_at DESC, id DESC')
                ->fetchAll();
$flash    = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guestbook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script defer src="https://analytics.micutu.com/script.js" data-website-id="c4e3a772-26b3-47e7-afec-b82097684551"></script>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col items-center py-10 font-sans">
    <div class="max-w-2xl w-full bg-white shadow-xl rounded-2xl p-8 border border-slate-100">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-extrabold text-indigo-600 tracking-tight flex items-center">
                <i class="fas fa-book-open mr-3"></i> Guestbook
            </h1>
            <div class="flex items-center gap-3">
                <span class="bg-indigo-100 text-indigo-800 text-xs font-semibold px-3 py-1 rounded-full">
                    <?php echo count($messages); ?> Messages
                </span>
                <?php if ($isAdmin): ?>
                    <form method="POST"><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="logout">
                        <button class="text-xs font-semibold text-slate-500 hover:text-slate-800">Sign out</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flash): ?>
            <?php $isErr = $flash['type'] === 'error'; ?>
            <div class="<?php echo $isErr ? 'bg-red-100 border-red-500 text-red-700' : 'bg-green-100 border-green-500 text-green-700'; ?> border-l-4 p-4 mb-6 rounded-r-lg" role="alert">
                <p><?php echo e($flash['message']); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" class="mb-10 bg-slate-50 p-6 rounded-xl border border-slate-200 shadow-inner">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="post">
            <!-- honeypot: hidden from real users -->
            <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Name (Optional)</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-user text-slate-400"></i>
                    </div>
                    <input type="text" name="author" maxlength="<?php echo AUTHOR_MAX; ?>" placeholder="John Doe" class="w-full pl-10 border border-slate-300 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-shadow bg-white">
                </div>
            </div>
            <div class="mb-5">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Message <span class="text-red-500">*</span></label>
                <div class="relative">
                    <div class="absolute top-3 left-3 pointer-events-none">
                        <i class="fas fa-comment-alt text-slate-400"></i>
                    </div>
                    <textarea name="message" required maxlength="<?php echo MESSAGE_MAX; ?>" rows="3" placeholder="What's on your mind?" class="w-full pl-10 border border-slate-300 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-shadow resize-none bg-white"></textarea>
                </div>
            </div>
            <button type="submit" class="w-full bg-indigo-600 text-white font-bold px-4 py-3 rounded-lg hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-200 transition-all flex justify-center items-center gap-2 shadow-md hover:shadow-lg">
                <i class="fas fa-paper-plane"></i> Post Message
            </button>
        </form>

        <div>
            <h2 class="text-xl font-bold text-slate-800 mb-5 border-b pb-3"><i class="fas fa-history mr-2 text-slate-400"></i>Recent Posts</h2>
            <?php if (count($messages) > 0): ?>
                <div class="space-y-5">
                    <?php foreach ($messages as $msg): ?>
                        <?php $name = $msg['author'] ?? 'Anonymous'; ?>
                        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition-all duration-200 relative group">
                            <?php if ($isAdmin): ?>
                            <form method="POST" class="absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity" onsubmit="return confirm('Delete this message?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $msg['id']; ?>">
                                <button type="submit" class="text-red-400 hover:text-red-600 p-2 bg-red-50 hover:bg-red-100 rounded-lg transition-colors" title="Delete message">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <div class="flex items-center gap-4 mb-3">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-indigo-100 to-indigo-200 text-indigo-700 flex items-center justify-center font-bold text-xl shadow-inner border border-indigo-100">
                                    <?php echo e(avatar_initial($name)); ?>
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-800"><?php echo e($name); ?></h3>
                                    <p class="text-xs text-slate-500 font-medium" title="<?php echo e($msg['created_at']); ?>">
                                        <i class="far fa-clock mr-1"></i> <?php echo e(time_ago($msg['created_at'])); ?>
                                    </p>
                                </div>
                            </div>
                            <p class="text-slate-700 mt-2 whitespace-pre-wrap leading-relaxed bg-slate-50 p-4 rounded-lg border border-slate-100"><?php echo e($msg['content']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-12 bg-slate-50 rounded-xl border-2 border-dashed border-slate-200">
                    <i class="fas fa-inbox text-5xl text-slate-300 mb-4 block"></i>
                    <h3 class="text-lg font-semibold text-slate-700 mb-1">It's pretty quiet here</h3>
                    <p class="text-slate-500">Be the first to leave a message in the guestbook!</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$isAdmin): ?>
        <details class="mt-10 text-sm text-slate-400">
            <summary class="cursor-pointer hover:text-slate-600 select-none">Admin</summary>
            <form method="POST" class="mt-3 flex gap-2">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="login">
                <input type="password" name="password" placeholder="Admin password" class="flex-1 border border-slate-300 rounded-lg p-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <button class="bg-slate-700 text-white px-4 py-2 rounded-lg hover:bg-slate-800">Sign in</button>
            </form>
        </details>
        <?php endif; ?>
    </div>
</body>
</html>
