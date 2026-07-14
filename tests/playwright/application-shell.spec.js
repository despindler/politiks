const path = require('node:path');
const { test, expect } = require('@playwright/test');

const visualStylePath = path.join(__dirname, 'visual-stability.css');

async function stubGoogleIdentity(page) {
  await page.route('https://accounts.google.com/gsi/client', async (route) => {
    await route.fulfill({
      contentType: 'application/javascript; charset=utf-8',
      body: `
        window.google = { accounts: { id: {
          initialize(options) { window.__politiksGoogleCallback = options.callback; },
          renderButton(container) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-light border w-100';
            button.textContent = 'Mit Google anmelden';
            button.addEventListener('click', () => window.__politiksGoogleCallback({
              credential: 'playwright-valid-google-credential'
            }));
            container.replaceChildren(button);
          }
        } } };
      `,
    });
  });
}

test.beforeEach(async ({ page }) => {
  await stubGoogleIdentity(page);
});

test('signed-out shell uses local assets and exposes accessible navigation', async ({ page }) => {
  const externalRequests = [];
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.origin !== 'http://127.0.0.1:8080' && url.hostname !== 'accounts.google.com') {
      externalRequests.push(request.url());
    }
  });
  const response = await page.goto('/');
  expect(response).not.toBeNull();
  const headers = response.headers();
  expect(headers['content-security-policy']).toContain("frame-ancestors 'none'");
  expect(headers['content-security-policy']).toContain('https://accounts.google.com/gsi/client');
  expect(headers['x-content-type-options']).toBe('nosniff');
  expect(headers['x-frame-options']).toBe('DENY');
  await expect(page).toHaveTitle(/Politiks/);
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Wie ihre Mitglieder abstimmen');
  await expect(page.getByRole('button', { name: 'Mit Google anmelden' })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Hauptnavigation' })).toBeVisible();
  await expect(page.locator('link[href="/assets/vendor/bootstrap/bootstrap.min.css"]')).toHaveCount(1);
  expect(externalRequests).toEqual([]);
});

test('Google login changes the session ID and logout invalidates authentication', async ({ page, context }) => {
  await page.goto('/');
  await expect(page.getByRole('button', { name: 'Mit Google anmelden' })).toBeVisible();
  const beforeCookie = (await context.cookies()).find((cookie) => cookie.name === 'politiks_session');
  expect(beforeCookie).toBeTruthy();

  await page.getByRole('button', { name: 'Mit Google anmelden' }).click();
  await expect(page.getByText('Grüezi, Mara Muster')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Meine Insights' })).toBeVisible();
  const afterCookie = (await context.cookies()).find((cookie) => cookie.name === 'politiks_session');
  expect(afterCookie).toBeTruthy();
  expect(afterCookie.value).not.toBe(beforeCookie.value);
  expect(afterCookie.httpOnly).toBe(true);
  expect(afterCookie.sameSite).toBe('Lax');

  const navigationToggle = page.getByRole('button', { name: 'Navigation öffnen' });
  if (await navigationToggle.isVisible()) await navigationToggle.click();
  await page.getByRole('button', { name: 'Abmelden' }).click();
  await expect(page.getByRole('button', { name: 'Mit Google anmelden' })).toBeVisible();
  await expect(page.getByText('Grüezi, Mara Muster')).toBeHidden();
});

test('mutating authentication endpoints enforce CSRF and bounded JSON validation', async ({ request }) => {
  const session = await request.get('/api/session');
  expect(session.ok()).toBe(true);
  const sessionPayload = await session.json();

  const noCsrf = await request.post('/api/google-login', {
    data: { credential: 'playwright-valid-google-credential' },
  });
  expect(noCsrf.status()).toBe(403);
  await expect(noCsrf.json()).resolves.toMatchObject({ ok: false, error_code: 'CSRF_FAILED' });

  const missingCredential = await request.post('/api/google-login', {
    headers: { 'X-CSRF-Token': sessionPayload.csrf_token },
    data: {},
  });
  expect(missingCredential.status()).toBe(422);
  await expect(missingCredential.json()).resolves.toMatchObject({
    ok: false,
    error_code: 'GOOGLE_CREDENTIAL_REQUIRED',
  });

  const invalidCredential = await request.post('/api/google-login', {
    headers: { 'X-CSRF-Token': sessionPayload.csrf_token },
    data: { credential: 'invalid-test-credential' },
  });
  expect(invalidCredential.status()).toBe(401);
  await expect(invalidCredential.json()).resolves.toMatchObject({
    ok: false,
    error_code: 'INVALID_GOOGLE_TOKEN',
  });
});

test('disabled Google configuration is presented without a login button', async ({ page }) => {
  await page.route('**/api/auth-config', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, google_client_id: null }),
  }));
  await page.goto('/');
  await expect(page.getByText('Die Anmeldung ist derzeit nicht konfiguriert.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Mit Google anmelden' })).toHaveCount(0);
});

test('theme choice persists across navigation', async ({ page }) => {
  await page.goto('/');
  const navigationToggle = page.getByRole('button', { name: 'Navigation öffnen' });
  if (await navigationToggle.isVisible()) await navigationToggle.click();
  await page.getByRole('button', { name: 'Farbschema wählen' }).click();
  await page.locator('[data-theme-value="dark"]').click();
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
  if (await navigationToggle.isVisible()) await navigationToggle.click();
  await expect(page.getByRole('button', { name: 'Farbschema wählen' })).toContainText('Dunkel');
});

test('private runtime paths are not retrievable', async ({ request }) => {
  for (const path of [
    '/backend/Config.php',
    '/database/schema.sql',
    '/storage/cache/google-jwks.json',
    '/storage/logs/application.log',
    '/logs/application.log',
    '/.env',
    '/.env.test',
  ]) {
    const response = await request.get(path);
    expect(response.status(), path).toBe(404);
    expect(await response.text()).not.toContain('<?php');
  }
});

test('@visual signed-out shell remains composed in light and dark themes', async ({ page }, testInfo) => {
  for (const theme of ['light', 'dark']) {
    await page.addInitScript((value) => localStorage.setItem('politiks-theme', value), theme);
    await page.goto('/');
    await expect(page.getByRole('button', { name: 'Mit Google anmelden' })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', theme);
    await page.evaluate(() => document.fonts.ready);
    await expect(page).toHaveScreenshot(`shell-${testInfo.project.name}-${theme}.png`, {
      fullPage: true,
      animations: 'disabled',
      stylePath: visualStylePath,
    });
  }
});

test('@visual signed-in shell remains composed in light and dark themes', async ({ page }, testInfo) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Mit Google anmelden' }).click();
  await expect(page.getByText('Grüezi, Mara Muster')).toBeVisible();
  for (const theme of ['light', 'dark']) {
    await page.evaluate((value) => localStorage.setItem('politiks-theme', value), theme);
    await page.reload();
    await expect(page.getByText('Grüezi, Mara Muster')).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', theme);
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(800);
    await page.evaluate(() => window.scrollTo(0, 0));
    await expect(page).toHaveScreenshot(`shell-authenticated-${testInfo.project.name}-${theme}.png`, {
      animations: 'disabled',
      stylePath: visualStylePath,
    });
  }
});
