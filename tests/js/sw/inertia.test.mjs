// Inertia visits (XHR GETs with X-Inertia): stored with the server's consent per user, served offline
// from the stored JSON or from the page object inside the stored HTML of the same URL.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createWorker, html, json, offline } from '../support/sw-env.mjs';

const ALICE = 'aaaaaaaaaaaaaaaa';
const BOB = 'bbbbbbbbbbbbbbbb';
const PAGE = { component: 'Dashboard', props: { orders: 3, note: 'A & B <ok> "quoted" \'single\'' }, url: '/dashboard', version: 'v1' };

const visit = async (worker, path = '/dashboard', headers = {}) =>
  (await worker.fetchEvent(path, { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': 'v1', Accept: 'text/html, application/xhtml+xml', ...headers } })).response;

const inertiaJson = (page = PAGE, headers = {}) =>
  json(page, { 'X-Inertia': 'true', Vary: 'X-Inertia', 'X-PWA-Cache': 'allow', 'X-PWA-User': ALICE, ...headers });

// What Laravel's @inertia renders: the page object, HTML-escaped, in a data-page attribute.
const escape = (text) => text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const rootView = (page = PAGE) => html(`<html><body><div id="app" data-page="${escape(JSON.stringify(page))}"></div></body></html>`, { 'X-PWA-Cache': 'allow', 'X-PWA-User': ALICE });
const scriptRootView = (page = PAGE) => html(`<html><body><script data-page="app" type="application/json">${JSON.stringify(page)}</script><div id="app"></div></body></html>`, { 'X-PWA-Cache': 'allow', 'X-PWA-User': ALICE });

describe('Inertia visits', () => {
  it('stores a visit next to the page and serves it offline', async () => {
    const worker = createWorker({ fetch: () => inertiaJson() });
    const online = await visit(worker);
    assert.deepEqual(await online.json(), PAGE);
    assert.deepEqual(worker.caches.urls(`pwa-pages-${ALICE}`), ['/dashboard?__pwa=inertia']);

    worker.network(offline);
    const response = await visit(worker);
    assert.equal(response.headers.get('X-Inertia'), 'true');
    assert.deepEqual(await response.json(), PAGE);
  });

  it('serves the page object of a stored HTML page (first load, warm-up)', async () => {
    for (const view of [rootView, scriptRootView]) {
      const worker = createWorker({ fetch: () => view() });
      await worker.navigate('/dashboard');

      worker.network(offline);
      const response = await visit(worker);
      assert.equal(response.headers.get('X-Inertia'), 'true');
      assert.equal(response.headers.get('Content-Type'), 'application/json');
      assert.deepEqual(await response.json(), PAGE);
    }
  });

  it('stores nothing without the pwa.cache consent or for a partial reload', async () => {
    const worker = createWorker({ fetch: () => inertiaJson(PAGE, { 'X-PWA-Cache': '' }) });
    await visit(worker);
    assert.deepEqual(worker.caches.urls(`pwa-pages-${ALICE}`), []);

    worker.network(() => inertiaJson());
    await visit(worker, '/dashboard', { 'X-Inertia-Partial-Data': 'orders', 'X-Inertia-Partial-Component': 'Dashboard' });
    assert.deepEqual(worker.caches.urls(`pwa-pages-${ALICE}`), []);
  });

  it('never answers a partial reload from the cache', async () => {
    const worker = createWorker({ fetch: () => inertiaJson() });
    await visit(worker);

    worker.network(offline);
    // The worker leaves it to the browser, which fails like any request without a network.
    const partial = await worker.fetchEvent('/dashboard', { headers: { 'X-Inertia': 'true', 'X-Inertia-Partial-Data': 'orders', 'X-Inertia-Partial-Component': 'Dashboard' } });
    assert.equal(partial.handled, false);
  });

  it('fails like the network for a page that was never stored, and tells the client', async () => {
    const worker = createWorker({ fetch: () => rootView() });
    await worker.navigate('/dashboard');

    worker.network(offline);
    const response = await visit(worker, '/orders');
    assert.equal(response.type, 'error');
    assert.ok(worker.messages.some((m) => m.type === 'offline'));
  });

  it('ignores stored HTML without a valid page object', async () => {
    const worker = createWorker({ fetch: () => html('<div id="app" data-page="{broken"></div>', { 'X-PWA-Cache': 'allow', 'X-PWA-User': ALICE }) });
    await worker.navigate('/dashboard');

    worker.network(offline);
    assert.equal((await visit(worker)).type, 'error');
  });

  it('keeps visits per user and drops them when another user signs in', async () => {
    const worker = createWorker({ fetch: () => inertiaJson() });
    await visit(worker);

    worker.network(() => inertiaJson({ ...PAGE, props: { orders: 0 } }, { 'X-PWA-User': BOB }));
    await visit(worker);
    assert.ok(!(await worker.caches.keys()).includes(`pwa-pages-${ALICE}`));
    assert.deepEqual(worker.caches.urls(`pwa-pages-${BOB}`), ['/dashboard?__pwa=inertia']);

    worker.network(offline);
    assert.deepEqual((await (await visit(worker)).json()).props, { orders: 0 });
  });

  it('passes maintenance (503) through and announces it', async () => {
    const worker = createWorker({ fetch: () => json({}, { 'Retry-After': '30' }, 503) });
    const response = await visit(worker);
    assert.equal(response.status, 503);
    assert.ok(worker.messages.some((m) => m.type === 'maintenance'));
    assert.deepEqual(worker.caches.urls(`pwa-pages-${ALICE}`), []);
  });
});
