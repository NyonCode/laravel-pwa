import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { apiRule, createWorker, html, imagesRule, json, offline } from '../support/sw-env.mjs';

const ALICE = 'aaaaaaaaaaaaaaaa';
const BOB = 'bbbbbbbbbbbbbbbb';

const cacheNames = async (worker) => (await worker.caches.keys()).sort();

describe('install and activate', () => {
  it('precaches listed URLs and survives a missing file', async () => {
    const worker = createWorker({
      config: { precache: ['/offline', '/build/app.js', '/missing.css'] },
      fetch: (request) => (request.url.endsWith('/missing.css') ? offline() : html('ok')),
    });

    await worker.install();

    assert.deepEqual(worker.caches.urls('pwa-static-v1').sort(), ['/build/app.js', '/offline']);
  });

  it('drops static caches of older versions and page caches after an update', async () => {
    const worker = createWorker();
    for (const name of ['pwa-static-old', 'pwa-static-v1', `pwa-pages-${ALICE}`, `pwa-rule-images-${ALICE}`]) {
      await worker.caches.open(name);
    }
    await (await worker.caches.open('pwa-meta')).put('/__pwa/version', new Response('old'));

    await worker.activate();

    assert.deepEqual(await cacheNames(worker), ['pwa-meta', `pwa-rule-images-${ALICE}`, 'pwa-static-v1']);
    assert.ok(worker.messages.some((m) => m.type === 'activated' && m.version === 'v1'));
  });

  it('removes caches of 0.1.x workers and of rules that no longer exist', async () => {
    const worker = createWorker();
    for (const name of ['pwa-images', `pwa-images-${ALICE}`, `pwa-api-${ALICE}`, `pwa-rule-gone-${ALICE}`, 'pwa-shared-gone', `pwa-rule-images-${ALICE}`]) {
      await worker.caches.open(name);
    }

    await worker.activate();

    assert.deepEqual((await cacheNames(worker)).filter((n) => n !== 'pwa-meta'), [`pwa-rule-images-${ALICE}`]);
  });
});

describe('navigation', () => {
  it('caches HTML only with server consent', async () => {
    const worker = createWorker({
      fetch: (request) =>
        new URL(request.url).pathname === '/allowed'
          ? html('allowed', { 'X-PWA-Cache': 'allow', 'X-PWA-User': 'guest' })
          : html('private', { 'X-PWA-User': 'guest' }),
    });

    await worker.navigate('/allowed');
    await worker.navigate('/private');

    assert.deepEqual(worker.caches.urls('pwa-pages-guest'), ['/allowed']);
  });

  it('never caches HTML marked no-store, even with consent', async () => {
    const worker = createWorker({ fetch: () => html('x', { 'X-PWA-Cache': 'allow', 'Cache-Control': 'no-store' }) });

    await worker.navigate('/page');

    assert.deepEqual(worker.caches.urls('pwa-pages-guest'), []);
  });

  it('serves the cached page offline, then the offline screen', async () => {
    const worker = createWorker({ fetch: () => html('fresh', { 'X-PWA-Cache': 'allow', 'X-PWA-User': 'guest' }) });
    await (await worker.caches.open('pwa-static-v1')).put('/offline', html('offline screen'));
    await worker.navigate('/orders');

    worker.network(offline);

    assert.equal(await (await worker.navigate('/orders')).response.text(), 'fresh');
    assert.equal(await (await worker.navigate('/never-visited')).response.text(), 'offline screen');
    assert.ok(worker.messages.some((m) => m.type === 'offline'));
  });

  it('treats 503 as maintenance: passes it through and keeps the cache', async () => {
    const worker = createWorker({ fetch: () => html('v1', { 'X-PWA-Cache': 'allow', 'X-PWA-User': 'guest' }) });
    await worker.navigate('/orders');

    worker.network(() => html('maintenance', { 'Retry-After': '60', 'X-PWA-Cache': 'allow' }, 503));
    const { response } = await worker.navigate('/orders');

    assert.equal(response.status, 503);
    assert.deepEqual(worker.caches.urls('pwa-pages-guest'), ['/orders']);
    assert.equal(await (await worker.caches.match('/orders')).text(), 'v1');
    assert.ok(worker.messages.some((m) => m.type === 'maintenance' && m.retryAfter === '60'));
  });

  it('answers from cache when the network is slower than the timeout', async () => {
    const worker = createWorker({
      config: { navigationTimeout: 20 },
      fetch: () => html('v1', { 'X-PWA-Cache': 'allow', 'X-PWA-User': 'guest' }),
    });
    await worker.navigate('/orders');

    worker.network(() => new Promise((resolve) => setTimeout(() => resolve(html('slow')), 200)));

    assert.equal(await (await worker.navigate('/orders')).response.text(), 'v1');
  });
});

describe('user separation', () => {
  const pageFor = (user) => () => html(`page of ${user}`, { 'X-PWA-Cache': 'allow', 'X-PWA-User': user });

  it('keeps pages per user and purges the previous user on switch', async () => {
    const worker = createWorker({ fetch: pageFor(ALICE) });
    await worker.navigate('/dashboard');
    assert.deepEqual(worker.caches.urls(`pwa-pages-${ALICE}`), ['/dashboard']);

    worker.network(pageFor(BOB));
    await worker.navigate('/dashboard');

    const names = await cacheNames(worker);
    assert.ok(!names.includes(`pwa-pages-${ALICE}`));
    assert.deepEqual(worker.caches.urls(`pwa-pages-${BOB}`), ['/dashboard']);
  });

  it('purges pages, API data and images on logout', async () => {
    const worker = createWorker({
      config: { rules: [apiRule('/api/*'), imagesRule()] },
      fetch: (request) => {
        const path = new URL(request.url).pathname;
        if (path === '/api/me') return json({ me: 'alice' }, { 'X-PWA-User': ALICE });
        if (path === '/avatar.png') return new Response('png', { headers: { 'Content-Type': 'image/png' } });
        return pageFor(ALICE)();
      },
    });
    await worker.navigate('/dashboard');
    await worker.fetchEvent('/api/me');
    await worker.fetchEvent('/avatar.png', { destination: 'image' });
    assert.deepEqual(worker.caches.urls(`pwa-rule-images-${ALICE}`), ['/avatar.png']);

    worker.network(() => html('bye', { 'X-PWA-Logout': '1', 'X-PWA-User': 'guest' }));
    await worker.navigate('/logout-done');

    const names = await cacheNames(worker);
    assert.deepEqual(names.filter((n) => n.endsWith(ALICE)), []);
    assert.ok(worker.messages.some((m) => m.type === 'logout'));
  });

  it('stores images in the cache of the current user, never a shared one', async () => {
    const worker = createWorker({ fetch: pageFor(ALICE) });
    await worker.navigate('/dashboard');

    worker.network(() => new Response('png', { headers: { 'Content-Type': 'image/png' } }));
    await worker.fetchEvent('/avatar.png', { destination: 'image' });

    assert.deepEqual(worker.caches.urls(`pwa-rule-images-${ALICE}`), ['/avatar.png']);
    assert.ok(!(await cacheNames(worker)).some((n) => n.startsWith('pwa-shared-')));
  });

  it('clears user data on CLEAR_USER from the page', async () => {
    const worker = createWorker({ fetch: pageFor(ALICE) });
    await worker.navigate('/dashboard');

    await worker.message({ type: 'CLEAR_USER' });

    assert.deepEqual((await cacheNames(worker)).filter((n) => n.endsWith(ALICE)), []);
  });
});

describe('images', () => {
  const png = () => new Response('png', { headers: { 'Content-Type': 'image/png' } });

  it('trims the image cache to max_entries', async () => {
    const worker = createWorker({ config: { rules: [imagesRule({ maxEntries: 2 })] }, fetch: png });

    for (const name of ['a', 'b', 'c']) await worker.fetchEvent(`/${name}.png`, { destination: 'image' });

    assert.deepEqual(worker.caches.urls('pwa-rule-images-guest'), ['/b.png', '/c.png']);
  });

  it('does not serve images older than max_age', async () => {
    const worker = createWorker({ config: { rules: [imagesRule({ maxEntries: 10, maxAge: 60 })] }, fetch: png });
    await worker.fetchEvent('/a.png', { destination: 'image' });

    // Age the stored copy beyond max_age.
    const cache = await worker.caches.open('pwa-rule-images-guest');
    const stored = await cache.match('/a.png');
    const headers = new Headers(stored.headers);
    headers.set('X-PWA-Cached-At', String(Date.now() - 120_000));
    await cache.put('/a.png', new Response('stale', { headers }));

    worker.network(() => new Response('fresh', { headers: { 'Content-Type': 'image/png' } }));
    const { response } = await worker.fetchEvent('/a.png', { destination: 'image' });

    assert.equal(await response.text(), 'fresh');
  });
});

describe('API network-first', () => {
  it('falls back to the cached copy offline and on 503', async () => {
    const worker = createWorker({ config: { rules: [apiRule('/api/catalog*')] }, fetch: () => json({ v: 1 }) });
    await worker.fetchEvent('/api/catalog?page=1');

    worker.network(offline);
    assert.deepEqual(await (await worker.fetchEvent('/api/catalog?page=1')).response.json(), { v: 1 });

    worker.network(() => json({ down: true }, {}, 503));
    assert.deepEqual(await (await worker.fetchEvent('/api/catalog?page=1')).response.json(), { v: 1 });
  });

  it('leaves paths outside the rules and never_cache untouched', async () => {
    const worker = createWorker({ config: { rules: [apiRule('/api/catalog*')] }, fetch: () => json({}) });

    assert.equal((await worker.fetchEvent('/api/other')).handled, false);
    assert.equal((await worker.navigate('/login')).handled, false, 'never_cache navigations are untouched');
    assert.equal((await worker.fetchEvent('https://cdn.example.com/x.js')).handled, false);
  });
});

describe('offline queue', () => {
  const post = (worker, path, body = 'name=x&_token=old') =>
    worker.fetchEvent(path, {
      method: 'POST',
      body,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': 'old', Cookie: 'secret' },
    });

  it('queues a failed mutation and answers 202', async () => {
    const worker = createWorker();

    const { response } = await post(worker, '/items');

    assert.equal(response.status, 202);
    assert.equal(response.headers.get('X-PWA-Queued'), '1');
    assert.deepEqual(await response.json(), { queued: true });
    assert.ok(worker.messages.some((m) => m.type === 'queued' && m.count === 1));
  });

  it('replays the queue and retries once with a fresh CSRF token after 419', async () => {
    const worker = createWorker();
    await post(worker, '/items');

    const seen = [];
    worker.network(async (request) => {
      const path = new URL(request.url).pathname;
      if (path === '/pwa/session') return json({ csrf: 'fresh' });
      const body = await request.text();
      seen.push({ token: request.headers.get('x-csrf-token'), body, cookie: request.headers.get('cookie') });
      return seen.length === 1 ? json({}, {}, 419) : json({ saved: true });
    });

    await worker.message({ type: 'REPLAY_QUEUE' });

    assert.equal(seen.length, 2);
    assert.equal(seen[1].token, 'fresh');
    assert.match(seen[1].body, /_token=fresh/);
    assert.equal(seen[0].cookie, null, 'only allow-listed headers are stored');
    assert.ok(worker.messages.some((m) => m.type === 'queue' && m.count === 0));
  });

  it('keeps entries while offline or in maintenance', async () => {
    const worker = createWorker();
    await post(worker, '/items');

    worker.network(() => json({}, {}, 503));
    await worker.message({ type: 'REPLAY_QUEUE' });
    assert.ok(worker.messages.some((m) => m.type === 'queue' && m.count === 1));

    worker.network(offline);
    await worker.message({ type: 'REPLAY_QUEUE' });
    assert.equal(worker.messages.filter((m) => m.type === 'queue').at(-1).count, 1);
  });

  it('does not queue when the queue is disabled', async () => {
    const worker = createWorker({ config: { queue: false } });

    await assert.rejects(post(worker, '/items'), /Failed to fetch/);
    assert.ok(!worker.messages.some((m) => m.type === 'queued'));
  });
});

describe('messages', () => {
  it('answers GET_VERSION on the provided port', async () => {
    const worker = createWorker();
    const replies = [];

    await worker.message({ type: 'GET_VERSION' }, [{ postMessage: (m) => replies.push(m) }]);

    assert.deepEqual(JSON.parse(JSON.stringify(replies)), [{ version: 'v1' }]);
  });
});
