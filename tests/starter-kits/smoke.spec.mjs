// The package inside a Laravel starter kit (KIT = livewire | react | vue), set up as the
// documentation says (tests/starter-kits/setup.php). Catches what the workbench cannot: the kits'
// own configs, layouts, login flows and frontend stacks.
import { expect, test } from '@playwright/test';

const KIT = process.env.KIT;
const INERTIA = KIT === 'react' || KIT === 'vue';

test.describe.configure({ mode: 'serial' });

/** Page errors, failed scripts and 5xx answers anywhere in the test fail it. */
const watch = (page) => {
  const problems = [];
  page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
  page.on('response', (response) => {
    if (response.status() >= 500) problems.push(`${response.status()} ${response.request().method()} ${response.url()}`);
  });
  return problems;
};

const login = async (page) => {
  await page.goto('/login');
  await page.locator('input[type=email]').fill('test@example.com');
  await page.locator('input[type=password]').fill('password');
  await Promise.all([page.waitForURL('**/dashboard'), page.locator('form button[type=submit]').first().click()]);
};

const controlled = async (page) => {
  await page.evaluate(() => navigator.serviceWorker.ready);
  await page.reload();
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
};

/** Paths stored in the per-user page caches. */
const cachedPages = (page) => page.evaluate(async () => {
  const paths = [];
  for (const name of (await caches.keys()).filter((key) => key.startsWith('pwa-pages-'))) {
    for (const request of await (await caches.open(name)).keys()) paths.push(new URL(request.url).pathname);
  }
  return paths;
});

const logout = async (page) => {
  const csrf = await page.evaluate(async () => (await (await fetch('/pwa/session', { headers: { Accept: 'application/json' } })).json()).csrf);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/dashboard')),
    page.evaluate((token) => {
      const form = Object.assign(document.createElement('form'), { method: 'POST', action: '/logout' });
      form.append(Object.assign(document.createElement('input'), { type: 'hidden', name: '_token', value: token }));
      document.body.append(form);
      form.submit();
    }, csrf),
  ]);
};

test('serves a valid manifest and the service worker', async ({ request }) => {
  const manifest = await (await request.get('/manifest.webmanifest')).json();
  expect(manifest.name).toBeTruthy();
  expect(manifest.icons.map((icon) => icon.sizes)).toEqual(expect.arrayContaining(['192x192', '512x512']));

  const worker = await request.get('/sw.js');
  expect(worker.status()).toBe(200);
  expect(worker.headers()['service-worker-allowed']).toBeTruthy();
});

test('signs in, keeps the session alive and stores the dashboard for this user', async ({ page }) => {
  const problems = watch(page);
  await login(page);
  await controlled(page);

  const session = await page.evaluate(async () => {
    const response = await fetch('/pwa/session', { headers: { Accept: 'application/json' } });
    return { status: response.status, body: await response.json() };
  });
  expect(session.status).toBe(200);
  expect(session.body).toMatchObject({ authenticated: true });
  expect(typeof session.body.lifetime).toBe('number');

  await expect.poll(() => cachedPages(page)).toContain('/dashboard');
  expect(problems).toEqual([]);
});

test('opens the stored dashboard offline and shows the offline screen elsewhere', async ({ page, context }) => {
  const problems = watch(page);
  await login(page);
  await controlled(page);
  await expect.poll(() => cachedPages(page)).toContain('/dashboard');

  await context.setOffline(true);
  await page.reload();
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page).not.toHaveTitle(/offline/i);

  await page.goto('/settings/profile');
  await expect(page).toHaveTitle(/offline/i);
  await context.setOffline(false);
  expect(problems).toEqual([]);
});

test('Inertia: a form sent offline is queued and sent once the connection is back', async ({ page, context }) => {
  test.skip(!INERTIA, 'Inertia kits only');
  const problems = watch(page);
  await login(page);
  await controlled(page);
  await page.goto('/settings/profile');
  const name = page.locator('input#name, input[name="name"]').first();
  const queued = `Queued ${Date.now()}`;
  await name.fill(queued);

  await context.setOffline(true);
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.locator('.pwa-bar')).toContainText(/will be sent/i);

  await context.setOffline(false);
  await expect.poll(async () => {
    await page.reload();
    return page.locator('input#name, input[name="name"]').first().inputValue();
  }, { timeout: 15_000 }).toBe(queued);
  expect(problems).toEqual([]);
});

test('Livewire: an action sent offline is not queued and the banner says so', async ({ page, context }) => {
  test.skip(INERTIA, 'Livewire kit only');
  const problems = watch(page);
  // The login form is a Livewire component (the kit's settings pages need a layout the latest
  // Volt kit does not ship with Livewire 4, so they answer 500 without the package too).
  await page.goto('/login');
  await controlled(page);
  await page.locator('input[type=email]').fill('test@example.com');
  await page.locator('input[type=password]').fill('password');

  await context.setOffline(true);
  await page.locator('form button[type=submit]').first().click();
  await expect(page.locator('.pwa-bar')).toContainText(/not sent/i);
  await expect(page).toHaveURL(/\/login$/);
  await context.setOffline(false);
  expect(problems).toEqual([]);
});

test('signing out deletes the user\'s pages from the cache', async ({ page }) => {
  const problems = watch(page);
  await login(page);
  await controlled(page);
  await expect.poll(() => cachedPages(page)).toContain('/dashboard');

  await logout(page);
  await expect.poll(() => cachedPages(page)).toEqual([]);
  expect(problems).toEqual([]);
});
