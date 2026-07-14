const { defineConfig, devices } = require('@playwright/test');
const path = require('node:path');
const { loadTestEnvironment } = require('./tests/support/load-test-environment.cjs');

loadTestEnvironment();

const baseURL = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';

module.exports = defineConfig({
  globalSetup: './tests/support/playwright-global-setup.cjs',
  testDir: './tests/playwright',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 2 : 0,
  // Stateful publication scenarios intentionally share one deterministic test database.
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    locale: 'de-CH',
    timezoneId: 'Europe/Zurich',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium-desktop',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'chromium-mobile',
      use: { ...devices['Pixel 7'] },
    },
  ],
  webServer: {
    command: 'php -S 127.0.0.1:8080 -t site tests/support/router.php',
    url: baseURL,
    reuseExistingServer: !process.env.CI,
    timeout: 15_000,
    stdout: 'pipe',
    stderr: 'pipe',
    env: {
      ...process.env,
      APP_ENV: 'test',
      POLITIKS_TEST_AUTH: 'enabled',
      POLITIKS_TEST_AUTH_BOOTSTRAP: path.resolve(__dirname, 'tests/support/TestGoogleTokenVerifier.php'),
      GOOGLE_CLIENT_ID: 'playwright-client.apps.googleusercontent.com',
    },
  },
});
