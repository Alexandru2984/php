import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';

const viewports = [320, 375, 768, 1024, 1440];
const wcagTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

function observeRuntimeFailures(page, ignoreRequestFailure = () => false) {
  const failures = [];
  page.on('console', (message) => {
    if (message.type() === 'error') failures.push(`console: ${message.text()}`);
  });
  page.on('pageerror', (error) => failures.push(`page: ${error.message}`));
  page.on('requestfailed', (request) => {
    if (ignoreRequestFailure(request)) return;
    failures.push(`request: ${request.method()} ${request.url()} (${request.failure()?.errorText ?? 'failed'})`);
  });
  return failures;
}

function violationSummary(results) {
  return results.violations.map((violation) => ({
    id: violation.id,
    impact: violation.impact,
    targets: violation.nodes.map((node) => node.target),
  }));
}

test.describe.configure({ mode: 'serial' });

test('supported viewport widths reflow without horizontal overflow', async ({ page }) => {
  const runtimeFailures = observeRuntimeFailures(page);

  for (const width of viewports) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/');

    const dimensions = await page.evaluate(() => ({
      viewportWidth: document.documentElement.clientWidth,
      documentWidth: document.documentElement.scrollWidth,
      bodyWidth: document.body.scrollWidth,
    }));
    expect(dimensions.documentWidth, `${width}px document overflow`).toBeLessThanOrEqual(dimensions.viewportWidth);
    expect(dimensions.bodyWidth, `${width}px body overflow`).toBeLessThanOrEqual(dimensions.viewportWidth);

    await expect(page.getByRole('heading', { level: 1, name: 'Micuțu Guestbook' })).toBeVisible();
    await expect(page.getByRole('button', { name: /theme/i })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Message' })).toBeVisible();
    await expect(page.getByRole('searchbox', { name: 'Search messages' })).toBeVisible();
    const searchBox = await page.locator('.search').boundingBox();
    const searchIcon = await page.locator('.search > .field-icon').boundingBox();
    expect(searchBox?.height ?? 0, `${width}px search control height`).toBeLessThanOrEqual(48);
    expect(searchIcon?.width ?? 0, `${width}px search icon width`).toBe(18);
    expect(searchIcon?.height ?? 0, `${width}px search icon height`).toBe(18);

    if (width <= 375) {
      const touchTargets = page.locator([
        '[data-theme-toggle]',
        '.compose .input',
        '.compose .textarea',
        '.compose .btn',
        '.draft-opt-in',
        '.search-input',
        '.search-submit',
        '.admin > summary',
        '.privacy > summary',
      ].join(', '));
      const targetCount = await touchTargets.count();
      for (let index = 0; index < targetCount; index += 1) {
        const box = await touchTargets.nth(index).boundingBox();
        expect(box?.height ?? 0, `${width}px touch target ${index}`).toBeGreaterThanOrEqual(44);
      }
    }
  }

  expect(runtimeFailures).toEqual([]);
});

test('keyboard navigation, theme persistence and posting work end to end', async ({ page }) => {
  const runtimeFailures = observeRuntimeFailures(page);
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/');

  await page.keyboard.press('Tab');
  const skipLink = page.getByRole('link', { name: 'Skip to content' });
  await expect(skipLink).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('#main-content')).toBeFocused();

  await page.keyboard.press('Tab');
  const themeToggle = page.getByRole('button', { name: /theme/i });
  await expect(themeToggle).toBeFocused();
  const focusStyle = await themeToggle.evaluate((element) => {
    const style = getComputedStyle(element);
    return { style: style.outlineStyle, width: Number.parseFloat(style.outlineWidth) };
  });
  expect(focusStyle.style).not.toBe('none');
  expect(focusStyle.width).toBeGreaterThanOrEqual(2);

  await themeToggle.click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', /^(dark|light)$/);
  const selectedTheme = await page.locator('html').getAttribute('data-theme');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', selectedTheme);

  const author = page.getByRole('textbox', { name: /Name/ });
  const message = page.getByRole('textbox', { name: 'Message' });
  await author.fill('Browser check');
  await message.fill('Responsive accessibility flow');
  await expect(page.locator('#msg-counter')).toHaveText('29 / 2000');
  await page.getByRole('checkbox', { name: 'Save a draft on this device' }).check();
  await expect(page.locator('[data-draft-status]')).toHaveText('Draft saved on this device.');
  expect(await page.evaluate(() => localStorage.getItem('gb-compose-draft-v1'))).not.toBeNull();
  await page.getByRole('button', { name: 'Post message' }).click();

  await expect(page).toHaveURL(/\/messages\/\d+\/manage(?:#main-content)?$/);
  await expect(page.getByRole('status')).toContainText('Message posted!');
  await expect(page.getByRole('heading', { level: 1, name: /Manage message #\d+/ })).toBeVisible();
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex, nofollow, noarchive');
  await expect(page.locator('link[rel="canonical"]')).toHaveCount(0);
  await expect(page.getByText('Responsive accessibility flow', { exact: true })).toHaveCount(0);
  expect(await page.evaluate(() => localStorage.getItem('gb-compose-draft-v1'))).toBeNull();
  expect(await page.evaluate(() => document.cookie)).not.toContain('gbowner_');
  const managePath = new URL(page.url()).pathname;
  const publicPath = managePath.replace(/\/manage$/, '');
  const ownerCookies = (await page.context().cookies()).filter((cookie) => cookie.name.startsWith('gbowner_'));
  expect(ownerCookies).toHaveLength(1);
  expect(ownerCookies[0]).toMatchObject({ httpOnly: true, sameSite: 'Strict', path: managePath });
  const manageResults = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
  expect(violationSummary(manageResults), 'private management WCAG violations').toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);

  await page.getByRole('link', { name: 'View public message' }).click();
  await expect(page).toHaveURL(new RegExp(`${publicPath}$`));
  await expect(page.getByText('Responsive accessibility flow', { exact: true })).toBeVisible();
  for (const target of [page.locator('.permalink'), page.locator('.report-menu > summary')]) {
    const box = await target.boundingBox();
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
  }

  const search = page.getByRole('searchbox', { name: 'Search messages' });
  await search.fill('accessibility flow');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.getByRole('heading', { level: 2, name: 'Search results' })).toBeVisible();
  await expect(page.getByText('Responsive accessibility flow', { exact: true })).toBeVisible();

  expect(runtimeFailures).toEqual([]);
});

test('opt-in drafts expire locally and preview treats markup as text', async ({ page }) => {
  const runtimeFailures = observeRuntimeFailures(page);
  await page.setViewportSize({ width: 320, height: 812 });
  await page.goto('/');

  const author = page.getByRole('textbox', { name: /Name/ });
  const message = page.getByRole('textbox', { name: 'Message' });
  const optIn = page.getByRole('checkbox', { name: 'Save a draft on this device' });
  const hostileName = '<img src=x onerror="window.__draftExecuted=true">';
  const hostileMessage = '<script>window.__draftExecuted=true</script>\nStill plain text.';

  await author.fill(hostileName);
  await message.fill(hostileMessage);
  expect(await page.evaluate(() => localStorage.getItem('gb-compose-draft-v1'))).toBeNull();
  await optIn.check();
  await expect(page.locator('[data-draft-status]')).toHaveText('Draft saved on this device.');

  const stored = await page.evaluate(() => JSON.parse(localStorage.getItem('gb-compose-draft-v1')));
  expect(stored.author).toBe(hostileName);
  expect(stored.message).toBe(hostileMessage);
  expect(stored.expiresAt - stored.savedAt).toBe(24 * 60 * 60 * 1000);

  await page.reload();
  await expect(author).toHaveValue(hostileName);
  await expect(message).toHaveValue(hostileMessage);
  await expect(optIn).toBeChecked();
  await page.getByRole('button', { name: 'Preview', exact: true }).click();
  const preview = page.getByRole('region', { name: 'Private preview' });
  await expect(preview).toBeVisible();
  await expect(preview.locator('[data-preview-author]')).toHaveText(hostileName);
  await expect(preview.locator('[data-preview-body]')).toHaveText(hostileMessage);
  await expect(preview.locator('script, img')).toHaveCount(0);
  expect(await page.evaluate(() => window.__draftExecuted)).toBeUndefined();

  await page.getByRole('button', { name: 'Delete saved draft' }).click();
  expect(await page.evaluate(() => localStorage.getItem('gb-compose-draft-v1'))).toBeNull();
  await expect(message).toHaveValue(hostileMessage);

  await page.evaluate(() => {
    const now = Date.now();
    localStorage.setItem('gb-compose-draft-v1', JSON.stringify({
      author: 'Expired',
      message: 'Must not return',
      savedAt: now - (25 * 60 * 60 * 1000),
      expiresAt: now - (60 * 60 * 1000),
    }));
  });
  await page.reload();
  await expect(author).toHaveValue('');
  await expect(message).toHaveValue('');
  expect(await page.evaluate(() => localStorage.getItem('gb-compose-draft-v1'))).toBeNull();
  expect(runtimeFailures).toEqual([]);
});

test('light and dark interfaces have no automatically detectable WCAG A/AA violations', async ({ page }) => {
  const runtimeFailures = observeRuntimeFailures(page);

  for (const colorScheme of ['light', 'dark']) {
    await page.emulateMedia({ colorScheme, reducedMotion: 'reduce' });
    await page.setViewportSize({ width: colorScheme === 'light' ? 320 : 1440, height: 900 });
    await page.goto('/');

    const results = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
    expect(violationSummary(results), `${colorScheme} WCAG violations`).toEqual([]);

    const transitionDuration = await page.locator('.msg').evaluate((element) =>
      Number.parseFloat(getComputedStyle(element).transitionDuration) || 0,
    );
    expect(transitionDuration).toBeLessThanOrEqual(0.00001);
  }

  expect(runtimeFailures).toEqual([]);
});

test('public trust pages are canonical, accessible and responsive', async ({ page }) => {
  const runtimeFailures = observeRuntimeFailures(page);
  const pages = [
    { path: '/about', title: 'About · Micuțu Guestbook', heading: 'About this guestbook', schema: 'AboutPage' },
    { path: '/privacy', title: 'Privacy · Micuțu Guestbook', heading: 'Privacy and data use', schema: 'WebPage' },
    { path: '/moderation', title: 'Moderation · Micuțu Guestbook', heading: 'Moderation policy', schema: 'WebPage' },
  ];

  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  await page.setViewportSize({ width: 320, height: 812 });
  for (const publicPage of pages) {
    await page.goto(publicPage.path);
    await expect(page).toHaveTitle(publicPage.title);
    await expect(page.getByRole('heading', { level: 1, name: publicPage.heading })).toBeVisible();
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', new RegExp(`${publicPage.path}$`));
    await expect(page.locator(`main[itemtype="https://schema.org/${publicPage.schema}"]`)).toHaveCount(1);
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText('Guestbook');
    await expect(page.getByRole('navigation', { name: 'Site information' }).getByRole('link', { name: publicPage.heading.startsWith('About') ? 'About' : publicPage.heading.split(' ')[0] }))
      .toHaveAttribute('aria-current', 'page');

    const dimensions = await page.evaluate(() => ({
      viewportWidth: document.documentElement.clientWidth,
      documentWidth: document.documentElement.scrollWidth,
      bodyWidth: document.body.scrollWidth,
    }));
    expect(dimensions.documentWidth, `${publicPage.path} document overflow`).toBeLessThanOrEqual(dimensions.viewportWidth);
    expect(dimensions.bodyWidth, `${publicPage.path} body overflow`).toBeLessThanOrEqual(dimensions.viewportWidth);
    const results = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
    expect(violationSummary(results), `${publicPage.path} WCAG violations`).toEqual([]);
  }

  expect(runtimeFailures).toEqual([]);
});

test('SEO metadata, canonical permalinks and structured data match visible content', async ({ page }) => {
  const runtimeFailures = observeRuntimeFailures(page);
  await page.goto('/');

  await expect(page).toHaveTitle('Micuțu Guestbook · Leave a public message');
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /^http:\/\/127\.0\.0\.1:\d+\/$/);
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /^index, follow/);
  await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content', /\/assets\/social-card\.png\?v=\d+$/);
  await expect(page.locator('meta[property="og:image:width"]')).toHaveAttribute('content', '1200');
  await expect(page.locator('meta[property="og:image:height"]')).toHaveAttribute('content', '630');
  await expect(page.locator('meta[property="og:image:alt"]')).toHaveAttribute('content', /open guestbook/i);
  await expect(page.locator('meta[name="twitter:card"]')).toHaveAttribute('content', 'summary_large_image');
  await expect(page.locator('meta[name="twitter:image"]')).toHaveAttribute('content', /\/assets\/social-card\.png\?v=\d+$/);
  await expect(page.locator('link[rel="alternate"][type="application/rss+xml"]')).toHaveAttribute('href', '/feed.xml');
  await expect(page.locator('link[rel="alternate"][type="application/feed+json"]')).toHaveAttribute('href', '/feed.json');
  await expect(page.locator('[itemtype="https://schema.org/WebSite"]')).toContainText('Micuțu Guestbook');

  const archiveLink = page.locator('.archive-link').first();
  await expect(archiveLink).toContainText(/\w+ 20\d{2}/);
  await expect(archiveLink).toContainText('1 message');
  const archiveHref = await archiveLink.getAttribute('href');
  expect(archiveHref).toMatch(/^\/archive\/20\d{2}\/(?:0[1-9]|1[0-2])$/);
  await expect(page.getByRole('link', { name: 'View full archive' })).toHaveAttribute('href', '/archive');

  await page.setViewportSize({ width: 320, height: 812 });
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  await page.goto('/archive');
  await expect(page).toHaveTitle('Archive · Micuțu Guestbook');
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/archive$/);
  await expect(page.locator('main[itemtype="https://schema.org/CollectionPage"]')).toHaveCount(1);
  await expect(page.locator('[itemtype="https://schema.org/ItemList"]')).toHaveCount(1);
  await expect(page.getByRole('heading', { level: 1, name: 'Guestbook archive' })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText('Archive');
  await expect(page.getByRole('navigation', { name: 'Site information' }).getByRole('link', { name: 'Archive' }))
    .toHaveAttribute('aria-current', 'page');
  await expect(page.locator(`.archive-index-link[href="${archiveHref}"]`)).toContainText('1 message');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  const archiveIndexResults = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
  expect(violationSummary(archiveIndexResults), 'archive index WCAG violations').toEqual([]);

  await page.goto(archiveHref);
  await expect(page).toHaveTitle(/archive · Micuțu Guestbook$/);
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', new RegExp(`${archiveHref}$`));
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText('archive');
  await expect(page.locator('.archive-link[aria-current="page"]')).toHaveAttribute('href', archiveHref);
  await expect(page.getByText('Responsive accessibility flow', { exact: true })).toBeVisible();

  const permalink = await page.locator('.permalink').first().getAttribute('href');
  expect(permalink).toMatch(/^\/messages\/\d+#m\d+$/);
  await page.goto('/');
  await page.getByRole('textbox', { name: /Name/ }).fill('Neighbor check');
  await page.getByRole('textbox', { name: 'Message' }).fill('Published permalink neighbor');
  await page.getByRole('button', { name: 'Post message' }).click();
  await expect(page).toHaveURL(/\/messages\/\d+\/manage(?:#main-content)?$/);
  await page.goto(permalink);
  await expect(page).toHaveTitle(/Message by Browser check · Micuțu Guestbook/);
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/messages\/\d+$/);
  await expect(page.locator('meta[property="og:type"]')).toHaveAttribute('content', 'article');
  await expect(page.locator('meta[property="article:published_time"]')).toHaveAttribute('content', /Z$/);
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText('Guestbook');
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText(/\w+ 20\d{2} archive/);
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText(/Message #\d+/);
  const adjacentNavigation = page.getByRole('navigation', { name: 'Adjacent messages' });
  await expect(adjacentNavigation).toBeVisible();
  const adjacentLinks = adjacentNavigation.locator('a[href^="/messages/"]');
  expect(await adjacentLinks.count()).toBeGreaterThanOrEqual(1);
  expect(await adjacentLinks.count()).toBeLessThanOrEqual(2);
  await expect(adjacentLinks.first()).toHaveAttribute('href', /^\/messages\/\d+$/);
  await expect(page.locator('[itemtype="https://schema.org/DiscussionForumPosting"] [itemprop="text"]'))
    .toHaveText('Responsive accessibility flow');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  const permalinkResults = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
  expect(violationSummary(permalinkResults), 'permalink navigation WCAG violations').toEqual([]);

  await page.goto('/?q=accessibility');
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex, follow');
  expect(runtimeFailures).toEqual([]);
});

test('message sharing is explicit, canonical and progressively enhanced', async ({ page }) => {
  const runtimeFailures = observeRuntimeFailures(page);
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'share', {
      configurable: true,
      value: async (payload) => { window.__sharedPayload = payload; },
    });
    Object.defineProperty(navigator, 'canShare', { configurable: true, value: () => true });
  });
  await page.setViewportSize({ width: 320, height: 812 });
  await page.goto('/');

  expect(await page.evaluate(() => window.__sharedPayload)).toBeUndefined();
  const shareButton = page.getByRole('button', { name: /Share message #/ }).first();
  await expect(shareButton).toBeVisible();
  const box = await shareButton.boundingBox();
  expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
  await shareButton.click();

  const payload = await page.evaluate(() => window.__sharedPayload);
  expect(payload.title).toMatch(/^Micuțu Guestbook message #\d+$/);
  expect(payload.url).toMatch(/^http:\/\/127\.0\.0\.1:\d+\/messages\/\d+$/);
  expect(payload.title).not.toContain('Browser check');
  await expect(page.locator('[data-share-status]')).toHaveText('Message link shared.');

  await page.evaluate(() => {
    Object.defineProperty(navigator, 'share', { configurable: true, value: undefined });
    Object.defineProperty(navigator, 'canShare', { configurable: true, value: undefined });
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText: async (url) => { window.__copiedUrl = url; } },
    });
  });
  await shareButton.click();
  expect(await page.evaluate(() => window.__copiedUrl)).toBe(payload.url);
  await expect(page.locator('[data-share-status]')).toHaveText('Message link copied.');
  expect(runtimeFailures).toEqual([]);
});

test('risk-queued duplicate is private, explained to admin and reversibly publishable', async ({ page }) => {
  const runtimeFailures = observeRuntimeFailures(page);
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/');

  await page.getByRole('textbox', { name: /Name/ }).fill('Queued browser check');
  await page.getByRole('textbox', { name: 'Message' }).fill('Responsive accessibility flow');
  await page.getByRole('button', { name: 'Post message' }).click();
  await expect(page).toHaveURL(/\/messages\/\d+\/manage(?:#main-content)?$/);
  await expect(page.getByRole('status')).toContainText('queued for moderator review');
  await expect(page.getByText('Awaiting moderator review')).toBeVisible();
  await expect(page.getByText('Responsive accessibility flow', { exact: true })).toHaveCount(0);

  await page.goto('/');
  await expect(page.getByText('Responsive accessibility flow', { exact: true })).toHaveCount(1);

  await page.locator('details.admin > summary').click();
  await page.locator('input[name="password"]').fill('browser-test-password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { level: 2, name: 'Moderation & audit' })).toBeVisible();
  await expect(page.getByText(/Review score \d+\/100/)).toBeVisible();
  await expect(page.getByText('Recent duplicate')).toBeVisible();
  await expect(page.getByRole('heading', { level: 3, name: 'Operational overview' })).toBeVisible();
  await expect(page.getByText('High-risk pending', { exact: true })).toBeVisible();
  await page.getByText(/Suspicious activity · 0 exceptions · 0 active throttles/).click();
  await expect(page.getByText('No failure or blocked events in the last 24 hours.')).toBeVisible();
  await expect(page.getByText('No subjects are currently throttled.')).toBeVisible();

  const reviewFilters = page.getByRole('search', { name: 'Filter moderation workspace' });
  await reviewFilters.getByRole('searchbox', { name: 'Search names and messages' })
    .fill('Queued browser check');
  await reviewFilters.getByRole('combobox', { name: 'Status' }).selectOption('pending');
  await reviewFilters.getByRole('combobox', { name: 'Order' }).selectOption('risk');
  await reviewFilters.getByRole('button', { name: 'Apply filters' }).click();
  await expect(page).toHaveURL(/admin_q=Queued(?:\+|%20)browser(?:\+|%20)check/);
  await expect(page.getByText('Review messages · 1 match')).toBeVisible();
  await expect(page.getByText('Showing 1–1 of 1.')).toBeVisible();
  await expect(page.locator('.moderation-item').filter({ hasText: 'Queued browser check' })).toBeVisible();
  for (const target of [
    reviewFilters.getByRole('searchbox', { name: 'Search names and messages' }),
    reviewFilters.getByRole('combobox', { name: 'Status' }),
    reviewFilters.getByRole('combobox', { name: 'Order' }),
    reviewFilters.getByRole('button', { name: 'Apply filters' }),
  ]) {
    const box = await target.boundingBox();
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
  }

  const queuedReview = page.locator('.moderation-item').filter({ hasText: 'Queued browser check' });
  const bulkForm = page.locator('form[data-bulk-moderation]');
  const bulkState = bulkForm.getByRole('combobox', { name: 'Move selected to' });
  await expect(bulkState.locator('option[value="published"]')).toHaveCount(0);
  await queuedReview.getByRole('checkbox', { name: /Select message #/ }).check();
  await expect(bulkForm.getByText('1 message selected.')).toBeVisible();
  await bulkState.selectOption('hidden');
  await bulkForm.getByRole('combobox', { name: 'Reason' }).selectOption('risk');
  page.once('dialog', async (dialog) => {
    expect(dialog.message()).toContain('all-or-nothing');
    await dialog.accept();
  });
  await bulkForm.getByRole('button', { name: 'Apply to selected' }).click();
  await expect(page.getByRole('status')).toContainText('1 message updated atomically');
  await expect(page.locator('.moderation-item').filter({ hasText: 'Queued browser check' }))
    .toContainText('hidden');

  page.once('dialog', async (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Feature message', exact: true }).first().click();
  await expect(page.getByRole('status')).toContainText('Featured message updated');
  await expect(page.getByText('Featured message', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Remove feature', exact: true })).toBeVisible();

  const results = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
  expect(violationSummary(results), 'admin queue WCAG violations').toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);

  await page.locator('.moderation-item').filter({ hasText: 'Queued browser check' })
    .getByRole('button', { name: 'Publish' }).click();
  await expect(page.getByRole('status')).toContainText('Moderation state updated');
  await expect(page.getByText('Responsive accessibility flow', { exact: true })).toHaveCount(2);
  expect(runtimeFailures).toEqual([]);
});

test('author can privately withdraw a message and it stays unavailable', async ({ page }) => {
  let expectedDownloadPath = '';
  const runtimeFailures = observeRuntimeFailures(page, (request) => (
    request.method() === 'POST'
      && new URL(request.url()).pathname === expectedDownloadPath
      && request.failure()?.errorText === 'net::ERR_ABORTED'
  ));
  await page.setViewportSize({ width: 320, height: 812 });
  await page.goto('/');

  await page.getByRole('textbox', { name: /Name/ }).fill('Owner browser check');
  await page.getByRole('textbox', { name: 'Message' }).fill('Owner withdrawal browser fixture');
  await page.getByRole('button', { name: 'Post message' }).click();
  await expect(page).toHaveURL(/\/messages\/\d+\/manage(?:#main-content)?$/);
  const managePath = new URL(page.url()).pathname;
  const publicPath = managePath.replace(/\/manage$/, '');

  await expect(page.getByRole('heading', { level: 1, name: /Manage message #\d+/ })).toBeVisible();
  await expect(page.getByText('Owner withdrawal browser fixture', { exact: true })).toHaveCount(0);
  expectedDownloadPath = managePath;
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: 'Download my message' }).click(),
  ]);
  expect(download.suggestedFilename()).toMatch(/^micutu-guestbook-message-\d+\.json$/);
  const downloadFilePath = await download.path();
  expect(downloadFilePath).not.toBeNull();
  const ownerExport = JSON.parse(await readFile(downloadFilePath, 'utf8'));
  expect(ownerExport.export_version).toBe('1.0');
  expect(ownerExport.message.author).toBe('Owner browser check');
  expect(ownerExport.message.content).toBe('Owner withdrawal browser fixture');
  expect(ownerExport.message.status).toBe('published');
  expect(Object.keys(ownerExport.message)).toEqual([
    'id', 'author', 'content', 'submitted_at', 'status',
  ]);
  expect(JSON.stringify(ownerExport)).not.toMatch(
    /ip_hash|content_fingerprint|risk_score|risk_flags|owner_token|moderation_reason/
  );
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  const results = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
  expect(violationSummary(results), 'owner withdrawal WCAG violations').toEqual([]);

  page.once('dialog', async (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Remove my message' }).click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole('status')).toContainText('removed from the public guestbook');
  const response = await page.request.get(publicPath);
  expect(response.status()).toBe(404);
  expect(await response.text()).toBe('Message not found.');
  const manageResponse = await page.request.get(managePath);
  expect(manageResponse.status()).toBe(404);
  expect(await manageResponse.text()).toBe('Not found.');
  expect((await page.context().cookies()).filter((cookie) => cookie.name.startsWith('gbowner_'))).toHaveLength(0);
  expect(runtimeFailures).toEqual([]);
});
