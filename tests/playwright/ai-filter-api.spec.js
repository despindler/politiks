const { test, expect } = require('@playwright/test');

async function stubGoogleIdentity(page) {
  await page.route('https://accounts.google.com/gsi/client', (route) => route.fulfill({
    contentType: 'application/javascript; charset=utf-8',
    body: `window.google={accounts:{id:{initialize(options){window.__login=options.callback},renderButton(container){const button=document.createElement('button');button.type='button';button.className='btn btn-light border w-100';button.textContent='Mit Google anmelden';button.onclick=()=>window.__login({credential:'playwright-valid-google-credential'});container.replaceChildren(button)}}}};`,
  }));
}

test('AI filter endpoint enforces authentication, CSRF, ownership, scope and deterministic cache reuse', async ({ page, request }) => {
  const anonymousSession = await request.get('/api/session').then((response) => response.json());
  const anonymous = await request.post('/api/insights/bbbbbbbbbbbbbbbbbbbbbbbbbb/ai-filter', {
    data: { criterion: 'Steuerentlastung', member_ids: [910101] },
  });
  expect(anonymous.status()).toBe(403);
  await expect(anonymous.json()).resolves.toMatchObject({ error_code: 'CSRF_FAILED' });
  const unauthenticated = await request.post('/api/insights/bbbbbbbbbbbbbbbbbbbbbbbbbb/ai-filter', {
    headers: { 'X-CSRF-Token': anonymousSession.csrf_token },
    data: { criterion: 'Steuerentlastung', member_ids: [910101] },
  });
  expect(unauthenticated.status()).toBe(401);
  await expect(unauthenticated.json()).resolves.toMatchObject({ error_code: 'AUTHENTICATION_REQUIRED' });

  await stubGoogleIdentity(page);
  await page.goto('/');
  await page.getByRole('button', { name: 'Mit Google anmelden' }).click();
  await expect(page.getByRole('button', { name: 'Neuen Insight erstellen' })).toBeVisible();
  const payload = await page.evaluate(async () => {
    const session = await fetch('/api/session').then((response) => response.json());
    const headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token };
    const created = await fetch('/api/insights', { method: 'POST', headers, body: '{}' }).then((response) => response.json());
    const id = created.insight.public_id;
    await fetch(`/api/insights/${id}/scope`, {
      method: 'PUT',
      headers,
      body: JSON.stringify({
        country_id: 910001,
        legislature_id: 910002,
        chamber_id: 910003,
        party_id: 910004,
        period_from: '2025-01-01',
        period_to: '2025-12-31',
      }),
    });
    const members = await fetch(`/api/insights/${id}/members`).then((response) => response.json());
    const memberIds = members.items.map((item) => item.id);
    const missingCsrf = await fetch(`/api/insights/${id}/ai-filter`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ criterion: 'Steuerentlastung', member_ids: memberIds }),
    });
    const crossOwner = await fetch('/api/insights/aaaaaaaaaaaaaaaaaaaaaaaaaa/ai-filter', {
      method: 'POST', headers,
      body: JSON.stringify({ criterion: 'Steuerentlastung', member_ids: memberIds }),
    });
    const firstResponse = await fetch(`/api/insights/${id}/ai-filter`, {
      method: 'POST', headers,
      body: JSON.stringify({ criterion: 'Vorlagen zu Steuerentlastungen', member_ids: memberIds }),
    });
    const first = await firstResponse.json();
    const secondResponse = await fetch(`/api/insights/${id}/ai-filter`, {
      method: 'POST', headers,
      body: JSON.stringify({ criterion: 'Vorlagen zu Steuerentlastungen', member_ids: memberIds }),
    });
    const second = await secondResponse.json();
    await fetch(`/api/insights/${id}`, { method: 'DELETE', headers, body: '{}' });
    return {
      missingCsrfStatus: missingCsrf.status,
      crossOwnerStatus: crossOwner.status,
      firstStatus: firstResponse.status,
      secondStatus: secondResponse.status,
      first,
      second,
    };
  });

  expect(payload.missingCsrfStatus).toBe(403);
  expect(payload.crossOwnerStatus).toBe(404);
  expect(payload.firstStatus).toBe(200);
  expect(payload.secondStatus).toBe(200);
  expect(payload.first).toMatchObject({
    ok: true,
    filter: {
      cache_hit: false,
      candidate_count: 1,
      matches: [{ id: 910301, cohort_direction: 'yes' }],
      ambiguous: [],
    },
  });
  expect(payload.second.filter.cache_hit).toBe(true);
  expect(payload.second.filter.request_id).not.toBe(payload.first.filter.request_id);
});
