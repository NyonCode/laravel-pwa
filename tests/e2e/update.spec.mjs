import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { artisan, controlled } from './support.mjs';

// The worker that testbench serves after pwa:build.
const COMPILED = fileURLToPath(new URL('../../vendor/orchestra/testbench-core/laravel/bootstrap/cache/pwa/sw.js', import.meta.url));

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  artisan('pwa:enable');
  artisan('up');
  artisan('pwa:build');
});

test.afterAll(() => {
  artisan('pwa:clear');
});

test('a new worker waits for the update toast, then takes over and reloads', async ({ page }) => {
  await page.goto('/components');
  await controlled(page);
  const before = await page.evaluate(() => window.Pwa.version());
  const toast = page.locator('[data-pwa-update]');
  await expect(toast).toBeHidden();

  // Deploy: a new build with another version.
  const next = before === 'abcdef012345' ? '0123456789ab' : 'abcdef012345';
  writeFileSync(COMPILED, readFileSync(COMPILED, 'utf8').replace(`"version":"${before}"`, `"version":"${next}"`));

  await page.evaluate(() => window.Pwa.update());
  await expect(toast).toBeVisible();
  await expect(page.locator('.pwa-bar')).toHaveCount(0); // the toast replaces the built-in bar
  expect(await page.evaluate(() => window.Pwa.version())).toBe(before);

  await Promise.all([page.waitForEvent('load'), toast.locator('[data-pwa-update-apply]').click()]);
  await controlled(page);
  expect(await page.evaluate(() => window.Pwa.version())).toBe(next);
  await expect(toast).toBeHidden();
});
