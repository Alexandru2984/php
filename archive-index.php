<?php

declare(strict_types=1);

if (!defined('GUESTBOOK_APP')) {
    http_response_code(404);
    exit;
}

if (!isset($archiveIndexMonths) || !is_array($archiveIndexMonths)) {
    throw new RuntimeException('Invalid archive index data');
}

$archiveYears = [];
$archiveMessageTotal = 0;
$archiveLatestUpdate = null;
foreach ($archiveIndexMonths as $monthSummary) {
    if (!is_array($monthSummary)) {
        continue;
    }
    $monthKey = $monthSummary['archive_month'] ?? null;
    $messageCount = (int) ($monthSummary['message_count'] ?? 0);
    $updatedAt = $monthSummary['updated_at'] ?? null;
    if (!is_string($monthKey)
        || preg_match('/\A([12][0-9]{3})-(0[1-9]|1[0-2])\z/D', $monthKey, $match) !== 1
        || $messageCount < 1
        || !is_string($updatedAt)) {
        continue;
    }
    $monthDate = DateTimeImmutable::createFromFormat(
        '!Y-m',
        $monthKey,
        new DateTimeZone('UTC')
    );
    if (!$monthDate instanceof DateTimeImmutable) {
        continue;
    }
    $year = (int) $match[1];
    $archiveYears[$year][] = [
        'key' => $monthKey,
        'date' => $monthDate,
        'count' => $messageCount,
        'path' => public_archive_path($year, (int) $match[2]),
    ];
    $archiveMessageTotal += $messageCount;
    if ($archiveLatestUpdate === null || strcmp($updatedAt, $archiveLatestUpdate) > 0) {
        $archiveLatestUpdate = $updatedAt;
    }
}

if ($archiveYears === []) {
    throw new RuntimeException('Archive index has no valid public months');
}

$archiveMonthTotal = array_sum(array_map('count', $archiveYears));
$canonicalUrl = APP_ORIGIN . '/archive';
$pageTitle = 'Archive · Micuțu Guestbook';
$pageDescription = sprintf(
    'Browse %d public guestbook %s across %d UTC calendar %s.',
    $archiveMessageTotal,
    $archiveMessageTotal === 1 ? 'message' : 'messages',
    $archiveMonthTotal,
    $archiveMonthTotal === 1 ? 'month' : 'months'
);
$socialImageUrl = APP_ORIGIN . asset('assets/social-card.png');
$archiveModifiedAt = $archiveLatestUpdate === null ? null : utc_iso8601($archiveLatestUpdate);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4f46e5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0e18" media="(prefers-color-scheme: dark)">
    <meta name="description" content="<?php echo e($pageDescription); ?>">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Micuțu Guestbook">
    <meta property="og:locale" content="en_US">
    <meta property="og:title" content="<?php echo e($pageTitle); ?>">
    <meta property="og:description" content="<?php echo e($pageDescription); ?>">
    <meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
    <meta property="og:image" content="<?php echo e($socialImageUrl); ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="An open guestbook surrounded by friendly message cards.">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo e($pageTitle); ?>">
    <meta name="twitter:description" content="<?php echo e($pageDescription); ?>">
    <meta name="twitter:image" content="<?php echo e($socialImageUrl); ?>">
    <meta name="twitter:image:alt" content="An open guestbook surrounded by friendly message cards.">
    <title><?php echo e($pageTitle); ?></title>
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
    <main class="app public-page archive-index-page" id="main-content" tabindex="-1"
          itemscope itemtype="https://schema.org/CollectionPage">
        <link itemprop="url" href="<?php echo e($canonicalUrl); ?>">
        <meta itemprop="name" content="Guestbook archive">
        <meta itemprop="description" content="<?php echo e($pageDescription); ?>">
        <?php if ($archiveModifiedAt !== null): ?>
        <meta itemprop="dateModified" content="<?php echo e($archiveModifiedAt); ?>">
        <?php endif; ?>

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
                    <span itemprop="name">Archive</span>
                    <meta itemprop="position" content="2">
                </li>
            </ol>
        </nav>

        <article class="archive-index-content" itemprop="mainContentOfPage">
            <header class="policy-hero archive-index-hero">
                <p class="policy-kicker">Public timeline</p>
                <h1>Guestbook archive</h1>
                <p class="policy-lead"><?php echo e($pageDescription); ?></p>
                <p class="archive-index-note">Only published messages are counted. Months use UTC.</p>
            </header>

            <?php foreach ($archiveYears as $year => $months): ?>
            <?php $yearMessageTotal = array_sum(array_column($months, 'count')); ?>
            <section class="archive-index-year" aria-labelledby="archive-year-<?php echo $year; ?>"
                     itemscope itemtype="https://schema.org/ItemList">
                <div class="archive-index-year-heading">
                    <h2 id="archive-year-<?php echo $year; ?>"><?php echo $year; ?></h2>
                    <span><?php echo $yearMessageTotal; ?> message<?php echo $yearMessageTotal === 1 ? '' : 's'; ?></span>
                </div>
                <meta itemprop="numberOfItems" content="<?php echo count($months); ?>">
                <ol class="archive-index-list">
                    <?php foreach ($months as $position => $month): ?>
                    <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                        <a class="archive-index-link" itemprop="item" href="<?php echo e($month['path']); ?>">
                            <time class="archive-index-month" datetime="<?php echo e($month['key']); ?>"
                                  itemprop="name"><?php echo e($month['date']->format('F')); ?></time>
                            <span class="archive-index-count"><?php echo $month['count']; ?> message<?php echo $month['count'] === 1 ? '' : 's'; ?></span>
                        </a>
                        <meta itemprop="position" content="<?php echo $position + 1; ?>">
                    </li>
                    <?php endforeach; ?>
                </ol>
            </section>
            <?php endforeach; ?>

            <p class="archive-index-action"><a class="btn secondary" href="/">View latest messages</a></p>
        </article>

        <footer class="site-footer">
            <nav aria-label="Site information">
                <a href="/archive" aria-current="page">Archive</a>
                <a href="/about">About</a>
                <a href="/privacy">Privacy</a>
                <a href="/moderation">Moderation</a>
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
