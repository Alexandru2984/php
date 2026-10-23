import { defineConfig } from '@playwright/test';

const port = Number.parseInt(process.env.BROWSER_TEST_PORT ?? '18766', 10);
const baseURL = `http://127.0.0.1:${port}`;

export default defineConfig({
  testDir: './tests/browser',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: 0,
  workers: 1,
  timeout: 30_000,
  expect: {
    timeout: 5_000,
  },
  reporter: process.env.CI ? 'github' : 'line',
  use: {
    baseURL,
    browserName: 'chromium',
    locale: 'en-GB',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'bash tests/browser-server.sh',
    url: `${baseURL}/api/v1/messages`,
    reuseExistingServer: false,
    timeout: 20_000,
    stdout: 'pipe',
    stderr: 'pipe',
    env: {
      ...process.env,
      BROWSER_TEST_PORT: String(port),
    },
  },
});
