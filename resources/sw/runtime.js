/*
 * nyoncode/laravel-pwa — service worker runtime.
 *
 * Configuration arrives in self.__PWA (built by ServiceWorkerBuilder). Files from
 * config('pwa.sw.import') run before this code (e.g. push listeners); files from
 * config('pwa.sw.extend') run after it and can use self.PwaWorker.
 *
 * Safety rules this file guarantees:
 *  - HTML is cached only with X-PWA-Cache: allow from the server.
 *  - Every cache that can hold user data is per user (X-PWA-User hash) and is purged
 *    on logout (X-PWA-Logout) or when another user signs in.
 *  - 503 means maintenance, not offline: it is passed through and never cached.
 */
(() => {
  'use strict';

  const C = self.__PWA || {};
  const V = C.version || 'dev';

  // Path tenants (TenantResolver) register this same script for their own scope, e.g. /t/acme/.
  // Cache Storage is shared by the whole origin, so the static and data caches of a tenant carry
  // its namespace: "@" + a readable slug + "." + a hash of the exact scope path, e.g.
  // pwa-@t-acme.1a2b3c4d-pages-… ('@' never occurs in the plain names; the hash keeps /t/a-b/ and
  // /t/a/b/ apart). The worker for the configured scope keeps the plain names. The user identity
  // and the offline queue stay shared by the origin: one sign-in purges every tenant's user data.
  const fnv1a = (text) => {
    let hash = 0x811c9dc5;
    for (let i = 0; i < text.length; i++) hash = Math.imul(hash ^ text.charCodeAt(i), 0x01000193) >>> 0;
    return hash.toString(16).padStart(8, '0');
  };
  const scopePath = new URL(self.registration?.scope || '/', self.location.origin).pathname;
  const NS = !C.scope || scopePath === C.scope
    ? ''
    : `@${scopePath.replace(/^\/|\/$/g, '').replace(/[^a-z0-9]+/gi, '-').toLowerCase().slice(0, 32)}.${fnv1a(scopePath)}-`;
  // User data caches of every namespace on the origin: pwa-[@slug.hash-](pages|rule)-…
  const USER_CACHE = /^pwa-(@[a-z0-9-]*\.[0-9a-f]{8}-)?(pages|rule)-/;
  const META_CACHE = /^pwa-(@[a-z0-9-]*\.[0-9a-f]{8}-)?meta$/;
  const SHARED_META = 'pwa-meta';
  const STATIC_PREFIX = `pwa-${NS}static-`;
  const STATIC = `${STATIC_PREFIX}${V}`;
  const META = `pwa-${NS}meta`;
  const PAGES_PREFIX = `pwa-${NS}pages-`;
  const RULE_PREFIX = `pwa-${NS}rule-`;
  const SHARED_PREFIX = `pwa-${NS}shared-`;
  // Cache names of 0.1.x workers, removed on activation (only the root worker ever made them).
  const LEGACY = (name) => NS === '' && (name === 'pwa-images' || name.startsWith('pwa-images-') || name.startsWith('pwa-api-'));
  const DB_NAME = 'pwa';
  const QUEUE = 'queue';
  const SYNC_TAG = 'pwa-queue';
  const CACHED_AT = 'X-PWA-Cached-At';
  const WARM_TAG = 'pwa-warm';
  const WARM_LIMIT = 50;
  const WARM_CONCURRENCY = 2;

  /* ------------------------------------------------------------------ *
   * Helpers
   * ------------------------------------------------------------------ */

  const origin = self.location.origin;
  const workerPath = self.location.pathname;
  const sameOrigin = (url) => url.origin === origin;

  const toRegExp = (pattern) =>
    new RegExp('^' + pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');

  const neverCache = (C.neverCache || []).map(toRegExp);
  const matches = (patterns, path) => patterns.some((re) => re.test(path));

  const broadcast = async (message) => {
    const clients = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
    clients.forEach((client) => client.postMessage({ source: 'pwa', ...message }));
  };

  const metaStore = (name) => ({
    async get(key) {
      const res = await (await caches.open(name)).match(`/__pwa/${key}`);
      return res ? res.text() : null;
    },
    async set(key, value) {
      await (await caches.open(name)).put(`/__pwa/${key}`, new Response(String(value)));
    },
    async delete(key) {
      await (await caches.open(name)).delete(`/__pwa/${key}`);
    },
  });

  const meta = metaStore(META);          // this registration: version, warm-up list
  const sharedMeta = metaStore(SHARED_META); // the whole origin: current user

  const timeout = (ms) => new Promise((resolve) => setTimeout(() => resolve(null), ms));

  /* ------------------------------------------------------------------ *
   * User separation: X-PWA-User header (a hash, never the id)
   * ------------------------------------------------------------------ */

  let currentUser = null;

  // Workers of other path tenants learn about a sign-in at once, not on their next response.
  const identityChannel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('pwa-identity') : null;
  identityChannel?.addEventListener('message', (event) => {
    if (validUser(event.data?.user)) currentUser = event.data.user;
  });

  const currentUserHash = async () => {
    if (currentUser === null) currentUser = (await sharedMeta.get('user')) || 'guest';
    return currentUser;
  };

  /** Per-user caches of every namespace; with `keepUser`, all except that user's. */
  const userCaches = async (keepUser) => {
    const keys = await caches.keys();
    return keys.filter((k) => USER_CACHE.test(k) && (keepUser === undefined || !k.endsWith(`-${keepUser}`)));
  };

  const purgeUserData = async (keepUser) => {
    const keys = await caches.keys();
    await Promise.all((await userCaches(keepUser)).map((k) => caches.delete(k)));
    await queueClear();
    // Warm-up lists name the previous user's pages, in every namespace.
    await Promise.all(keys.filter((k) => META_CACHE.test(k)).map((k) => metaStore(k).delete('warm')));
  };

  const switchUser = async (user) => {
    currentUser = user;
    await sharedMeta.set('user', user);
    identityChannel?.postMessage({ user });
  };

  // Valid identities: 'guest' or the 16-hex HMAC from Pwa::userHash().
  const validUser = (user) => user === 'guest' || /^[a-f0-9]{16}$/.test(user || '');

  /** Resolves to true when another user signed in (not on logout). */
  const observeIdentity = async (response) => {
    const logout = response.headers.get('X-PWA-Logout') === '1';
    const user = response.headers.get('X-PWA-User');

    if (logout) {
      await purgeUserData();
      await switchUser('guest');
      await broadcast({ type: 'logout' });
      return false;
    }

    if (validUser(user) && user !== (await currentUserHash())) {
      await purgeUserData(user);
      await switchUser(user);
      await broadcast({ type: 'identity', user });
      return user !== 'guest';
    }

    return false;
  };

  // Only our own server may change the identity. Checked on the final response, not the request:
  // a same-origin request can be redirected elsewhere, and a CORS response may expose the headers.
  const ownResponse = (request, response) => {
    if (['cors', 'opaque', 'opaqueredirect', 'error'].includes(response.type)) return false;
    return sameOrigin(new URL(response.url || request.url));
  };

  const observeFrom = async (request, response) =>
    ownResponse(request, response) ? observeIdentity(response) : false;

  /* ------------------------------------------------------------------ *
   * Offline queue (IndexedDB "pwa" / "queue")
   * ------------------------------------------------------------------ */

  const db = () =>
    new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = () => req.result.createObjectStore(QUEUE, { keyPath: 'id', autoIncrement: true });
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });

  const tx = async (mode, fn) => {
    const conn = await db();
    return new Promise((resolve, reject) => {
      const t = conn.transaction(QUEUE, mode);
      const result = fn(t.objectStore(QUEUE));
      t.oncomplete = () => { conn.close(); resolve(result && 'result' in result ? result.result : undefined); };
      t.onerror = () => { conn.close(); reject(t.error); };
    });
  };

  // Only these headers are stored; cookies and authorization never are.
  const QUEUED_HEADERS = ['content-type', 'accept', 'x-csrf-token', 'x-xsrf-token', 'x-requested-with', 'x-inertia', 'x-livewire'];

  const queuePush = async (request) => {
    const headers = {};
    request.headers.forEach((value, key) => {
      if (QUEUED_HEADERS.includes(key)) headers[key] = value;
    });
    const body = await request.clone().arrayBuffer();
    await tx('readwrite', (store) => store.add({ url: request.url, method: request.method, headers, body, at: Date.now() }));
    if (self.registration.sync) {
      try { await self.registration.sync.register(SYNC_TAG); } catch { /* Background Sync unavailable */ }
    }
    await broadcast({ type: 'queued', count: await queueCount() });
  };

  const queueAll = () => tx('readonly', (store) => store.getAll());
  const queueCount = () => tx('readonly', (store) => store.count());
  const queueDelete = (id) => tx('readwrite', (store) => store.delete(id));
  const queueClear = async () => {
    try { await tx('readwrite', (store) => store.clear()); } catch { /* database not created yet */ }
  };

  const freshCsrf = async () => {
    try {
      const res = await fetch(C.sessionUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      return res.ok ? (await res.json()).csrf : null;
    } catch {
      return null;
    }
  };

  const withToken = (entry, token) => {
    const headers = { ...entry.headers, 'x-csrf-token': token };
    let body = entry.body;
    if ((headers['content-type'] || '').includes('application/x-www-form-urlencoded')) {
      const params = new URLSearchParams(new TextDecoder().decode(body));
      if (params.has('_token')) params.set('_token', token);
      body = new TextEncoder().encode(params.toString());
    }
    return { headers, body };
  };

  let replaying = false;

  const replayQueue = async () => {
    if (replaying) return;
    replaying = true;
    try {
      let token = null;
      for (const entry of await queueAll()) {
        const init = { method: entry.method, headers: entry.headers, body: entry.body, credentials: 'same-origin' };
        let res;
        try {
          res = await fetch(entry.url, init);
          if (res.status === 419) {
            token ??= await freshCsrf();
            if (token) res = await fetch(entry.url, { ...init, ...withToken(entry, token) });
          }
        } catch {
          break; // still offline
        }
        if (res.status === 503) break; // maintenance, try later
        await queueDelete(entry.id);
        await broadcast({ type: 'replayed', url: entry.url, status: res.status });
      }
    } finally {
      replaying = false;
      await broadcast({ type: 'queue', count: await queueCount() });
    }
  };

  /* ------------------------------------------------------------------ *
   * Error telemetry
   * ------------------------------------------------------------------ */

  const errors = [];
  let errorTimer = null;

  const report = (type, error) => {
    if (!C.telemetry || errors.length >= 20) return;
    errors.push({
      type,
      message: String((error && error.message) || error).slice(0, 500),
      stack: error && error.stack ? String(error.stack).slice(0, 2000) : null,
      version: V,
    });
    clearTimeout(errorTimer);
    errorTimer = setTimeout(() => {
      const events = errors.splice(0);
      fetch(C.telemetry, {
        method: 'POST',
        keepalive: true,
        credentials: 'omit',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ source: 'sw', events }),
      }).catch(() => {});
    }, 3000);
  };

  self.addEventListener('error', (e) => report('error', e.error || e.message));
  self.addEventListener('unhandledrejection', (e) => report('unhandledrejection', e.reason));

  /* ------------------------------------------------------------------ *
   * Storage
   * ------------------------------------------------------------------ */

  const cacheable = (response) => {
    if (!response || response.status !== 200 || response.type === 'opaque') return false;
    const cc = (response.headers.get('Cache-Control') || '').toLowerCase();
    return !cc.includes('no-store');
  };

  // A shared cache must never hold a signed-in user's data.
  const shareable = (response) => {
    const cc = (response.headers.get('Cache-Control') || '').toLowerCase();
    const user = response.headers.get('X-PWA-User');
    return !cc.includes('private') && (!user || user === 'guest');
  };

  const storable = (response, options) => cacheable(response) && (!options.shared || shareable(response));

  // HTML only with the server's consent (middleware pwa.cache).
  const pageCacheable = (response) => cacheable(response) && response.headers.get('X-PWA-Cache') === 'allow';

  // Stored copies carry their storage time so max_age can be enforced on read.
  const stamp = async (response) => {
    const headers = new Headers(response.headers);
    headers.set(CACHED_AT, String(Date.now()));
    return new Response(await response.arrayBuffer(), { status: response.status, statusText: response.statusText, headers });
  };

  const fresh = (response, maxAge) =>
    !maxAge || Date.now() - Number(response.headers.get(CACHED_AT) || 0) <= maxAge * 1000;

  /** Drops expired entries, then the oldest ones above maxEntries. */
  const trim = async (cacheName, { maxEntries, maxAge } = {}) => {
    if (!maxEntries && !maxAge) return;
    const cache = await caches.open(cacheName);
    let keys = await cache.keys();
    if (maxAge) {
      const expired = [];
      for (const key of keys) {
        const hit = await cache.match(key);
        if (hit && !fresh(hit, maxAge)) expired.push(key);
      }
      await Promise.all(expired.map((k) => cache.delete(k)));
      keys = keys.filter((k) => !expired.includes(k));
    }
    if (maxEntries && keys.length > maxEntries) {
      await Promise.all(keys.slice(0, keys.length - maxEntries).map((k) => cache.delete(k)));
    }
  };

  const store = async (cacheName, request, response, options) => {
    if (!storable(response, options)) return;
    // A per-user cache only takes responses of that user (identity may have changed meanwhile).
    const owner = response.headers.get('X-PWA-User');
    if (options.forUser && owner && owner !== options.forUser) return;
    const cache = await caches.open(cacheName);
    await cache.put(request, await stamp(response));
    await trim(cacheName, options);
  };

  /* ------------------------------------------------------------------ *
   * Strategies
   *
   * Each takes the FetchEvent and { cacheName, maxEntries, maxAge, timeout, shared }
   * and resolves to a Response. They are exposed to sw.extend as PwaWorker.strategies.
   * ------------------------------------------------------------------ */

  const lookup = async (request, options) => {
    const cached = await caches.match(request, { cacheName: options.cacheName });
    return cached && fresh(cached, options.maxAge) ? cached : null;
  };

  const maintenance = (response) => broadcast({ type: 'maintenance', retryAfter: response.headers.get('Retry-After') });

  const strategies = {
    async networkFirst(event, options) {
      const { request } = event;
      const network = fetch(request).then(async (response) => {
        await observeFrom(request, response);
        if (response.status === 503) {
          await maintenance(response);
          return (await lookup(request, options)) || response;
        }
        event.waitUntil(store(options.cacheName, request, response.clone(), options));
        return response;
      });

      if (options.timeout) {
        const winner = await Promise.race([network.catch(() => null), timeout(options.timeout * 1000)]);
        if (winner) return winner;
        const cached = await lookup(request, options);
        if (cached) {
          event.waitUntil(network.catch(() => {}));
          return cached;
        }
      }

      try {
        return await network;
      } catch (error) {
        const cached = await lookup(request, options);
        if (cached) return cached;
        throw error;
      }
    },

    async cacheFirst(event, options) {
      const { request } = event;
      const cached = await caches.match(request, { cacheName: options.cacheName });
      if (cached && fresh(cached, options.maxAge)) return cached;
      try {
        const response = await fetch(request);
        await observeFrom(request, response);
        event.waitUntil(store(options.cacheName, request, response.clone(), options));
        return response;
      } catch (error) {
        if (cached) return cached; // expired, but better than nothing offline
        throw error;
      }
    },

    async staleWhileRevalidate(event, options) {
      const { request } = event;
      const cached = await caches.match(request, { cacheName: options.cacheName });
      const network = fetch(request).then(async (response) => {
        await observeFrom(request, response);
        await store(options.cacheName, request, response.clone(), options);
        return response;
      });
      if (cached && fresh(cached, options.maxAge)) {
        event.waitUntil(network.catch(() => {}));
        return cached;
      }
      // Expired copy: prefer the network, but an old one beats none while offline.
      return cached ? network.catch(() => cached) : network;
    },

    networkOnly(event) {
      return fetch(event.request);
    },

    async cacheOnly(event, options) {
      const cached = (await lookup(event.request, options)) || (await caches.match(event.request, { cacheName: STATIC }));
      return cached || new Response('', { status: 504, statusText: 'Not cached' });
    },
  };

  const STRATEGY = {
    'network-first': strategies.networkFirst,
    'cache-first': strategies.cacheFirst,
    'stale-while-revalidate': strategies.staleWhileRevalidate,
    'network-only': strategies.networkOnly,
    'cache-only': strategies.cacheOnly,
  };

  /** Cache name for a rule: one per user unless the rule is shared. */
  const cacheNameFor = async (name, { shared = false } = {}) =>
    shared ? `${SHARED_PREFIX}${name}` : `${RULE_PREFIX}${name}-${await currentUserHash()}`;

  /* ------------------------------------------------------------------ *
   * Rules (config pwa.cache.rules, fluent Pwa::serviceWorker()->cache())
   * ------------------------------------------------------------------ */

  const rules = (C.rules || []).map((rule) => ({
    ...rule,
    re: rule.match && rule.match !== '*' ? toRegExp(rule.match) : null,
    destinations: rule.destinations || [],
  }));

  const ruleFor = (request, url) =>
    rules.find((rule) => (!rule.re || rule.re.test(url.pathname))
      && (rule.destinations.length === 0 || rule.destinations.includes(request.destination)));

  const applyRule = async (event, rule) => {
    const forUser = rule.shared ? null : await currentUserHash();
    const options = {
      forUser,
      cacheName: await cacheNameFor(rule.cache, rule),
      maxEntries: rule.maxEntries,
      maxAge: rule.maxAge,
      timeout: rule.timeout,
      shared: rule.shared,
    };
    return (STRATEGY[rule.strategy] || strategies.networkFirst)(event, options);
  };

  /* ------------------------------------------------------------------ *
   * Custom routes from sw.extend (self.PwaWorker.route)
   * ------------------------------------------------------------------ */

  const routes = [];

  const routeMatcher = (match) => {
    if (typeof match === 'function') return match;
    if (match instanceof RegExp) return ({ url }) => match.test(url.href);
    if (typeof match === 'string') {
      const re = toRegExp(match);
      return ({ url }) => sameOrigin(url) && re.test(url.pathname);
    }
    throw new TypeError('PwaWorker.route(match): match must be a path pattern, a RegExp or a function');
  };

  /* ------------------------------------------------------------------ *
   * Navigation and mutations
   * ------------------------------------------------------------------ */

  const offlineFallback = async () => {
    if (!C.offlineUrl) return Response.error();
    const cached = await caches.match(C.offlineUrl, { cacheName: STATIC });
    return cached || Response.error();
  };

  const handleNavigation = async (event) => {
    const { request } = event;
    const pages = `${PAGES_PREFIX}${await currentUserHash()}`;

    const network = (async () => {
      const preloaded = await event.preloadResponse;
      const response = preloaded || (await fetch(request));
      // A sign-in navigation: download the new user's warm-up pages in the background.
      if (await observeFrom(request, response)) event.waitUntil(warmFromServer());

      if (response.status === 503) {
        await maintenance(response);
        return response; // the maintenance screen, never cached
      }

      if (pageCacheable(response)) {
        const copy = response.clone();
        event.waitUntil(caches.open(`${PAGES_PREFIX}${await currentUserHash()}`).then((c) => c.put(request, copy)));
      }

      return response;
    })();

    try {
      const winner = await Promise.race([network, timeout(C.navigationTimeout || 3000)]);
      if (winner) return winner;

      // Slow network: use the cache if possible, otherwise keep waiting.
      const cached = await caches.match(request, { cacheName: pages });
      return cached || (await network);
    } catch {
      const cached = await caches.match(request, { cacheName: pages });
      if (cached) return cached;
      await broadcast({ type: 'offline' });
      return offlineFallback();
    }
  };

  const handleMutation = async (request) => {
    const copy = request.clone();
    try {
      const response = await fetch(request);
      // XHR logins and logouts (Inertia, Livewire) never navigate: read the identity here too.
      await observeFrom(request, response);
      return response;
    } catch {
      await queuePush(copy);
      await broadcast({ type: 'offline' });
      return new Response(JSON.stringify({ queued: true }), {
        status: 202,
        headers: { 'Content-Type': 'application/json', 'X-PWA-Queued': '1' },
      });
    }
  };

  const passThrough = async (request) => {
    const response = await fetch(request);
    await observeFrom(request, response);
    return response;
  };

  /* ------------------------------------------------------------------ *
   * Warm-up (offline.warm, Pwa::warmUsing): pages downloaded ahead of time.
   * Stored under the same rule as navigations: only with X-PWA-Cache: allow,
   * only for the user who asked, never redirects.
   * ------------------------------------------------------------------ */

  let warming = Promise.resolve();
  let lastWarm = { user: null, at: 0 };

  const warmable = (path) => {
    let url;
    try { url = new URL(path, origin); } catch { return null; }
    if (!sameOrigin(url) || url.pathname === workerPath || matches(neverCache, url.pathname)) return null;
    url.hash = '';
    return url.href;
  };

  const warmNow = async (urls, user, force) => {
    if (self.navigator?.connection?.saveData) return { count: 0, skipped: 0, failed: 0 };
    if (user !== undefined) {
      if (!validUser(user)) return { count: 0, skipped: 0, failed: 0 };
      await observeIdentity(new Response(null, { headers: { 'X-PWA-User': user } }));
    }

    const owner = await currentUserHash();
    // The client and a sign-in navigation can both ask within moments of each other.
    if (!force && lastWarm.user === owner && Date.now() - lastWarm.at < 30_000) return null;
    lastWarm = { user: owner, at: Date.now() };

    const list = [...new Set((Array.isArray(urls) ? urls : []).map(String).map(warmable).filter(Boolean))].slice(0, WARM_LIMIT);
    await meta.set('warm', JSON.stringify({ user: owner, urls: list }));

    const cache = await caches.open(`${PAGES_PREFIX}${owner}`);
    const result = { count: 0, skipped: 0, failed: 0 };
    const queue = [...list];

    const next = async () => {
      for (let url = queue.shift(); url; url = queue.shift()) {
        if ((await currentUserHash()) !== owner) return; // signed out meanwhile
        try {
          const request = new Request(url, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-PWA-Warm': '1' } });
          const response = await fetch(request);
          await observeFrom(request, response);
          const pageUser = response.headers.get('X-PWA-User');
          if (!response.redirected && pageCacheable(response) && (!pageUser || pageUser === owner)
            && (await currentUserHash()) === owner) {
            await cache.put(url, response);
            result.count++;
          } else {
            result.skipped++;
          }
        } catch {
          result.failed++;
        }
      }
    };

    await Promise.all(Array.from({ length: WARM_CONCURRENCY }, next));
    await broadcast({ type: 'warmed', ...result });
    return result;
  };

  /** One warm-up at a time; a later one waits for the running one. */
  const warm = (urls, user, force = false) => {
    const run = warming.then(() => warmNow(urls, user, force));
    warming = run.catch(() => null);
    return run;
  };

  // The worker asks the server itself after a sign-in navigation (the page that asked is gone).
  const warmFromServer = async () => {
    if (!C.warm || !C.sessionUrl) return;
    try {
      const response = await fetch(new Request(C.sessionUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } }));
      if (!response.ok) return;
      const data = await response.json();
      if (Array.isArray(data.warm) && data.warm.length > 0) await warm(data.warm, data.user);
    } catch (error) {
      report('warm', error);
    }
  };

  const precached = (url) => url.pathname.startsWith(C.buildPrefix || '/build/') || (C.precache || []).includes(url.pathname);

  /* ------------------------------------------------------------------ *
   * Lifecycle
   * ------------------------------------------------------------------ */

  self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
      const cache = await caches.open(STATIC);
      // One by one, so a single missing file does not block the installation.
      await Promise.all((C.precache || []).map(async (url) => {
        try {
          const res = await fetch(new Request(url, { cache: 'reload', credentials: 'same-origin' }));
          if (res.ok) await cache.put(url, res);
        } catch (e) {
          report('precache', e);
        }
      }));
    })());
  });

  self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
      const ruleNames = new Set(rules.map((r) => r.cache));
      const orphan = (name) => {
        if (name.startsWith(SHARED_PREFIX)) return !ruleNames.has(name.slice(SHARED_PREFIX.length));
        if (name.startsWith(RULE_PREFIX)) return !ruleNames.has(name.slice(RULE_PREFIX.length).replace(/-[^-]+$/, ''));
        return false;
      };

      const keys = await caches.keys();
      await Promise.all(keys
        .filter((k) => (k.startsWith(STATIC_PREFIX) && k !== STATIC) || LEGACY(k) || orphan(k))
        .map((k) => caches.delete(k)));

      // Pages reference assets of the old version: drop them after an update.
      const previous = await meta.get('version');
      if (previous && previous !== V) {
        await Promise.all((await caches.keys()).filter((k) => k.startsWith(PAGES_PREFIX)).map((k) => caches.delete(k)));
      }
      await meta.set('version', V);

      if (self.registration.navigationPreload) {
        try { await self.registration.navigationPreload.enable(); } catch { /* unsupported */ }
      }
      await self.clients.claim();
      await broadcast({ type: 'activated', version: V });
    })());
  });

  self.addEventListener('message', (event) => {
    const data = event.data || {};
    switch (data.type) {
      case 'SKIP_WAITING':
        self.skipWaiting();
        break;
      case 'REPLAY_QUEUE':
        event.waitUntil(replayQueue());
        break;
      case 'CLEAR_USER':
        event.waitUntil(purgeUserData().then(() => switchUser('guest')));
        break;
      case 'IDENTITY':
        // From the client's keep-alive (/pwa/session is never routed through the worker).
        if (validUser(data.user)) {
          event.waitUntil(observeIdentity(new Response(null, { headers: { 'X-PWA-User': data.user } })));
        }
        break;
      case 'WARM':
        // From the client (keep-alive, Pwa.warm()) with the list from /pwa/session.
        event.waitUntil(warm(data.urls, data.user, data.force === true));
        break;
      case 'GET_VERSION':
        if (event.ports[0]) event.ports[0].postMessage({ version: V });
        break;
      default:
    }
  });

  self.addEventListener('sync', (event) => {
    if (event.tag === SYNC_TAG) event.waitUntil(replayQueue());
  });

  // Periodic Background Sync (installed Chromium apps): refresh the last list for the same user.
  self.addEventListener('periodicsync', (event) => {
    if (event.tag !== WARM_TAG) return;
    event.waitUntil((async () => {
      const saved = JSON.parse((await meta.get('warm')) || 'null');
      if (saved && saved.user === (await currentUserHash())) await warm(saved.urls, undefined, true);
    })());
  });

  /* ------------------------------------------------------------------ *
   * Fetch routing. Order: worker script → never_cache (pass-through) → mutations →
   * navigations → precached build files → sw.extend routes → rules.
   * ------------------------------------------------------------------ */

  self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    const local = sameOrigin(url);

    if (local && url.pathname === workerPath) return;

    // The client script (?v=hash) is precached: pages served from the cache offline need it.
    if (local && C.client && url.pathname === C.client && request.method === 'GET') {
      event.respondWith(strategies.cacheFirst(event, { cacheName: STATIC }));
      return;
    }

    // never_cache: never stored or queued. Fetch/XHR requests still pass through the worker so it
    // sees identity changes (XHR login/logout of Inertia and Livewire hit /login, /logout).
    if (local && matches(neverCache, url.pathname)) {
      if (request.mode !== 'navigate') event.respondWith(passThrough(request));
      return;
    }

    if (request.method !== 'GET') {
      // Always through the worker (identity of XHR logins), queued only with offline.queue.
      // Livewire updates are never queued: a replayed snapshot would be stale (Livewire 4 posts to
      // /livewire-{hash}/update, which an older never_cache list does not cover).
      if (local && request.mode !== 'navigate') {
        const queueable = C.queue && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method) && !request.headers.has('x-livewire');
        event.respondWith(queueable ? handleMutation(request) : passThrough(request));
      }
      return;
    }

    if (local && request.mode === 'navigate') {
      event.respondWith(handleNavigation(event));
      return;
    }

    if (local && precached(url)) {
      event.respondWith(strategies.cacheFirst(event, { cacheName: STATIC }));
      return;
    }

    const context = { url, request, event };
    const route = routes.find((r) => r.matches(context));
    if (route) {
      event.respondWith(Promise.resolve(route.handler(event, context)));
      return;
    }

    if (!local) return;

    const rule = ruleFor(request, url);
    if (rule) event.respondWith(applyRule(event, rule));
  });

  /* ------------------------------------------------------------------ *
   * Web push (pwa.push.enabled with handle_in_worker). Every push shows a notification:
   * Safari cancels subscriptions that receive pushes without one.
   * ------------------------------------------------------------------ */

  const P = C.push || null;
  const scopeUrl = () => new URL(self.registration?.scope || '/', origin).href;

  /** Same-origin targets only; anything else opens the app's scope. */
  const pushTarget = (raw) => {
    try {
      const url = new URL(raw || scopeUrl(), scopeUrl());
      return sameOrigin(url) ? url.href : scopeUrl();
    } catch {
      return scopeUrl();
    }
  };

  const readPush = (event) => {
    if (!event.data) return {};
    try {
      return event.data.json() || {};
    } catch {
      return { title: event.data.text() };
    }
  };

  // Declarative Web Push (WebKit): { web_push: 8030, notification: { title, navigate, … }, app_badge }
  const fromDeclarative = (data) => {
    const n = data.notification || {};
    const actions = Array.isArray(n.actions) ? n.actions : [];
    return {
      title: n.title,
      appBadge: data.app_badge === undefined ? undefined : Number(data.app_badge),
      options: {
        body: n.body,
        lang: n.lang,
        dir: n.dir,
        silent: n.silent,
        tag: n.tag,
        actions: actions.map((a) => ({ action: a.action || a.navigate, title: a.title })),
        data: { url: n.navigate, actions: Object.fromEntries(actions.map((a) => [a.action || a.navigate, a.navigate])) },
      },
    };
  };

  // pwa.push.analytics: shown / click / close with the message id() and tag, sent without cookies.
  const pushEvents = [];
  let pushEventTimer = null;

  const track = (type, data = {}, tag = null, action = null) => {
    if (!P?.events || pushEvents.length >= 20) return Promise.resolve();
    pushEvents.push({ type, id: data.id ?? null, tag: tag ?? null, action: action || null });
    clearTimeout(pushEventTimer);
    // A short delay batches a burst; the push and click events wait for it with waitUntil.
    return new Promise((resolve) => {
      pushEventTimer = setTimeout(() => {
        const events = pushEvents.splice(0);
        fetch(P.events, {
          method: 'POST',
          keepalive: true,
          credentials: 'omit',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ events }),
        }).catch(() => {}).finally(resolve);
      }, 500);
    });
  };

  const showPush = async (data) => {
    const message = data.web_push === 8030 ? fromDeclarative(data) : data;
    let title = message.title || P.name;
    let options = { ...(message.options || {}) };

    // Sent to another user than the one signed in on this device (shared device, signed out):
    // say that something arrived, never what.
    if (message.user && message.user !== (await currentUserHash())) {
      title = P.name;
      options = { body: P.fallback, tag: 'pwa-private', data: { url: scopeUrl() } };
    }

    options.icon ??= P.icon || undefined;
    options.badge ??= P.badge || undefined;
    await self.registration.showNotification(title, options);

    if (Number.isInteger(message.appBadge) && self.navigator?.setAppBadge) {
      try {
        await (message.appBadge > 0 ? self.navigator.setAppBadge(message.appBadge) : self.navigator.clearAppBadge());
      } catch { /* Badging API unavailable */ }
    }
    await broadcast({ type: 'push-received', tag: options.tag ?? null });
    await track('shown', options.data || {}, options.tag);
  };

  /** Focuses a window showing the target, else navigates a window of the app, else opens one. */
  const openFromNotification = async (href, action) => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const exact = windows.find((client) => client.url === href);
    const inApp = windows.find((client) => client.url.startsWith(scopeUrl()));

    try {
      if (exact) await exact.focus();
      else if (inApp?.navigate) await (await inApp.navigate(href))?.focus();
      else await self.clients.openWindow(href);
    } catch {
      await self.clients.openWindow(href);
    }
    await broadcast({ type: 'notification-click', url: href, action: action || null });
  };

  const keyBytes = (base64url) => {
    const raw = atob(base64url.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(base64url.length / 4) * 4, '='));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
  };

  // The push service rotated the subscription: store the new one for the same user. The server
  // accepts it only in place of an endpoint that user owns.
  const renewSubscription = async (event) => {
    const subscription = event.newSubscription
      || await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(P.key) });
    const replaces = event.oldSubscription?.endpoint;
    if (!subscription || !replaces) return;
    const token = await freshCsrf();
    if (!token) return;
    const encoding = (self.PushManager?.supportedContentEncodings || []).includes('aes128gcm') ? 'aes128gcm' : 'aesgcm';
    await fetch(P.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ ...subscription.toJSON(), contentEncoding: encoding, replaces }),
    });
    await broadcast({ type: 'push-renewed' });
  };

  if (P) {
    self.addEventListener('push', (event) => {
      event.waitUntil(showPush(readPush(event)));
    });

    self.addEventListener('notificationclick', (event) => {
      event.notification.close();
      const data = event.notification.data || {};
      const target = pushTarget((event.action && data.actions?.[event.action]) || data.url);
      event.waitUntil(Promise.all([openFromNotification(target, event.action), track('click', data, event.notification.tag, event.action)]));
    });

    self.addEventListener('notificationclose', (event) => {
      event.waitUntil(track('close', event.notification.data || {}, event.notification.tag));
    });

    self.addEventListener('pushsubscriptionchange', (event) => {
      event.waitUntil(renewSubscription(event).catch((error) => report('push', error)));
    });
  }

  /* ------------------------------------------------------------------ *
   * Extension API for sw.extend files
   * ------------------------------------------------------------------ */

  self.PwaWorker = Object.freeze({
    version: V,
    config: C,
    /** Cache name namespace of this registration: '' or '@{scope-slug}-' for path tenants. */
    namespace: NS,
    strategies,
    /**
     * Handles matching GET requests that are not navigations, before the cache rules.
     * `match`: a path pattern (same origin, `*` wildcard), a RegExp tested on the full URL,
     * or a function ({ url, request, event }) => boolean. `handler(event, context)` returns a Response.
     */
    route(match, handler) {
      if (typeof handler !== 'function') throw new TypeError('PwaWorker.route(match, handler): handler must be a function');
      routes.push({ matches: routeMatcher(match), handler });
    },
    /** Cache name for your data: per user (purged on logout) unless { shared: true }. */
    cacheName: cacheNameFor,
    currentUser: currentUserHash,
    /** Downloads pages for offline use (stored only with X-PWA-Cache: allow). */
    warm: (urls) => warm(urls, undefined, true),
    broadcast,
  });
})();
