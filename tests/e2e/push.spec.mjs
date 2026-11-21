import { expect, test } from '@playwright/test';
import { controlled } from './support.mjs';

// Push messages delivered to the real worker through the DevTools protocol (no push service).
// The full Chromium build: the headless shell has no notifications.
test.skip(({ browserName }) => browserName !== 'chromium', 'ServiceWorker.deliverPushMessage is a Chromium DevTools command');
test.use({ channel: 'chromium', permissions: ['notifications'] });

/** Delivers `data` as a push message to the page's service worker registration. */
const deliver = async (page, data) => {
  const cdp = await page.context().newCDPSession(page);
  const registered = new Promise((resolve) => {
    cdp.on('ServiceWorker.workerRegistrationUpdated', ({ registrations }) => {
      const registration = registrations.find((r) => !r.isDeleted);
      if (registration) resolve(registration.registrationId);
    });
  });
  await cdp.send('ServiceWorker.enable');
  const registrationId = await registered;
  await cdp.send('ServiceWorker.deliverPushMessage', { origin: new URL(page.url()).origin, registrationId, data: JSON.stringify(data) });
};

test('the worker shows push notifications, and hides private ones from another user', async ({ context, page }) => {
  await page.goto('/');
  await controlled(page);
  expect(await page.evaluate(() => window.Pwa.push.supported())).toBe(true);
  expect(await page.evaluate(() => window.Pwa.push.state())).toBe('unsubscribed');

  // Record what the worker passes to showNotification (headless Chromium does not list them).
  const [worker] = context.serviceWorkers();
  await worker.evaluate(() => {
    self.__shown = [];
    const show = self.registration.showNotification.bind(self.registration);
    self.registration.showNotification = (title, options) => {
      self.__shown.push({ title, body: options.body, url: options.data?.url });
      return show(title, options);
    };
  });
  const shown = () => worker.evaluate(() => self.__shown);

  const received = page.evaluate(() => new Promise((resolve) => window.Pwa.on('push-received', resolve)));
  await deliver(page, { pwa: 1, title: 'Maintenance tonight', options: { body: 'From 22:00.', tag: 'news', data: { url: '/orders' } } });
  expect(await received).toMatchObject({ tag: 'news' });
  await expect.poll(shown).toEqual([{ title: 'Maintenance tonight', body: 'From 22:00.', url: '/orders' }]);

  // Addressed to a signed-in user, but this browser is a guest: only the neutral text.
  await deliver(page, { pwa: 1, title: 'Invoice 42 paid', user: 'aaaaaaaaaaaaaaaa', options: { body: 'Secret amount' } });
  await expect.poll(async () => (await shown()).length).toBe(2);
  expect((await shown())[1]).toEqual({ title: 'PWA Workbench', body: 'You have a new notification. Open the app to see it.', url: new URL('/', page.url()).href });
});
