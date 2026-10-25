<?php

declare(strict_types=1);

if (!defined('GUESTBOOK_APP')) {
    http_response_code(404);
    exit;
}

if (!isset($managedMessage, $messageManagePathId, $ownerCsrf)
    || !is_array($managedMessage)
    || !is_int($messageManagePathId)
    || !is_string($ownerCsrf)) {
    throw new RuntimeException('Invalid message management page');
}

$managedStatus = (string) $managedMessage['status'];
$managedStatusLabel = match ($managedStatus) {
    'published' => 'Published',
    'pending' => 'Awaiting moderator review',
    'hidden' => 'Hidden by a moderator',
    'spam' => 'Marked as spam',
    'deleted' => 'Removed',
    default => 'Unavailable',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4f46e5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0e18" media="(prefers-color-scheme: dark)">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="same-origin">
    <title>Manage message · Micuțu Guestbook</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml" sizes="any">
    <link rel="stylesheet" href="<?php echo e(asset('assets/style.css')); ?>">
    <script src="<?php echo e(asset('assets/theme-init.js')); ?>"></script>
    <script defer src="<?php echo e(asset('assets/app.js')); ?>"></script>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <main class="app manage-page" id="main-content" tabindex="-1" data-clear-compose-draft>
        <header class="header public-page-header">
            <a class="brand brand-link" href="/" aria-label="Micuțu Guestbook home">
                <span class="logo"><?php echo icon('book'); ?></span>
                <span class="brand-text">Micuțu Guestbook</span>
            </a>
            <button type="button" class="icon-btn" data-theme-toggle aria-label="Switch color theme" aria-pressed="false">
                <?php echo icon('sun', 'icon-sun'); ?><?php echo icon('moon', 'icon-moon'); ?>
            </button>
        </header>

        <?php if (is_array($flash)): ?>
        <div class="flash <?php echo e($flash['type'] ?? 'success'); ?>" role="status">
            <?php echo e($flash['message'] ?? ''); ?>
        </div>
        <?php endif; ?>

        <section class="manage-card" aria-labelledby="manage-title">
            <p class="policy-kicker">Private controls</p>
            <h1 id="manage-title">Manage message #<?php echo $messageManagePathId; ?></h1>
            <p class="manage-lead">This private page works only in the browser that posted the message. The ownership key stays in an HttpOnly cookie and is never shown on this page.</p>

            <dl class="manage-meta">
                <div>
                    <dt>Status</dt>
                    <dd><span class="status-pill status-<?php echo e($managedStatus); ?>"><?php echo e($managedStatusLabel); ?></span></dd>
                </div>
                <div>
                    <dt>Submitted</dt>
                    <dd><time datetime="<?php echo e(utc_iso8601((string) $managedMessage['created_at'])); ?>"><?php echo e(time_ago('@' . $managedMessage['created_at'])); ?></time></dd>
                </div>
            </dl>

            <?php if ($managedStatus === 'published'): ?>
            <p><a class="btn secondary" href="<?php echo e(public_message_path($messageManagePathId)); ?>">View public message</a></p>
            <?php elseif ($managedStatus === 'pending'): ?>
            <p class="manage-notice">The message is private while it waits for human review. This page does not reveal its text.</p>
            <?php else: ?>
            <p class="manage-notice">The message is not currently visible on public guestbook surfaces.</p>
            <?php endif; ?>

            <div class="manage-danger">
                <h2>Remove this message</h2>
                <p>Removal immediately hides it from this site's pages, search, feeds, API and sitemap. It cannot be republished through moderation. External caches, screenshots and backups may retain earlier copies.</p>
                <form method="POST" action="<?php echo e(message_manage_path($messageManagePathId)); ?>"
                      data-confirm="Remove your message from the public guestbook? This cannot be undone through moderation.">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="owner_csrf" value="<?php echo e($ownerCsrf); ?>">
                    <input type="hidden" name="action" value="owner_remove">
                    <button class="btn danger" type="submit">Remove my message</button>
                </form>
            </div>
        </section>

        <footer class="site-footer">
            <nav aria-label="Site information">
                <a href="/">Guestbook</a>
                <a href="/archive">Archive</a>
                <a href="/privacy">Privacy</a>
                <a href="/moderation">Moderation</a>
            </nav>
            <p>Keep this page private. If its browser cookie is lost, use Report on the public message.</p>
        </footer>
    </main>
</body>
</html>
