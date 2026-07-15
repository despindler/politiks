const path = require('node:path');
const { test, expect } = require('@playwright/test');

const visualStylePath = path.join(__dirname, 'visual-stability.css');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');

async function stubExternalServices(page, credential) {
  await page.route('https://accounts.google.com/gsi/client', (route) => route.fulfill({
    contentType: 'application/javascript; charset=utf-8',
    body: `window.google={accounts:{id:{initialize(options){window.__login=options.callback},renderButton(container){const button=document.createElement('button');button.type='button';button.className='btn btn-light border w-100';button.textContent='Mit Google anmelden';button.onclick=()=>window.__login({credential:${JSON.stringify(credential)}});container.replaceChildren(button)}}}};`,
  }));
  await page.route('https://www.youtube-nocookie.com/**', (route) => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><title>Video-Vorschau</title><style>body{margin:0;background:#24263b}</style>',
  }));
}

async function loginAndCreate(page) {
  await page.goto('/');
  await page.getByRole('button', { name: 'Mit Google anmelden' }).click();
  await page.getByRole('button', { name: 'Neuen Insight erstellen' }).click();
  await expect(page).toHaveURL(/\/insights\/[a-f0-9]{26}\/bearbeiten$/);
  const publicId = page.url().match(/\/insights\/([a-f0-9]{26})\/bearbeiten$/)[1];
  await page.getByLabel('Rat').selectOption({ label: 'Nationalrat' });
  await page.getByLabel('Formale Partei').selectOption({ label: 'Beispielpartei Schweiz' });
  await page.getByRole('button', { name: /Mitglieder auswählen/ }).click();
  await expect(page.getByRole('tab', { name: /Mitglieder/ })).toHaveAttribute('aria-selected', 'true', { timeout: 15_000 });
  await expect(page.getByText('4 von 4 wählbaren Mitgliedern ausgewählt')).toBeVisible({ timeout: 15_000 });
  await page.getByRole('tab', { name: /Einordnung/ }).click();
  return publicId;
}

async function addImage(page, label = 'Wahlplakat 2023') {
  await page.getByRole('button', { name: 'Bild hochladen' }).click();
  const modal = page.locator('#context-modal');
  await modal.getByLabel('Bilddatei').setInputFiles({ name: 'campaign.php.png', mimeType: 'image/png', buffer: png });
  await modal.getByLabel('Bezeichnung').fill(label);
  await modal.getByLabel('Urheberangabe').fill('Beispielpartei Schweiz');
  await modal.getByLabel('Quellenlink (optional)').fill('https://example.org/plakat');
  await modal.getByLabel('Beschreibung').fill('Ein nutzerbereitgestelltes Kampagnenmotiv.');
  await modal.getByRole('button', { name: 'Speichern' }).click();
  await expect(modal).toBeHidden();
}

async function addYouTube(page) {
  await page.getByRole('button', { name: 'YouTube-Video' }).click();
  const modal = page.locator('#context-modal');
  await modal.getByLabel('YouTube-Adresse').fill('https://youtu.be/dQw4w9WgXcQ?t=4');
  await modal.getByLabel('Bezeichnung').fill('Kampagnenrede');
  await modal.getByLabel('Urheberangabe').fill('Beispielkanal');
  await modal.getByRole('button', { name: 'Speichern' }).click();
  await expect(modal).toBeHidden();
}

async function addLink(page, malicious = false) {
  await page.getByRole('button', { name: 'Weblink' }).click();
  const modal = page.locator('#context-modal');
  await modal.getByLabel('Webadresse').fill('https://example.org/wahlprogramm');
  await modal.getByLabel('Bezeichnung').fill(malicious ? '<img src=x onerror=alert(1)>' : 'Wahlprogramm');
  await modal.getByLabel('Beschreibung').fill(malicious ? '<script>window.__storedXss=true</script>' : 'Programmatische Einordnung der Partei.');
  await modal.getByRole('button', { name: 'Speichern' }).click();
  await expect(modal).toBeHidden();
}

async function archive(page, publicId) {
  const status = await page.evaluate(async (id) => {
    const session = await fetch('/api/session').then((response) => response.json());
    const headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token };
    const contexts = await fetch(`/api/insights/${id}/contexts`).then((response) => response.json());
    for (const context of contexts.items || []) {
      await fetch(`/api/insights/${id}/contexts/${context.id}`, { method: 'DELETE', headers, body: '{}' });
    }
    return fetch(`/api/insights/${id}`, {
      method: 'DELETE', headers, body: '{}',
    }).then((response) => response.status);
  }, publicId);
  expect(status).toBe(200);
}

test.beforeEach(async ({ page }, testInfo) => {
  await stubExternalServices(
    page,
    `playwright-valid-google-credential-context-${testInfo.project.name}-${testInfo.workerIndex}`,
  );
});

test('image, YouTube and link context are validated, safely rendered, reordered and shared', async ({ page }) => {
  test.setTimeout(60_000);
  const publicId = await loginAndCreate(page);
  try {
    await page.getByRole('button', { name: 'Bild hochladen' }).click();
    let modal = page.locator('#context-modal');
    await modal.getByLabel('Bilddatei').setInputFiles({ name: 'attack.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') });
    await modal.getByRole('button', { name: 'Speichern' }).click();
    await expect(modal.getByRole('alert')).toContainText('JPEG-, PNG- oder WebP-Bilder');
    await modal.getByRole('button', { name: 'Schliessen' }).click();

    await addImage(page);

    await page.getByRole('button', { name: 'YouTube-Video' }).click();
    modal = page.locator('#context-modal');
    await modal.getByLabel('YouTube-Adresse').fill('https://example.org/watch?v=dQw4w9WgXcQ');
    await modal.getByRole('button', { name: 'Speichern' }).click();
    await expect(modal.getByRole('alert')).toContainText('youtube.com/watch');
    await modal.getByRole('button', { name: 'Schliessen' }).click();

    await addYouTube(page);
    await addLink(page, true);
    const list = page.locator('[data-context-list]');
    await expect(list.locator('.context-card')).toHaveCount(3);
    await expect(list.getByText('<img src=x onerror=alert(1)>')).toBeVisible();
    await expect(list.locator('script, [onerror]')).toHaveCount(0);
    expect(await page.evaluate(() => window.__storedXss)).toBeUndefined();
    const imageResponse = await page.request.get(await list.locator('img.context-image').getAttribute('src'));
    expect(imageResponse.status()).toBe(200);
    expect(imageResponse.headers()['content-type']).toBe('image/png');
    await list.locator('.context-card').nth(2).getByRole('button', { name: 'Nach oben' }).click();
    await expect(list.locator('.context-card').nth(1)).toContainText('<img src=x onerror=alert(1)>');

    await page.getByRole('tab', { name: /Prüfen/ }).click();
    await expect(page.locator('[data-review-summary]')).toContainText('3 Elemente · nutzerbereitgestellt');
    await page.getByLabel('Sichtbarkeit').selectOption('unlisted');
    await page.getByRole('button', { name: 'Insight speichern' }).click();
    const shareInput = page.locator('[data-wizard-share-url]');
    await expect(shareInput).toHaveValue(/\/geteilt\/[A-Za-z0-9_-]{43}$/);
    const shareUrl = await shareInput.inputValue();
    await page.goto(shareUrl);
    await expect(page.getByText('Kampagnenkontext · nutzerbereitgestellt')).toBeVisible();
    await expect(page.locator('.public-context-card')).toHaveCount(3);
    await expect(page.locator('.public-context-card iframe')).toHaveAttribute('src', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
    await expect(page.locator('.public-context-card img')).toBeVisible();
  } finally {
    await archive(page, publicId);
  }
});

test('@visual campaign context remains composed in both themes', async ({ page }, testInfo) => {
  test.setTimeout(60_000);
  const publicId = await loginAndCreate(page);
  try {
    await addImage(page);
    await addYouTube(page);
    await addLink(page);
    await page.locator('img.context-image').evaluate((image) => {
      image.style.visibility = 'hidden';
      image.parentElement.style.background = '#050505';
    });
    await page.locator('.context-video iframe').evaluate((frame) => { frame.style.visibility = 'hidden'; });
    await page.locator('.app-navbar').evaluate((element) => { element.style.display = 'none'; });
    const target = page.locator('#wizard-step-4');
    for (const theme of ['light', 'dark']) {
      await page.evaluate((value) => { localStorage.setItem('politiks-theme', value); document.documentElement.dataset.bsTheme = value; }, theme);
      await expect(target).toHaveScreenshot(`campaign-context-${testInfo.project.name}-${theme}.png`, {
        animations: 'disabled', stylePath: visualStylePath,
      });
    }
  } finally {
    await archive(page, publicId);
  }
});
