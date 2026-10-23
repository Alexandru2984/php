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

    await expect(page.getByRole('heading', { level: 1, name: 'Guestbook' })).toBeVisible();
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
  await page.getByRole('button', { name: 'Post message' }).click();

  await expect(page.getByRole('status')).toContainText('Message posted!');
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
