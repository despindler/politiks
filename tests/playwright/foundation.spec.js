const { test, expect } = require('@playwright/test');

test('foundation page is served from the deployment root', async ({ page }) => {
  const response = await page.goto('/');

  expect(response).not.toBeNull();
  expect(response.ok()).toBeTruthy();
  await expect(page).toHaveTitle('Politiks');
  await expect(page.getByRole('heading', { level: 1, name: 'Politiks' })).toBeVisible();
  await expect(page.getByText('Die technische Grundlage ist bereit.')).toBeVisible();
});
