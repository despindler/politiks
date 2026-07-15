const path = require('node:path');
const { test, expect } = require('@playwright/test');

const visualStylePath = path.join(__dirname, 'visual-stability.css');
const shareToken = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopq';

async function stubGoogleIdentity(page) {
  await page.route('https://accounts.google.com/gsi/client', (route) => route.fulfill({
    contentType: 'application/javascript; charset=utf-8',
    body: `window.google={accounts:{id:{initialize(options){window.__login=options.callback},renderButton(container){const button=document.createElement('button');button.type='button';button.className='btn btn-light border w-100';button.textContent='Mit Google anmelden';button.onclick=()=>window.__login({credential:'playwright-valid-google-credential'});container.replaceChildren(button)}}}};`,
  }));
}

test.beforeEach(async ({ page }) => {
  await stubGoogleIdentity(page);
});

test('public catalogue excludes non-public work and accordion is keyboard-operable', async ({ page }) => {
  await page.goto('/');
  const publicList = page.locator('[data-public-list]');
  await expect(publicList.getByText('Versprechen und Abstimmungsverhalten im Vergleich')).toBeVisible();
  await expect(publicList.getByText('Mein veröffentlichter Insight')).toBeVisible();
  await expect(publicList.getByText('Mein erster Entwurf')).toHaveCount(0);
  await expect(publicList.getByText('Analyse für mein Team')).toHaveCount(0);

  const toggle = publicList.getByRole('button', { name: /Versprechen und Abstimmungsverhalten/ });
  await toggle.focus();
  await page.keyboard.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(publicList.getByText('Parlamentarische Evidenz')).toBeVisible();
});

test('owner sees every visibility state and creates a protected wizard draft', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Mit Google anmelden' }).click();
  const mine = page.locator('[data-mine-list]');
  await expect(mine.getByText('Mein erster Entwurf')).toBeVisible();
  await expect(mine.getByText('Analyse für mein Team')).toBeVisible();
  await expect(mine.getByText('Mein veröffentlichter Insight')).toBeVisible();

  await page.getByRole('button', { name: 'Neuen Insight erstellen' }).click();
  await expect(page).toHaveURL(/\/insights\/[a-f0-9]{26}\/bearbeiten$/);
  await expect(page.getByRole('heading', { name: 'Parlamentarischen Rahmen wählen' })).toBeVisible();
  await page.goto('/#meine-insights');
  const draft = mine.locator('article.owner-card').filter({ hasText: 'Unbenannter Insight' });
  await expect(draft).toBeVisible();
  page.once('dialog', async (dialog) => {
    expect(dialog.message()).toContain('endgültig löschen');
    await dialog.accept();
  });
  await draft.getByRole('button', { name: 'Löschen' }).click();
  await expect(draft).toHaveCount(0);
});

test('unlisted share page works without login and emits indexing protection', async ({ page }) => {
  const response = await page.goto(`/geteilt/${shareToken}`);
  expect(response.status()).toBe(200);
  expect(response.headers()['x-robots-tag']).toContain('noindex');
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  await expect(page.getByRole('heading', { name: 'Geteilter Insight' })).toBeVisible();
  await expect(page.getByText('Analyse für mein Team')).toBeVisible();
  await expect(page.getByText('Dieser Insight ist nur über seinen Link sichtbar.')).toBeVisible();
});

test('insight API rejects anonymous mutation and malformed pagination', async ({ request }) => {
  const session = await request.get('/api/session');
  const csrf = (await session.json()).csrf_token;
  const anonymousCreate = await request.post('/api/insights', { headers: { 'X-CSRF-Token': csrf }, data: {} });
  expect(anonymousCreate.status()).toBe(401);
  await expect(anonymousCreate.json()).resolves.toMatchObject({ error_code: 'AUTHENTICATION_REQUIRED' });

  const malformed = await request.get('/api/insights/public?page=first');
  expect(malformed.status()).toBe(422);
  await expect(malformed.json()).resolves.toMatchObject({ error_code: 'INVALID_PAGINATION' });
});

test('@visual catalogue covers populated and empty states', async ({ page }, testInfo) => {
  for (const theme of ['light', 'dark']) {
    await page.addInitScript((value) => localStorage.setItem('politiks-theme', value), theme);
    await page.goto('/');
    await expect(page.getByText('Versprechen und Abstimmungsverhalten im Vergleich')).toBeVisible();
    await expect(page.locator('#insights')).toHaveScreenshot(`catalogue-populated-${testInfo.project.name}-${theme}.png`, {
      animations: 'disabled',
      stylePath: visualStylePath,
    });

    await page.route('**/api/insights/public**', (route) => route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, items: [], pagination: { page: 1, per_page: 6, total: 0, total_pages: 0 } }),
    }));
    await page.reload();
    await expect(page.getByText('Noch keine öffentlichen Insights. Schau bald wieder vorbei.')).toBeVisible();
    await expect(page.locator('#insights')).toHaveScreenshot(`catalogue-empty-${testInfo.project.name}-${theme}.png`, {
      animations: 'disabled',
      stylePath: visualStylePath,
    });
    await page.unroute('**/api/insights/public**');
  }
});
