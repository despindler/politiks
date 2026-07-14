const path = require('node:path');
const { test, expect } = require('@playwright/test');

const visualStylePath = path.join(__dirname, 'visual-stability.css');

async function stubGoogleIdentity(page) {
  await page.route('https://accounts.google.com/gsi/client', (route) => route.fulfill({
    contentType: 'application/javascript; charset=utf-8',
    body: `window.google={accounts:{id:{initialize(options){window.__login=options.callback},renderButton(container){const button=document.createElement('button');button.type='button';button.className='btn btn-light border w-100';button.textContent='Mit Google anmelden';button.onclick=()=>window.__login({credential:'playwright-valid-google-credential'});container.replaceChildren(button)}}}};`,
  }));
}

async function loginAndCreate(page) {
  await page.goto('/');
  await page.getByRole('button', { name: 'Mit Google anmelden' }).click();
  await page.getByRole('button', { name: 'Neuen Insight erstellen' }).click();
  await expect(page).toHaveURL(/\/insights\/[a-f0-9]{26}\/bearbeiten$/);
  return page.url().match(/\/insights\/([a-f0-9]{26})\/bearbeiten$/)[1];
}

async function configureScope(page) {
  await page.getByLabel('Rat').selectOption({ label: 'Nationalrat' });
  await page.getByLabel('Formale Partei').selectOption({ label: 'Beispielpartei Schweiz' });
  await page.getByRole('button', { name: /Mitglieder auswählen/ }).click();
  await expect(page.getByText('4 von 4 wählbaren Mitgliedern ausgewählt')).toBeVisible();
}

async function archiveCurrent(page, publicId) {
  const status = await page.evaluate(async (id) => {
    const session = await fetch('/api/session').then((response) => response.json());
    return fetch(`/api/insights/${id}`, {
      method: 'DELETE', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token }, body: '{}',
    }).then((response) => response.status);
  }, publicId);
  expect(status).toBe(200);
}

function voteCard(page, column, title) {
  return page.locator(`[data-votes-${column}] details.vote-card`).filter({ hasText: title });
}

test.beforeEach(async ({ page }) => {
  await stubGoogleIdentity(page);
});

test('wizard synchronizes the cohort, regroups votes, retains evidence, resumes, and publishes', async ({ page }, testInfo) => {
  test.setTimeout(60_000);
  const publicId = await loginAndCreate(page);
  const title = `Kohortenanalyse ${testInfo.project.name}`;
  try {
    await configureScope(page);
    await page.getByRole('button', { name: /Abstimmungen untersuchen/ }).click();
    await expect(page.locator('[data-vote-status]')).toContainText('5 Abstimmungen');
    await expect(voteCard(page, 'yes', 'Steuerentlastung und Gegenfinanzierung')).toBeVisible();
    await expect(voteCard(page, 'no', 'Ausbau der sozialen Grundversorgung')).toHaveCount(1);
    await expect(voteCard(page, 'neutral', 'Regulierung digitaler Plattformen')).toHaveCount(1);

    const inspectedVote = voteCard(page, 'yes', 'Steuerentlastung und Gegenfinanzierung');
    await inspectedVote.locator('summary').click();
    await expect(inspectedVote).toContainText('Wollen Sie der Vorlage zustimmen?');
    await expect(inspectedVote).toContainText('Gesamtergebnis des Rates: Angenommen');
    await expect(inspectedVote).toContainText('Offiziell: Wirtschaft');
    await expect(inspectedVote).toContainText('Geprüft: Wirtschaftspolitik');
    await expect(inspectedVote).toContainText('Carla Probe — Beispielpartei Schweiz · Beispielfraktion · Abweichende Stimme');
    await page.getByText('Weitere Filter').click();
    await page.getByLabel('Abstimmungstyp').selectOption('final_vote');
    await expect(voteCard(page, 'yes', 'Steuerentlastung und Gegenfinanzierung')).toHaveCount(1);
    await expect(voteCard(page, 'no', 'Ausbau der sozialen Grundversorgung')).toHaveCount(0);
    await page.getByLabel('Abstimmungstyp').selectOption('all');
    await page.getByLabel('Geprüfte Klassifikation').selectOption({ label: 'Wirtschaftspolitik' });
    await expect(voteCard(page, 'yes', 'Steuerentlastung und Gegenfinanzierung')).toHaveCount(1);
    await expect(voteCard(page, 'neutral', 'Regulierung digitaler Plattformen')).toHaveCount(0);
    await page.getByLabel('Geprüfte Klassifikation').selectOption('all');
    await page.getByLabel('Abweichende Stimme').selectOption({ label: 'Carla Probe' });
    await expect(voteCard(page, 'yes', 'Steuerentlastung und Gegenfinanzierung')).toHaveCount(1);
    await expect(voteCard(page, 'no', 'Ausbau der sozialen Grundversorgung')).toHaveCount(1);
    await page.getByLabel('Abweichende Stimme').selectOption('all');

    await page.getByRole('button', { name: 'Auswertung öffnen' }).click();
    const carlaOutlier = page.locator('[data-outlier-panel]').getByText('Carla Probe').locator('..');
    await expect(carlaOutlier).toContainText('2 abweichend');

    await page.getByLabel('Abstimmungen und Geschäfte durchsuchen').fill('NR:TEST-5');
    await page.getByRole('button', { name: 'Suchen' }).click();
    await expect(page.locator('[data-vote-status]')).toContainText('1 Abstimmungen');
    await expect(voteCard(page, 'no', 'Förderung erneuerbarer Energien')).toHaveCount(1);

    if (await page.locator('[data-cohort-editor]').isHidden()) {
      await page.locator('[data-cohort-toggle]').click();
    }
    const cohort = page.locator('[data-cohort-list]');
    await cohort.getByLabel('Carla Probe').uncheck();
    await expect(voteCard(page, 'neutral', 'Förderung erneuerbarer Energien')).toHaveCount(1);
    await cohort.getByLabel('Bruno Muster').uncheck();
    await expect(voteCard(page, 'yes', 'Förderung erneuerbarer Energien')).toHaveCount(1);
    await expect(page.getByLabel('Abstimmungen und Geschäfte durchsuchen')).toHaveValue('NR:TEST-5');
    await page.locator('[data-votes-yes]').getByRole('button', { name: 'Als Evidenz auswählen' }).click();
    await expect(page.locator('[data-evidence-count]')).toHaveText('1');

    await page.getByRole('tab', { name: /Mitglieder/ }).click();
    const members = page.locator('[data-member-list]');
    await expect(members.getByLabel('Carla Probe')).not.toBeChecked();
    await members.getByLabel('Bruno Muster').check();
    await page.getByRole('tab', { name: /Abstimmungen/ }).click();
    await expect(voteCard(page, 'neutral', 'Förderung erneuerbarer Energien')).toHaveCount(1);
    if (await page.locator('[data-cohort-editor]').isHidden()) {
      await page.locator('[data-cohort-toggle]').click();
    }
    await page.locator('[data-cohort-list]').getByLabel('Bruno Muster').uncheck();
    await page.getByRole('button', { name: /Zurücksetzen/ }).click();
    await expect(page.locator('[data-cohort-list]').getByLabel('Bruno Muster')).toBeChecked();

    await page.getByRole('tab', { name: /Einordnung/ }).click();
    await page.getByLabel('Titel').fill(title);
    await page.getByLabel('Aussage').fill('Die ausgewählten Abstimmungen zeigen, wie sich die Bewertung mit dem betrachteten Mitgliederkreis verändert.');
    await page.getByLabel('Erläuterung und Einschränkungen').fill('Die Richtung bezieht sich ausschliesslich auf den gewählten Mitgliederkreis.');
    await page.getByRole('tab', { name: /Prüfen/ }).click();
    await expect(page.locator('[data-review-summary]')).toContainText('1 Abstimmungen ausgewählt');
    await page.getByRole('button', { name: 'Insight speichern' }).click();
    await expect(page).toHaveURL(/\/#meine-insights$/);

    const card = page.locator('[data-mine-list] article.owner-card').filter({ hasText: title });
    await expect(card).toBeVisible();
    await card.getByRole('link', { name: 'Im Assistenten bearbeiten' }).click();
    await page.getByRole('tab', { name: /Abstimmungen/ }).click();
    await expect(page.locator('[data-evidence-count]')).toHaveText('1');
    await page.getByRole('tab', { name: /Einordnung/ }).click();
    await expect(page.getByLabel('Titel')).toHaveValue(title);
    await page.getByRole('tab', { name: /Prüfen/ }).click();
    await page.getByLabel('Sichtbarkeit').selectOption('public');
    await page.getByRole('button', { name: 'Insight speichern' }).click();
    await expect(page).toHaveURL(/\/#meine-insights$/);
    await expect(page.locator('[data-public-list]').getByText(title)).toBeVisible();
  } finally {
    await archiveCurrent(page, publicId);
  }
});

test('public validation opens and focuses the first invalid wizard step', async ({ page }) => {
  const publicId = await loginAndCreate(page);
  try {
    await configureScope(page);
    await page.getByRole('tab', { name: /Prüfen/ }).click();
    await page.getByLabel('Sichtbarkeit').selectOption('public');
    await page.getByRole('button', { name: 'Insight speichern' }).click();
    const alert = page.locator('[data-validation-alert]');
    await expect(alert).toBeVisible();
    await expect(alert).toContainText('Mindestens eine Abstimmung als Evidenz auswählen');
    await expect(page.getByRole('tab', { name: /Abstimmungen/ })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByLabel('Abstimmungen und Geschäfte durchsuchen')).toBeFocused();
  } finally {
    await archiveCurrent(page, publicId);
  }
});

test('wizard state is unavailable anonymously and to another insight owner', async ({ page, request }) => {
  const anonymous = await request.get('/api/insights/bbbbbbbbbbbbbbbbbbbbbbbbbb/wizard');
  expect(anonymous.status()).toBe(401);
  await page.goto('/');
  await page.getByRole('button', { name: 'Mit Google anmelden' }).click();
  await expect(page.getByRole('button', { name: 'Neuen Insight erstellen' })).toBeVisible();
  const crossOwnerStatus = await page.evaluate(() => fetch('/api/insights/aaaaaaaaaaaaaaaaaaaaaaaaaa/wizard').then((response) => response.status));
  expect(crossOwnerStatus).toBe(404);
});

test('@visual vote workspace remains composed in light and dark modes', async ({ page }, testInfo) => {
  const publicId = await loginAndCreate(page);
  try {
    await configureScope(page);
    await page.getByRole('button', { name: /Abstimmungen untersuchen/ }).click();
    await expect(page.locator('[data-vote-status]')).toContainText('5 Abstimmungen');
    await page.locator('.app-navbar').evaluate((element) => { element.style.display = 'none'; });
    await page.locator('.cohort-panel, [data-evidence-tray]').evaluateAll((elements) => elements.forEach((element) => { element.style.position = 'static'; }));
    const screenshotTarget = page.locator('#wizard-step-3');
    for (const theme of ['light', 'dark']) {
      await page.evaluate((value) => { localStorage.setItem('politiks-theme', value); document.documentElement.dataset.bsTheme = value; }, theme);
      await expect(screenshotTarget).toHaveScreenshot(`wizard-votes-${testInfo.project.name}-${theme}.png`, {
        animations: 'disabled',
        stylePath: visualStylePath,
      });
    }
  } finally {
    await archiveCurrent(page, publicId);
  }
});
