// Livewire's wire:navigate: a fetch of the next page's HTML (X-Livewire-Navigate) instead of a
// navigation. Stored and served like a page, under the page URL, so both find each other.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createWorker, html, offline } from '../support/sw-env.mjs';

const ALICE = 'aaaaaaaaaaaaaaaa';
const page = (body = 'dashboard', headers = {}) => html(`<html><body>${body}</body></html>`, { 'X-PWA-Cache': 'allow', 'X-PWA-User': ALICE, ...headers });
const navigateFetch = async (worker, path = '/dashboard') =>
  (await worker.fetchEvent(path, { headers: { 'X-Livewire-Navigate': '1' } })).response;

describe('wire:navigate', () => {
  it('stores the page and serves it to a later wire:navigate and to a navigation offline', async () => {
    const worker = createWorker({ fetch: () => page() });
    assert.match(await (await navigateFetch(worker)).text(), /dashboard/);
    assert.deepEqual(worker.caches.urls(`pwa-pages-${ALICE}`), ['/dashboard']);

    worker.network(offline);
    assert.match(await (await navigateFetch(worker)).text(), /dashboard/);
    assert.match(await (await worker.navigate('/dashboard')).response.text(), /dashboard/);
  });

  it('serves a page stored by a navigation (first load, warm-up) offline', async () => {
    const worker = createWorker({ fetch: () => page() });
    await worker.navigate('/dashboard');

    worker.network(offline);
    assert.match(await (await navigateFetch(worker, '/dashboard#orders')).text(), /dashboard/);
  });

  it('fails like the network for a page that was never stored, and tells the client', async () => {
    const worker = createWorker();
    const response = await navigateFetch(worker, '/orders');
    // Not the offline screen: Livewire then falls back to a full navigation, which shows it.
    assert.equal(response.type, 'error');
    assert.ok(worker.messages.some((m) => m.type === 'offline'));
  });

  it('stores nothing without the pwa.cache consent or after a redirect', async () => {
    const worker = createWorker({ fetch: () => page('x', { 'X-PWA-Cache': '' }) });
    await navigateFetch(worker);
    assert.deepEqual(worker.caches.urls(`pwa-pages-${ALICE}`), []);

    worker.network(() => {
      const response = page('login');
      Object.defineProperty(response, 'redirected', { value: true });
      return response;
    });
    await navigateFetch(worker);
    assert.deepEqual(worker.caches.urls(`pwa-pages-${ALICE}`), []);
  });

  it('leaves never_cache pages to the network', async () => {
    const worker = createWorker({ fetch: () => page() });
    const { response } = await worker.fetchEvent('/login', { headers: { 'X-Livewire-Navigate': '1' } });
    assert.equal(response.status, 200);
    assert.deepEqual(worker.caches.urls(`pwa-pages-${ALICE}`), []);
  });
});
