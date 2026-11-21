// Offline warm-up (offline.warm, Pwa::warmUsing): pages downloaded ahead of time, under the same
// rules as navigations (server consent, per user, purged on logout).
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createWorker, html, json } from '../support/sw-env.mjs';

const ALICE = 'aaaaaaaaaaaaaaaa';
const BOB = 'bbbbbbbbbbbbbbbb';
const allow = (user = ALICE) => ({ 'X-PWA-Cache': 'allow', 'X-PWA-User': user });

const pages = (worker, user = ALICE) => worker.caches.urls(`pwa-pages-${user}`);

describe('warm-up', () => {
  it('stores only pages the server allows, for the user who asked', async () => {
    const worker = createWorker({ config: { warm: true } });
    worker.network((request) => {
      const { pathname } = new URL(request.url);
      if (pathname === '/private') return html('no consent', { 'X-PWA-User': ALICE });
      if (pathname === '/bob') return html('other user', allow(BOB));
      return html(pathname, allow());
    });

    await worker.message({ type: 'WARM', user: ALICE, urls: ['/dashboard', '/orders?page=2', '/private', '/dashboard#top'] });

    assert.deepEqual(pages(worker).sort(), ['/dashboard', '/orders?page=2']);
    assert.deepEqual({ ...worker.messages.find((m) => m.type === 'warmed') }, { source: 'pwa', type: 'warmed', count: 2, skipped: 1, failed: 0 });
    assert.ok(worker.requests.every((r) => r.headers.get('X-PWA-Warm') === '1'));
  });

  it('serves a warmed page offline without a prior visit', async () => {
    const worker = createWorker();
    worker.network(() => html('<h1>Orders</h1>', allow()));
    await worker.message({ type: 'WARM', user: ALICE, urls: ['/orders'] });

    worker.network(() => { throw new TypeError('Failed to fetch'); });
    const { response } = await worker.navigate('/orders');

    assert.equal(await response.text(), '<h1>Orders</h1>');
  });

  it('never fetches other origins, never_cache paths or the worker script', async () => {
    const worker = createWorker();
    worker.network(() => html('x', allow()));

    await worker.message({ type: 'WARM', user: ALICE, urls: ['https://evil.test/x', '//evil.test/y', '/login', '/pwa/session', '/sw.js', '/ok'] });

    assert.deepEqual(worker.requests.map((r) => new URL(r.url).pathname), ['/ok']);
  });

  it('skips redirects (login pages) and network errors', async () => {
    const worker = createWorker();
    worker.network((request) => {
      if (request.url.endsWith('/gone')) throw new TypeError('Failed to fetch');
      const response = html('login form', allow());
      Object.defineProperty(response, 'redirected', { value: true });
      return response;
    });

    await worker.message({ type: 'WARM', user: ALICE, urls: ['/moved', '/gone'] });

    assert.deepEqual(pages(worker), []);
    assert.deepEqual({ ...worker.messages.find((m) => m.type === 'warmed') }, { source: 'pwa', type: 'warmed', count: 0, skipped: 1, failed: 1 });
  });

  it('switches to the user from the message and purges the previous one first', async () => {
    const worker = createWorker();
    worker.network(() => html('alice', allow()));
    await worker.message({ type: 'WARM', user: ALICE, urls: ['/a'] });

    worker.network(() => html('bob', allow(BOB)));
    await worker.message({ type: 'WARM', user: BOB, urls: ['/b'] });

    assert.ok(!(await worker.caches.keys()).includes(`pwa-pages-${ALICE}`));
    assert.deepEqual(pages(worker, BOB), ['/b']);
  });

  it('ignores a message with an invalid identity', async () => {
    const worker = createWorker();
    await worker.message({ type: 'WARM', user: '1', urls: ['/a'] });

    assert.equal(worker.requests.length, 0);
  });

  it('stops when the user signs out during the warm-up', async () => {
    const worker = createWorker();
    // After the logout, the server only answers as a guest.
    worker.network((request) => html('x', request.url.endsWith('/1') ? { 'X-PWA-Logout': '1' } : allow('guest')));

    await worker.message({ type: 'WARM', user: ALICE, urls: ['/1', '/2', '/3', '/4'] });

    assert.deepEqual(pages(worker), []);
    assert.ok(worker.requests.length < 4);
  });

  it('downloads at most two pages at a time and at most 50 in total', async () => {
    const worker = createWorker();
    let running = 0;
    let peak = 0;
    worker.network(async () => {
      peak = Math.max(peak, ++running);
      await new Promise((resolve) => setTimeout(resolve, 1));
      running--;
      return html('x', allow());
    });

    await worker.message({ type: 'WARM', user: ALICE, urls: Array.from({ length: 60 }, (_, i) => `/p/${i}`) });

    assert.equal(peak, 2);
    assert.equal(pages(worker).length, 50);
  });

  it('does not repeat a warm-up within 30 seconds unless forced', async () => {
    const worker = createWorker();
    worker.network(() => html('x', allow()));

    await worker.message({ type: 'WARM', user: ALICE, urls: ['/a'] });
    await worker.message({ type: 'WARM', user: ALICE, urls: ['/a'] });
    assert.equal(worker.requests.length, 1);

    await worker.message({ type: 'WARM', user: ALICE, urls: ['/a'], force: true });
    assert.equal(worker.requests.length, 2);
  });

  it('asks the server for the list after a sign-in navigation', async () => {
    const worker = createWorker({ config: { warm: true } });
    worker.network((request) => {
      const { pathname } = new URL(request.url);
      if (pathname === '/pwa/session') return json({ user: ALICE, warm: ['/orders'] });
      return html(pathname, allow());
    });

    await worker.navigate('/home');

    assert.deepEqual(worker.requests.map((r) => new URL(r.url).pathname), ['/home', '/pwa/session', '/orders']);
    assert.ok(pages(worker).includes('/orders'));
    assert.ok(worker.messages.some((m) => m.type === 'identity' && m.user === ALICE));
  });

  it('does not ask the server without a configured warm-up', async () => {
    const worker = createWorker();
    worker.network(() => html('home', allow()));

    await worker.navigate('/home');

    assert.deepEqual(worker.requests.map((r) => new URL(r.url).pathname), ['/home']);
  });

  it('forgets the list on logout and refreshes it on periodic sync for the same user only', async () => {
    const worker = createWorker();
    worker.network(() => html('x', allow()));
    await worker.message({ type: 'WARM', user: ALICE, urls: ['/a'] });

    await worker.dispatch('periodicsync', { tag: 'pwa-warm' });
    assert.equal(worker.requests.length, 2);

    worker.network(() => html('bye', { 'X-PWA-Logout': '1' }));
    await worker.navigate('/logout-done');
    const before = worker.requests.length;

    await worker.dispatch('periodicsync', { tag: 'pwa-warm' });
    assert.equal(worker.requests.length, before);
  });

  it('is available to sw.extend files as PwaWorker.warm()', async () => {
    const worker = createWorker({ extensions: ["self.addEventListener('message', (e) => { if (e.data.type === 'MY_WARM') e.waitUntil(self.PwaWorker.warm(['/x'])); });"] });
    worker.network(() => html('x', { 'X-PWA-Cache': 'allow' }));

    await worker.message({ type: 'MY_WARM' });

    assert.deepEqual(pages(worker, 'guest'), ['/x']);
  });
});
