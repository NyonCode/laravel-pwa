import { expect, test } from '@playwright/test';
import { artisan } from './support.mjs';

// iPhone Safari outside the Home Screen app (project "iphone"). Safari hides the push API there;
// Playwright's WebKit exposes it, so the page gets the real Safari behaviour from an init script.
test.beforeAll(() => {
  artisan('pwa:enable');
  artisan('up');
});

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => { delete window.PushManager; });
});

test('shows the Share → Add to Home Screen steps instead of an install button', { tag: '@ios' }, async ({ page }) => {
  await page.goto('/components'); // install.min_visits = 2
  await page.goto('/components');

  const sheet = page.locator('[data-pwa-ios-hint]');
  await expect(sheet).toBeVisible();
  await expect(sheet.locator('ol li')).toHaveCount(2);
  await expect(page.locator('[data-pwa-install]')).toBeHidden();
  expect(await page.evaluate(() => [window.Pwa.isIos(), window.Pwa.canInstall()])).toEqual([true, true]);

  await sheet.locator('[data-pwa-install-dismiss]').click();
  await expect(sheet).toBeHidden();
  await page.reload();
  await expect(sheet).toBeHidden();
});

test('push asks to add the app to the Home Screen first', { tag: '@ios' }, async ({ page }) => {
  await page.goto('/components');

  const toggle = page.locator('[data-pwa-push]');
  await expect(toggle).toHaveAttribute('data-pwa-push-state', 'install-required');
  await expect(toggle.locator('[data-pwa-push-toggle]')).toBeDisabled();
  await expect(toggle.locator('small[data-pwa-push-label="install-required"]')).toBeVisible();
});
