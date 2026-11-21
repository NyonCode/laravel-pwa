// Regression tests for the 0.1.1 security fix (GHSA-mjp7-hj6c-2jr9): the worker must see identity changes that do not navigate
// (XHR logins and logouts of Inertia and Livewire) and never mix users' data.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { apiRule, createWorker, html, json } from '../support/sw-env.mjs';

const ALICE = 'aaaaaaaaaaaaaaaa';
const BOB = 'bbbbbbbbbbbbbbbb';
const config = { rules: [apiRule('/api/*')] };

const signIn = async (worker, user = ALICE) => {
  worker.network(() => html('home', { 'X-PWA-User': user }));
  await worker.navigate('/');
};

const post = (worker, path) =>
  worker.fetchEvent(path, { method: 'POST', body: '{}', headers: { 'Content-Type': 'application/json' } });

const aliceData = async (worker) => {
  worker.network(() => json({ secret: 'alice' }, { 'X-PWA-User': ALICE }));
  await worker.fetchEvent('/api/me');
  assert.deepEqual(worker.caches.urls(`pwa-rule-api-${ALICE}`), ['/api/me']);
};

describe('identity without navigations', () => {
  it('an XHR logout on a never_cache path purges the user data', async () => {
    const worker = createWorker({ config });
    await signIn(worker);
    await aliceData(worker);

    worker.network(() => json({}, { 'X-PWA-Logout': '1', 'X-PWA-User': 'guest' }));
    await post(worker, '/logout');

    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
    assert.ok(worker.messages.some((m) => m.type === 'logout'));
  });

  it('another user signing in through XHR never sees the previous user data offline', async () => {
    const worker = createWorker({ config });
    await signIn(worker);
    await aliceData(worker);

    worker.network(() => json({}, { 'X-PWA-User': BOB }));
    await post(worker, '/login');

    worker.network(() => { throw new TypeError('Failed to fetch'); });
    await assert.rejects(worker.fetchEvent('/api/me'));
    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
  });

  it('reads the identity from mutations even with the queue disabled', async () => {
    const worker = createWorker({ config: { ...config, queue: false } });
    await signIn(worker);
    await aliceData(worker);

    worker.network(() => json({}, { 'X-PWA-User': BOB }));
    await post(worker, '/session/switch');

    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
  });

  it('reads the identity from API responses and never stores another user response', async () => {
    const worker = createWorker({ config });
    await signIn(worker);

    worker.network(() => json({ secret: 'bob' }, { 'X-PWA-User': BOB }));
    await worker.fetchEvent('/api/me');

    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
  });

  it('ignores identity headers of responses from another origin, also after a redirect', async () => {
    const worker = createWorker({ config });
    await signIn(worker);

    const redirected = () => {
      const response = json({}, { 'X-PWA-Logout': '1', 'X-PWA-User': BOB });
      Object.defineProperty(response, 'url', { value: 'https://evil.example.com/landing' });
      Object.defineProperty(response, 'type', { value: 'cors' });
      return response;
    };
    worker.network(redirected);
    await worker.fetchEvent('/api/out');
    await post(worker, '/items');

    worker.network(() => html('home', { 'X-PWA-User': ALICE }));
    await aliceData(worker);
    assert.ok(!worker.messages.some((m) => m.type === 'logout'));
  });

  it('takes the identity from the keep-alive and ignores malformed values', async () => {
    const worker = createWorker({ config });
    await signIn(worker);
    await aliceData(worker);

    await worker.message({ type: 'IDENTITY', user: '../etc' });
    assert.deepEqual(worker.caches.urls(`pwa-rule-api-${ALICE}`), ['/api/me']);

    await worker.message({ type: 'IDENTITY', user: 'guest' });
    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
  });

  it('CLEAR_USER resets the current user, so new data is not stored for the old one', async () => {
    const worker = createWorker({ config });
    await signIn(worker);

    await worker.message({ type: 'CLEAR_USER' });
    worker.network(() => json({ v: 1 }));
    await worker.fetchEvent('/api/catalog');

    assert.deepEqual(worker.caches.urls('pwa-rule-api-guest'), ['/api/catalog']);
  });

  it('still never stores or queues never_cache requests', async () => {
    const worker = createWorker({ config });

    await assert.rejects(post(worker, '/login'), /Failed to fetch/);
    assert.ok(!worker.messages.some((m) => m.type === 'queued'));
    assert.equal((await worker.navigate('/login')).handled, false);
  });
});
