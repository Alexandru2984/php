import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const viewports = [320, 375, 768, 1024, 1440];
const wcagTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

function observeRuntimeFailures(page) {
  const failures = [];
  page.on('console', (message) => {
    if (message.type() === 'error') failures.push(`console: ${message.text()}`);
  });
  page.on('pageerror', (error) => failures.push(`page: ${error.message}`));
  page.on('requestfailed', (request) => {
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

  await expect(page.getByRole('status')).toContainText('Message posted!');
  expect(await page.evaluate(() => localStorage.getItem('gb-compose-draft-v1'))).toBeNull();
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

  const permalink = await page.locator('.permalink').first().getAttribute('href');
  expect(permalink).toMatch(/^\/messages\/\d+#m\d+$/);
  await page.goto(permalink);
  await expect(page).toHaveTitle(/Message by Browser check · Micuțu Guestbook/);
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/messages\/\d+$/);
  await expect(page.locator('meta[property="og:type"]')).toHaveAttribute('content', 'article');
  await expect(page.locator('meta[property="article:published_time"]')).toHaveAttribute('content', /Z$/);
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText('Guestbook');
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText(/Message #\d+/);
  await expect(page.locator('[itemtype="https://schema.org/DiscussionForumPosting"] [itemprop="text"]'))
    .toHaveText('Responsive accessibility flow');

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
  await expect(page.getByRole('status')).toContainText('queued for moderator review');
  await expect(page.getByText('Responsive accessibility flow', { exact: true })).toHaveCount(1);

  await page.locator('details.admin > summary').click();
  await page.locator('input[name="password"]').fill('browser-test-password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { level: 2, name: 'Moderation & audit' })).toBeVisible();
  await expect(page.getByText(/Review score \d+\/100/)).toBeVisible();
  await expect(page.getByText('Recent duplicate')).toBeVisible();

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
