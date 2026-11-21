// Path tenants register the same worker for their own scope (/t/acme/). Cache Storage is shared by
// the origin, so each registration must keep to its own cache names.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { ORIGIN, apiRule, createWorker, html, json } from '../support/sw-env.mjs';

const ALICE = 'aaaaaaaaaaaaaaaa';
const BOB = 'bbbbbbbbbbbbbbbb';
const tenant = (path, caches) => createWorker({ config: { rules: [apiRule('/api/*')] }, registration: { scope: `${ORIGIN}${path}` }, caches });

describe('path tenants', () => {
  it('namespaces every cache of a tenant registration', async () => {
    const worker = tenant('/t/acme/');
    worker.network(() => html('ok', { 'X-PWA-Cache': 'allow', 'X-PWA-User': ALICE }));
    await worker.install();
    await worker.navigate('/t/acme/dashboard');
    worker.network(() => json({}, { 'X-PWA-User': ALICE }));
    await worker.fetchEvent('/api/me');

    const ns = worker.self.PwaWorker.namespace;
    assert.match(ns, /^@t-acme\.[0-9a-f]{8}-$/);
    const keys = await worker.caches.keys();
    assert.ok(keys.includes(`pwa-${ns}static-v1`), keys.join());
    assert.ok(keys.includes(`pwa-${ns}pages-${ALICE}`));
    assert.ok(keys.includes(`pwa-${ns}rule-api-${ALICE}`));
    assert.ok(keys.includes('pwa-meta'), 'the user identity is shared by the origin');
    assert.ok(keys.every((k) => !k.startsWith('pwa-static-') && !k.startsWith('pwa-pages-')));
  });

  it('keeps the plain names for the configured scope', async () => {
    const worker = tenant('/');
    await worker.install();

    assert.deepEqual(await worker.caches.keys(), ['pwa-static-v1']);
    assert.equal(worker.self.PwaWorker.namespace, '');
  });

  it('gives scopes with the same slug different namespaces', () => {
    const names = ['/t/a-b/', '/t/a/b/', '/t/A-b/', `/t/${'x'.repeat(40)}1/`, `/t/${'x'.repeat(40)}2/`]
      .map((path) => tenant(path).self.PwaWorker.namespace);

    assert.equal(new Set(names).size, names.length, names.join());
  });

  it('never deletes another registration\'s caches on activation or logout', async () => {
    const root = tenant('/');
    const acme = tenant('/t/acme/', root.caches);
    root.network(() => html('root', { 'X-PWA-Cache': 'allow', 'X-PWA-User': ALICE }));
    await root.install();
    await root.navigate('/home');

    acme.network(() => html('acme', { 'X-PWA-Cache': 'allow', 'X-PWA-User': ALICE }));
    await acme.install();
    await acme.activate();
    await acme.navigate('/t/acme/dashboard');

    await root.activate();
    root.network(() => html('bye', { 'X-PWA-Logout': '1' }));
    await root.navigate('/logged-out');

    const ns = acme.self.PwaWorker.namespace;
    const keys = await root.caches.keys();
    assert.ok(keys.includes(`pwa-${ns}static-v1`), 'root activation keeps the tenant precache');
    assert.ok(keys.includes('pwa-static-v1'), 'tenant activation keeps the root precache');
    assert.ok(!keys.includes(`pwa-pages-${ALICE}`));
    assert.ok(!keys.includes(`pwa-${ns}pages-${ALICE}`), 'a logout purges user data of every tenant');
  });

  it('a sign-in under one tenant hides the previous user\'s pages in every other tenant', async () => {
    const acme = tenant('/t/acme/');
    const globex = tenant('/t/globex/', acme.caches);
    globex.network(() => html('alice at globex', { 'X-PWA-Cache': 'allow', 'X-PWA-User': ALICE }));
    await globex.navigate('/t/globex/orders');

    // Bob signs in through XHR under acme; globex's worker never sees a response.
    acme.network(() => json({}, { 'X-PWA-User': BOB }));
    await acme.fetchEvent('/login', { method: 'POST', body: '{}' });
    await new Promise((resolve) => setTimeout(resolve, 10)); // BroadcastChannel delivery

    globex.network(() => { throw new TypeError('Failed to fetch'); });
    const { response } = await globex.navigate('/t/globex/orders');

    assert.notEqual(await response.text(), 'alice at globex');
    assert.equal(await globex.self.PwaWorker.currentUser(), BOB);
  });

  it('reads the identity a worker of another scope stored', async () => {
    const acme = tenant('/t/acme/');
    acme.network(() => html('x', { 'X-PWA-User': ALICE }));
    await acme.navigate('/t/acme/');

    const later = tenant('/t/globex/', acme.caches);
    assert.equal(await later.self.PwaWorker.currentUser(), ALICE);
  });
});
