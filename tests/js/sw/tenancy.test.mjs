// Path tenants register the same worker for their own scope (/t/acme/). Cache Storage is shared by
// the origin, so each registration must keep to its own cache names.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { ORIGIN, apiRule, createWorker, html, json } from '../support/sw-env.mjs';

const ALICE = 'aaaaaaaaaaaaaaaa';
const tenant = (path, caches) => createWorker({ config: { rules: [apiRule('/api/*')] }, registration: { scope: `${ORIGIN}${path}` }, caches });

describe('path tenants', () => {
  it('namespaces every cache of a tenant registration', async () => {
    const worker = tenant('/t/acme/');
    worker.network(() => html('ok', { 'X-PWA-Cache': 'allow', 'X-PWA-User': ALICE }));
    await worker.install();
    await worker.navigate('/t/acme/dashboard');
    worker.network(() => json({}, { 'X-PWA-User': ALICE }));
    await worker.fetchEvent('/api/me');

    const keys = await worker.caches.keys();
    assert.ok(keys.includes('pwa-@t-acme-static-v1'), keys.join());
    assert.ok(keys.includes(`pwa-@t-acme-pages-${ALICE}`));
    assert.ok(keys.includes(`pwa-@t-acme-rule-api-${ALICE}`));
    assert.ok(keys.includes('pwa-@t-acme-meta'));
    assert.ok(keys.every((k) => !k.startsWith('pwa-static-') && !k.startsWith('pwa-pages-')));
    assert.equal(worker.self.PwaWorker.namespace, '@t-acme-');
  });

  it('keeps the plain names for the configured scope', async () => {
    const worker = tenant('/');
    await worker.install();

    assert.deepEqual(await worker.caches.keys(), ['pwa-static-v1']);
    assert.equal(worker.self.PwaWorker.namespace, '');
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

    const keys = await root.caches.keys();
    assert.ok(keys.includes('pwa-@t-acme-static-v1'), 'root activation keeps the tenant precache');
    assert.ok(keys.includes(`pwa-@t-acme-pages-${ALICE}`), 'the tenant purges on its own next response');
    assert.ok(!keys.includes(`pwa-pages-${ALICE}`));
    assert.ok(keys.includes('pwa-static-v1'), 'tenant activation keeps the root precache');
  });
});
