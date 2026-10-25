<?php

declare(strict_types=1);

if (!defined('GUESTBOOK_APP')) {
    http_response_code(404);
    exit;
}

if (!isset($publicInfoPageSlug, $infoPage)
    || !is_string($publicInfoPageSlug)
    || !is_array($infoPage)
    || !isset($infoPage['path'], $infoPage['title'], $infoPage['heading'], $infoPage['description'], $infoPage['schema_type'])) {
    throw new RuntimeException('Invalid public information page');
}

$canonicalUrl = APP_ORIGIN . $infoPage['path'];
$socialImageUrl = APP_ORIGIN . asset('assets/social-card.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4f46e5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0e18" media="(prefers-color-scheme: dark)">
    <meta name="description" content="<?php echo e($infoPage['description']); ?>">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Micuțu Guestbook">
    <meta property="og:locale" content="en_US">
    <meta property="og:title" content="<?php echo e($infoPage['title']); ?>">
    <meta property="og:description" content="<?php echo e($infoPage['description']); ?>">
    <meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
    <meta property="og:image" content="<?php echo e($socialImageUrl); ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="An open guestbook surrounded by friendly message cards.">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo e($infoPage['title']); ?>">
    <meta name="twitter:description" content="<?php echo e($infoPage['description']); ?>">
    <meta name="twitter:image" content="<?php echo e($socialImageUrl); ?>">
    <meta name="twitter:image:alt" content="An open guestbook surrounded by friendly message cards.">
    <title><?php echo e($infoPage['title']); ?></title>
    <link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
    <link rel="alternate" type="application/rss+xml" title="Micuțu Guestbook RSS" href="/feed.xml">
    <link rel="alternate" type="application/feed+json" title="Micuțu Guestbook JSON Feed" href="/feed.json">
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
    <main class="app public-page" id="main-content" tabindex="-1"
          itemscope itemtype="https://schema.org/<?php echo e($infoPage['schema_type']); ?>">
        <link itemprop="url" href="<?php echo e($canonicalUrl); ?>">
        <meta itemprop="name" content="<?php echo e($infoPage['heading']); ?>">
        <meta itemprop="description" content="<?php echo e($infoPage['description']); ?>">
        <meta itemprop="lastReviewed" content="2026-10-25">
        <header class="header public-page-header">
            <a class="brand brand-link" href="/" aria-label="Micuțu Guestbook home">
                <span class="logo"><?php echo icon('book'); ?></span>
                <span class="brand-text">Micuțu Guestbook</span>
            </a>
            <button type="button" class="icon-btn" data-theme-toggle aria-label="Switch color theme" aria-pressed="false">
                <?php echo icon('sun', 'icon-sun'); ?><?php echo icon('moon', 'icon-moon'); ?>
            </button>
        </header>

        <nav class="breadcrumbs" aria-label="Breadcrumb" itemprop="breadcrumb"
             itemscope itemtype="https://schema.org/BreadcrumbList">
            <ol>
                <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                    <a itemprop="item" href="/"><span itemprop="name">Guestbook</span></a>
                    <meta itemprop="position" content="1">
                </li>
                <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem" aria-current="page">
                    <span itemprop="name"><?php echo e($infoPage['heading']); ?></span>
                    <meta itemprop="position" content="2">
                </li>
            </ol>
        </nav>

        <article class="policy" itemprop="mainContentOfPage" itemscope itemtype="https://schema.org/WebPageElement">
            <header class="policy-hero">
                <p class="policy-kicker">Micuțu Guestbook</p>
                <h1><?php echo e($infoPage['heading']); ?></h1>
                <p class="policy-lead"><?php echo e($infoPage['description']); ?></p>
                <p class="policy-reviewed">Reviewed <time datetime="2026-10-25">25 October 2026</time></p>
            </header>

            <?php if ($publicInfoPageSlug === 'about'): ?>
            <section>
                <h2>A small, open guestbook</h2>
                <p>Micuțu Guestbook is a public place for short messages, thoughts and hellos. A name is optional. Published names, messages and timestamps can be read by anyone and discovered by search engines.</p>
                <p>The application is operated on Micuțu infrastructure with a PHP and SQLite core. Its browser interface, styles, scripts and social preview are served from this site without a CDN or third-party social widget.</p>
            </section>
            <section>
                <h2>Open ways to read</h2>
                <p>Every published message has a stable permalink and appears in calendar archives. Readers can follow updates through <a href="/feed.xml">RSS</a> or <a href="/feed.json">JSON Feed</a>. Developers can use the same-origin <a href="/api/v1/messages">JSON API</a> and its <a href="/openapi.json">OpenAPI contract</a>.</p>
            </section>
            <section>
                <h2>Safety and accessibility</h2>
                <p>Submissions can be queued for human review, and every public message has a category-only Report control. The interface supports keyboard navigation, narrow mobile screens, reduced motion, forced colors and light or dark themes.</p>
                <p>Read the <a href="/privacy">privacy details</a> before posting and the <a href="/moderation">moderation policy</a> for the review process. Security vulnerabilities should use the contact route in <a href="/.well-known/security.txt">security.txt</a>.</p>
            </section>
            <?php elseif ($publicInfoPageSlug === 'privacy'): ?>
            <aside class="policy-callout" aria-label="Important privacy notice">
                <strong>This is a public guestbook.</strong> Do not submit secrets, contact details or other personal information that should not be indexed and redistributed.
            </aside>
            <section>
                <h2>What becomes public</h2>
                <p>The message, optional name, publication time and numeric permalink are public. Published content can appear on the homepage, search results, calendar archives, RSS, JSON Feed, the JSON API, the XML sitemap and search-engine indexes.</p>
                <p>Hiding a message removes it from application-controlled public surfaces immediately, but external caches, feed readers, screenshots and search indexes can keep earlier copies.</p>
            </section>
            <section>
                <h2>Abuse prevention and retention</h2>
                <ul>
                    <li>A purpose-separated keyed network identifier and duplicate-content fingerprint are detached from a message after 24 hours.</li>
                    <li>Purpose-separated rate-limit records expire after seven days.</li>
                    <li>Security events and closed abuse reports are deleted after 90 days. Open reports remain until human review.</li>
                    <li>Append-only moderator actions remain for accountability; permanent message purge is not currently available.</li>
                </ul>
                <p>Database backups can temporarily retain records after a live change. Local plaintext snapshots are retained for 14 days and local encrypted off-site staging copies for 30 days; provider-side retention is controlled separately and remains under operational review.</p>
            </section>
            <section>
                <h2>Reports, drafts and analytics</h2>
                <p>An abuse report contains the message number, one selected category, workflow status and timestamps. It has no free-text explanation, reporter account or reporter identifier; its separate rate limiter still uses the temporary keyed identifier described above.</p>
                <p>Draft saving is off by default. If enabled, the name and message stay in this browser's local storage for at most 24 hours and are removed after a successful submission or an explicit delete.</p>
                <?php if (ANALYTICS_WEBSITE_ID !== ''): ?>
                <p>This deployment sends aggregate page and performance events to the self-hosted <code>analytics.micutu.com</code> service using a reviewed script served from this site. It respects Do Not Track and excludes query strings, URL fragments and form content.</p>
                <?php else: ?>
                <p>Optional analytics is disabled on this deployment.</p>
                <?php endif; ?>
            </section>
            <section>
                <h2>Removal and access requests</h2>
                <p>Use Report on a published message to request review without adding more personal details. A dedicated private channel and automated workflow for access, export, erasure or objection requests are not yet published. This is a known operational gap, and this page must not be read as a complete statutory privacy notice.</p>
                <p>For a security vulnerability, use <a href="/.well-known/security.txt">security.txt</a>; do not use that route for ordinary content reports.</p>
            </section>
            <?php else: ?>
            <section>
                <h2>Community baseline</h2>
                <p>Keep messages lawful, kind and safe for a public audience. Spam, scams, harassment, personal or private information and other policy concerns can be reviewed. A report is a request for human review, not a vote and not an automatic takedown.</p>
            </section>
            <section>
                <h2>How reports work</h2>
                <p>Anyone can report a currently published message using one controlled category: spam, harassment, personal/private information or another policy concern. Reports contain no free-text narrative and no reporter identity. Five reports per client per hour is the current application limit.</p>
                <p>Public reports never hide a message automatically. A moderator separately resolves or dismisses the report and decides whether the message state should change.</p>
            </section>
            <section>
                <h2>Submission review</h2>
                <p>New messages are checked with fixed, explainable signals such as recent duplication, link density, address-like names, repeated runs, very fast submission and invisible Unicode controls. A score at or above the review threshold queues the message as pending.</p>
                <p>These signals never delete a message. A human moderator can publish, hide, mark as spam, mark as deleted or return a message to pending review using a controlled reason.</p>
            </section>
            <section>
                <h2>Accountability and limits</h2>
                <p>Successful moderation changes record the previous state, new state, reason, message number, logical moderator and timestamp in append-only audit history. Actions are reversible; permanent purge remains intentionally unavailable.</p>
                <p>Automated signals can produce false positives, and distributed or varied abuse can evade local limits. Review backlogs are monitored, while dedicated edge limits and a formal appeal channel remain follow-up work.</p>
            </section>
            <?php endif; ?>
        </article>

        <footer class="site-footer">
            <nav aria-label="Site information">
                <a href="/about"<?php echo $publicInfoPageSlug === 'about' ? ' aria-current="page"' : ''; ?>>About</a>
                <a href="/privacy"<?php echo $publicInfoPageSlug === 'privacy' ? ' aria-current="page"' : ''; ?>>Privacy</a>
                <a href="/moderation"<?php echo $publicInfoPageSlug === 'moderation' ? ' aria-current="page"' : ''; ?>>Moderation</a>
                <a href="/feed.xml">RSS</a>
                <a href="/feed.json">JSON Feed</a>
                <a href="/api/v1/messages">API</a>
                <a href="/.well-known/security.txt">Security</a>
            </nav>
            <p>Public by design. Please post with care.</p>
        </footer>
    </main>
</body>
</html>
