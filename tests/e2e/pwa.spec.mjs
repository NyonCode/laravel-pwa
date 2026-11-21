import { expect, test } from '@playwright/test';
import { artisan, cachedPages, controlled, queueCount } from './support.mjs';

// One browser context through the whole lifecycle: install → offline → maintenance → kill switch.
test.describe.configure({ mode: 'serial' });

/** @type {import('@playwright/test').BrowserContext} */
let context;
/** @type {import('@playwright/test').Page} */
let page;
const pageErrors = [];

test.beforeAll(async ({ browser }) => {
  artisan('pwa:enable');
  artisan('up');
  context = await browser.newContext();
  page = await context.newPage();
  page.on('pageerror', (error) => pageErrors.push(String(error)));
});

test.afterAll(async () => {
  artisan('up');
  artisan('pwa:enable');
  await context?.close();
});

test('registers the worker and precaches the offline page', async () => {
  await page.goto('/');
  await controlled(page);

  const keys = await page.evaluate(() => caches.keys());
  expect(keys.some((k) => k.startsWith('pwa-static-'))).toBe(true);
  expect(await page.evaluate(() => caches.match('/offline').then((r) => !!r))).toBe(true);
  expect(await page.evaluate(() => typeof window.Pwa.install === 'function' && window.Pwa.displayMode())).toBe('browser');
});

test('caches only pages the server allows (pwa.cache)', async () => {
  await page.goto('/orders');
  await page.goto('/private');

  await expect.poll(() => cachedPages(page)).toContain('/orders');
  expect(await cachedPages(page)).not.toContain('/private');
});

test('offline: cached page from cache, others get the offline screen', { tag: '@offline' }, async () => {
  await context.setOffline(true);

  await page.goto('/orders');
  await expect(page.locator('#title')).toHaveText('Objednávky');

  await page.goto('/private');
  await expect(page.locator('h1')).toHaveText(/You are offline|Jste offline/);
});

test('offline: a mutation is queued and replayed after reconnecting', { tag: '@offline' }, async () => {
  await page.goto('/orders');

  const result = await page.evaluate(() => {
    const token = document.querySelector('meta[name=csrf-token]').content;
    return fetch('/items', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': token },
      body: `name=offline-item&_token=${token}`,
    }).then((r) => ({ status: r.status, queued: r.headers.get('X-PWA-Queued') }));
  });
  expect(result).toEqual({ status: 202, queued: '1' });
  expect(await queueCount(page)).toBe(1);

  await context.setOffline(false);
  await page.evaluate(() => navigator.serviceWorker.controller.postMessage({ type: 'REPLAY_QUEUE' }));

  await expect.poll(() => queueCount(page)).toBe(0);
});

test('cache rules: the catalogue is served from its rule cache offline', { tag: '@offline' }, async () => {
  await page.goto('/orders');
  const online = await page.evaluate(() => fetch('/api/catalog').then((r) => r.json()));
  expect(online.items).toEqual(['A', 'B']);

  await expect.poll(() => page.evaluate(() => caches.keys())).toContain('pwa-rule-catalog-guest');

  await context.setOffline(true);
  const offline = await page.evaluate(() => fetch('/api/catalog').then((r) => r.json()));
  await context.setOffline(false);

  expect(offline.at).toBe(online.at);
});

test('keep-alive returns a CSRF token', async () => {
  const session = await page.evaluate(() => fetch('/pwa/session', { headers: { Accept: 'application/json' } }).then((r) => r.json()));

  expect(session.csrf).toBeTruthy();
  expect(session.user).toBe('guest');
});

test('maintenance: 503 screen, page cache untouched', async () => {
  artisan('pwa:down', '--message=Deploying a new version', '--retry=60');

  const response = await page.goto('/orders');
  expect(response.status()).toBe(503);
  await expect(page.locator('body')).toContainText('Deploying a new version');

  const cachedStatus = await page.evaluate(async () => {
    for (const name of (await caches.keys()).filter((k) => k.startsWith('pwa-pages-'))) {
      const hit = await (await caches.open(name)).match('/orders');
      if (hit) return hit.status;
    }
    return null;
  });
  expect(cachedStatus).toBe(200);

  artisan('up');
  await page.goto('/orders');
  await expect(page.locator('#title')).toHaveText('Objednávky');
});

test('kill switch: pwa:disable removes the worker and caches, pwa:enable restores it', async () => {
  artisan('pwa:disable', '--force');
  await page.goto('/');

  await expect.poll(() => page.evaluate(() => navigator.serviceWorker.getRegistrations().then((r) => r.length)), { timeout: 10_000 }).toBe(0);
  await expect.poll(() => page.evaluate(() => caches.keys().then((k) => k.filter((n) => n.startsWith('pwa-')).length))).toBe(0);

  artisan('pwa:enable');
  await page.goto('/');
  await page.evaluate(() => navigator.serviceWorker.ready);
  expect(await page.evaluate(() => navigator.serviceWorker.getRegistrations().then((r) => r.length))).toBe(1);
});

test('no JavaScript errors on the page', () => {
  expect(pageErrors).toEqual([]);
});
