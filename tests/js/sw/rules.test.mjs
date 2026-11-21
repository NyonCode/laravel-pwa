import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createWorker, html, imagesRule, json, offline, rule } from '../support/sw-env.mjs';

const ALICE = 'aaaaaaaaaaaaaaaa';
const counter = () => {
  let n = 0;
  return () => json({ n: ++n });
};

/** Signs Alice in through a navigation, so per-user caches get her hash. */
const signIn = async (worker, user = ALICE) => {
  worker.network(() => html('home', { 'X-PWA-User': user }));
  await worker.navigate('/');
};

describe('strategies', () => {
  it('cache-first answers from cache without the network', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/catalog*', strategy: 'cache-first', cache: 'catalog' })] } });
    worker.network(counter());

    await worker.fetchEvent('/catalog/1');
    const { response } = await worker.fetchEvent('/catalog/1');

    assert.deepEqual(await response.json(), { n: 1 });
    assert.equal(worker.requests.filter((r) => r.url.endsWith('/catalog/1')).length, 1);
  });

  it('cache-first refetches after max_age, and serves the old copy offline', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/catalog*', strategy: 'cache-first', cache: 'catalog', maxAge: 60 })] } });
    worker.network(counter());
    await worker.fetchEvent('/catalog/1');

    const cache = await worker.caches.open('pwa-rule-catalog-guest');
    const stored = await cache.match('/catalog/1');
    const headers = new Headers(stored.headers);
    headers.set('X-PWA-Cached-At', String(Date.now() - 120_000));
    await cache.put('/catalog/1', new Response(JSON.stringify({ n: 'old' }), { headers }));

    assert.deepEqual(await (await worker.fetchEvent('/catalog/1')).response.json(), { n: 2 });

    await cache.put('/catalog/1', new Response(JSON.stringify({ n: 'old' }), { headers }));
    worker.network(offline);
    assert.deepEqual(await (await worker.fetchEvent('/catalog/1')).response.json(), { n: 'old' });
  });

  it('stale-while-revalidate answers from cache and refreshes in the background', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/feed', strategy: 'stale-while-revalidate', cache: 'feed' })] } });
    worker.network(counter());

    await worker.fetchEvent('/feed');
    assert.deepEqual(await (await worker.fetchEvent('/feed')).response.json(), { n: 1 });
    assert.deepEqual(await (await worker.fetchEvent('/feed')).response.json(), { n: 2 });
  });

  it('network-first with a timeout serves the cache while the network is slow', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/stock', cache: 'stock', timeout: 1 })] } });
    worker.network(() => json({ v: 'cached' }));
    await worker.fetchEvent('/stock');

    worker.network(() => new Promise((resolve) => setTimeout(() => resolve(json({ v: 'slow' })), 1500)));
    const { response, respondedIn } = await worker.fetchEvent('/stock');

    assert.deepEqual(await response.json(), { v: 'cached' });
    assert.ok(respondedIn < 1400, `answered after ${respondedIn} ms, did not wait for the slow network`);
  });

  it('network-first never falls back to a copy older than max_age', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/stock', cache: 'stock', maxAge: 60 })] } });
    worker.network(() => json({ v: 1 }));
    await worker.fetchEvent('/stock');

    const cache = await worker.caches.open('pwa-rule-stock-guest');
    const headers = new Headers((await cache.match('/stock')).headers);
    headers.set('X-PWA-Cached-At', String(Date.now() - 120_000));
    await cache.put('/stock', new Response('{"v":1}', { headers }));

    worker.network(offline);
    await assert.rejects(worker.fetchEvent('/stock'));
  });

  it('network-only never stores anything', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/live*', strategy: 'network-only', cache: 'live' })] } });
    worker.network(() => json({ live: true }));

    await worker.fetchEvent('/live/1');

    assert.ok(!(await worker.caches.keys()).some((n) => n.includes('live')));
  });

  it('cache-only answers from the precache and 504 when missing', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/static/*', strategy: 'cache-only', cache: 'static' })] } });
    await (await worker.caches.open('pwa-static-v1')).put('/static/terms', html('terms'));

    assert.equal(await (await worker.fetchEvent('/static/terms')).response.text(), 'terms');
    assert.equal((await worker.fetchEvent('/static/missing')).response.status, 504);
    assert.equal(worker.requests.length, 0);
  });
});

describe('rule matching', () => {
  it('uses the first matching rule', async () => {
    const worker = createWorker({
      config: {
        rules: [
          rule({ match: '/api/live', strategy: 'network-only', cache: 'live' }),
          rule({ match: '/api/*', strategy: 'cache-first', cache: 'api' }),
        ],
      },
      fetch: counter(),
    });

    await worker.fetchEvent('/api/live');
    await worker.fetchEvent('/api/live');
    await worker.fetchEvent('/api/other');

    assert.equal(worker.requests.filter((r) => r.url.endsWith('/api/live')).length, 2);
    assert.deepEqual(worker.caches.urls('pwa-rule-api-guest'), ['/api/other']);
  });

  it('matches by destination, and by pattern and destination together', async () => {
    const worker = createWorker({
      config: {
        rules: [
          rule({ match: '/avatars/*', destinations: ['image'], strategy: 'cache-first', cache: 'avatars', maxEntries: 50 }),
          imagesRule(),
        ],
      },
      fetch: () => new Response('png', { headers: { 'Content-Type': 'image/png' } }),
    });

    await worker.fetchEvent('/avatars/1.png', { destination: 'image' });
    await worker.fetchEvent('/logo.png', { destination: 'image' });
    await worker.fetchEvent('/font.woff2', { destination: 'font' });
    await worker.fetchEvent('/avatars/data.json', { destination: '' });

    assert.deepEqual(worker.caches.urls('pwa-rule-avatars-guest'), ['/avatars/1.png']);
    assert.deepEqual(worker.caches.urls('pwa-rule-images-guest').sort(), ['/font.woff2', '/logo.png']);
  });

  it('ignores the query string when matching, but caches each URL separately', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/search', strategy: 'cache-first', cache: 'search' })] }, fetch: counter() });

    await worker.fetchEvent('/search?q=a');
    await worker.fetchEvent('/search?q=b');

    assert.deepEqual(worker.caches.urls('pwa-rule-search-guest').sort(), ['/search?q=a', '/search?q=b']);
  });

  it('does not apply rules to other origins or never_cache paths', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '*', strategy: 'cache-first', cache: 'all' })] }, fetch: counter() });

    assert.equal((await worker.fetchEvent('https://cdn.example.com/lib.js')).handled, false);
    assert.equal((await worker.fetchEvent('/sw.js')).handled, false);

    await worker.fetchEvent('/pwa/session');
    assert.ok(!(await worker.caches.keys()).includes('pwa-rule-all-guest'), 'never_cache paths are not stored');
  });

  it('serves precached build files cache-first', async () => {
    const worker = createWorker({ config: { precache: ['/offline', '/build/app.js'] }, fetch: () => new Response('js') });
    await worker.install();
    worker.network(offline);

    assert.equal(await (await worker.fetchEvent('/build/app.js')).response.text(), 'js');
  });
});

describe('per-user and shared rule caches', () => {
  it('stores rule data per user and purges it on logout', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/api/*', strategy: 'cache-first', cache: 'api' })] } });
    await signIn(worker);

    worker.network(() => json({ secret: 'alice' }));
    await worker.fetchEvent('/api/me');
    assert.deepEqual(worker.caches.urls(`pwa-rule-api-${ALICE}`), ['/api/me']);

    worker.network(() => html('bye', { 'X-PWA-Logout': '1' }));
    await worker.navigate('/logged-out');

    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
  });

  it('keeps shared caches across users, but never stores private or signed-in responses there', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/public/*', strategy: 'cache-first', cache: 'public', shared: true })] } });

    worker.network((request) => {
      const path = new URL(request.url).pathname;
      if (path === '/public/private') return json({}, { 'Cache-Control': 'private, max-age=60' });
      if (path === '/public/user') return json({}, { 'X-PWA-User': ALICE });
      return json({ public: true }, { 'X-PWA-User': 'guest' });
    });

    await worker.fetchEvent('/public/catalog');
    await worker.fetchEvent('/public/private');
    await worker.fetchEvent('/public/user');
    assert.deepEqual(worker.caches.urls('pwa-shared-public'), ['/public/catalog']);

    await signIn(worker);
    worker.network(() => html('bye', { 'X-PWA-Logout': '1' }));
    await worker.navigate('/logged-out');

    assert.deepEqual(worker.caches.urls('pwa-shared-public'), ['/public/catalog']);
  });

  it('CLEAR_USER also resets the current user, so new data is not stored for the old one', async () => {
    const worker = createWorker({ config: { rules: [rule({ match: '/api/*', strategy: 'cache-first', cache: 'api' })] } });
    await signIn(worker);

    await worker.message({ type: 'CLEAR_USER' });
    worker.network(() => json({ v: 1 }));
    await worker.fetchEvent('/api/catalog');

    assert.deepEqual(worker.caches.urls('pwa-rule-api-guest'), ['/api/catalog']);
    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
  });
});

describe('sw.extend and PwaWorker', () => {
  it('routes matching requests to an extension before the rules', async () => {
    const worker = createWorker({
      config: { rules: [rule({ match: '/api/*', strategy: 'cache-first', cache: 'api' })] },
      extensions: [`
        self.PwaWorker.route('/api/hello', () => new Response('from extension'));
      `],
      fetch: counter(),
    });

    assert.equal(await (await worker.fetchEvent('/api/hello')).response.text(), 'from extension');
    assert.deepEqual(await (await worker.fetchEvent('/api/other')).response.json(), { n: 1 });
  });

  it('lets extensions use the strategies and per-user cache names', async () => {
    const worker = createWorker({
      extensions: [`
        self.PwaWorker.route(({ url }) => url.pathname.startsWith('/reports/'), async (event) =>
          self.PwaWorker.strategies.cacheFirst(event, { cacheName: await self.PwaWorker.cacheName('reports'), maxEntries: 5 }));
      `],
      fetch: counter(),
    });
    await signIn(worker);
    worker.network(counter());

    await worker.fetchEvent('/reports/1');
    const { response } = await worker.fetchEvent('/reports/1');

    assert.deepEqual(await response.json(), { n: 1 });
    assert.deepEqual(worker.caches.urls(`pwa-rule-reports-${ALICE}`), ['/reports/1']);
  });

  it('can match other origins with a RegExp', async () => {
    const worker = createWorker({
      extensions: [`
        self.PwaWorker.route(/^https:\\/\\/fonts\\.example\\.com\\//, (event) =>
          self.PwaWorker.strategies.cacheFirst(event, { cacheName: 'pwa-shared-fonts', shared: true }));
      `],
      fetch: () => new Response('font'),
    });

    assert.equal(await (await worker.fetchEvent('https://fonts.example.com/a.woff2')).response.text(), 'font');
    assert.deepEqual(worker.caches.urls('pwa-shared-fonts'), ['/a.woff2']);
  });

  it('exposes version and config, and rejects invalid routes', async () => {
    const worker = createWorker();
    const api = worker.self.PwaWorker;

    assert.equal(api.version, 'v1');
    assert.equal(api.config.offlineUrl, '/offline');
    assert.throws(() => api.route('/x'), /handler must be a function/);
    assert.throws(() => api.route(42, () => {}), /match must be/);
    assert.ok(Object.isFrozen(api));
  });
});

describe('identity outside navigations (Inertia, Livewire)', () => {
  const BOB = 'bbbbbbbbbbbbbbbb';
  const apiRules = { rules: [rule({ match: '/api/*', strategy: 'cache-first', cache: 'api' })] };

  const post = (worker, path) =>
    worker.fetchEvent(path, { method: 'POST', body: '{}', headers: { 'Content-Type': 'application/json' } });

  it('an XHR logout purges the user data', async () => {
    const worker = createWorker({ config: apiRules });
    await signIn(worker);
    worker.network(() => json({ secret: 'alice' }, { 'X-PWA-User': ALICE }));
    await worker.fetchEvent('/api/me');

    worker.network(() => json({ ok: true }, { 'X-PWA-Logout': '1', 'X-PWA-User': 'guest' }));
    await post(worker, '/logout');

    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
    assert.ok(worker.messages.some((m) => m.type === 'logout'));
  });

  it('an XHR login of another user hides the previous user data from them', async () => {
    const worker = createWorker({ config: apiRules });
    await signIn(worker);
    worker.network(() => json({ secret: 'alice' }, { 'X-PWA-User': ALICE }));
    await worker.fetchEvent('/api/me');

    worker.network(() => json({ ok: true }, { 'X-PWA-User': BOB }));
    await post(worker, '/login');

    worker.network(() => json({ secret: 'bob' }, { 'X-PWA-User': BOB }));
    const { response } = await worker.fetchEvent('/api/me');

    assert.deepEqual(await response.json(), { secret: 'bob' });
    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
    assert.deepEqual(worker.caches.urls(`pwa-rule-api-${BOB}`), ['/api/me']);
  });

  it('never stores a response of another user in the current user cache', async () => {
    const worker = createWorker({ config: apiRules });
    await signIn(worker);

    // The session changed server-side; the first rule response reveals it.
    worker.network(() => json({ secret: 'bob' }, { 'X-PWA-User': BOB }));
    await worker.fetchEvent('/api/me');

    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
  });

  it('ignores identity headers from other origins', async () => {
    const worker = createWorker({
      extensions: [`
        self.PwaWorker.route(/^https:\\/\\/cdn\\.example\\.com\\//, (event) =>
          self.PwaWorker.strategies.networkFirst(event, { cacheName: 'pwa-shared-cdn', shared: true }));
      `],
    });
    await signIn(worker);

    worker.network(() => json({}, { 'X-PWA-Logout': '1', 'X-PWA-User': BOB }));
    await worker.fetchEvent('https://cdn.example.com/lib.json');

    assert.equal(await worker.self.PwaWorker.currentUser(), ALICE);
  });

  it('ignores identity headers of a same-origin request redirected to another origin', async () => {
    const worker = createWorker({ config: apiRules });
    await signIn(worker);

    const redirected = (type) => {
      const response = json({}, { 'X-PWA-Logout': '1', 'X-PWA-User': BOB });
      Object.defineProperty(response, 'url', { value: 'https://evil.example.com/landing' });
      Object.defineProperty(response, 'redirected', { value: true });
      Object.defineProperty(response, 'type', { value: type });
      return response;
    };

    for (const type of ['cors', 'basic']) {
      worker.network(() => redirected(type));
      await worker.fetchEvent('/api/out');
      await post(worker, '/items');
      await post(worker, '/logout');
    }

    assert.equal(await worker.self.PwaWorker.currentUser(), ALICE);
    assert.ok(!worker.messages.some((m) => m.type === 'logout'));
  });

  it('passes never_cache requests through without storing or queueing them', async () => {
    const worker = createWorker();

    await assert.rejects(post(worker, '/login'), /Failed to fetch/);
    assert.ok(!worker.messages.some((m) => m.type === 'queued'));
  });

  it('takes the identity from the client keep-alive (IDENTITY message)', async () => {
    const worker = createWorker({ config: apiRules });
    await signIn(worker);
    worker.network(() => json({ secret: 'alice' }, { 'X-PWA-User': ALICE }));
    await worker.fetchEvent('/api/me');

    await worker.message({ type: 'IDENTITY', user: 'not-a-hash' });
    assert.equal(await worker.self.PwaWorker.currentUser(), ALICE);

    await worker.message({ type: 'IDENTITY', user: 'guest' });
    assert.equal(await worker.self.PwaWorker.currentUser(), 'guest');
    assert.ok(!(await worker.caches.keys()).includes(`pwa-rule-api-${ALICE}`));
  });
});
