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
  const STATIC = `pwa-static-${V}`;
  const META = 'pwa-meta';
  const PAGES_PREFIX = 'pwa-pages-';
  const RULE_PREFIX = 'pwa-rule-';
  const SHARED_PREFIX = 'pwa-shared-';
  // Cache names of 0.1.x workers, removed on activation.
  const LEGACY = (name) => name === 'pwa-images' || name.startsWith('pwa-images-') || name.startsWith('pwa-api-');
  const DB_NAME = 'pwa';
  const QUEUE = 'queue';
  const SYNC_TAG = 'pwa-queue';
  const CACHED_AT = 'X-PWA-Cached-At';

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

  const meta = {
    async get(key) {
      const res = await (await caches.open(META)).match(`/__pwa/${key}`);
      return res ? res.text() : null;
    },
    async set(key, value) {
      await (await caches.open(META)).put(`/__pwa/${key}`, new Response(String(value)));
    },
  };

  const timeout = (ms) => new Promise((resolve) => setTimeout(() => resolve(null), ms));

  /* ------------------------------------------------------------------ *
   * User separation: X-PWA-User header (a hash, never the id)
   * ------------------------------------------------------------------ */

  let currentUser = null;

  const currentUserHash = async () => {
    if (currentUser === null) currentUser = (await meta.get('user')) || 'guest';
    return currentUser;
  };

  /** Per-user caches; with `keepUser`, all except that user's. */
  const userCaches = async (keepUser) => {
    const keys = await caches.keys();
    return keys.filter((k) => (k.startsWith(PAGES_PREFIX) || k.startsWith(RULE_PREFIX))
      && (keepUser === undefined || !k.endsWith(`-${keepUser}`)));
  };

  const purgeUserData = async (keepUser) => {
    const stale = await userCaches(keepUser);
    await Promise.all(stale.map((k) => caches.delete(k)));
    await queueClear();
  };

  const switchUser = async (user) => {
    currentUser = user;
    await meta.set('user', user);
  };

  // Valid identities: 'guest' or the 16-hex HMAC from Pwa::userHash().
  const validUser = (user) => user === 'guest' || /^[a-f0-9]{16}$/.test(user || '');

  const observeIdentity = async (response) => {
    const logout = response.headers.get('X-PWA-Logout') === '1';
    const user = response.headers.get('X-PWA-User');

    if (logout) {
      await purgeUserData();
      await switchUser('guest');
      await broadcast({ type: 'logout' });
      return;
    }

    if (validUser(user) && user !== (await currentUserHash())) {
      await purgeUserData(user);
      await switchUser(user);
    }
  };

  // Only our own server may change the identity. Checked on the final response, not the request:
  // a same-origin request can be redirected elsewhere, and a CORS response may expose the headers.
  const ownResponse = (request, response) => {
    if (['cors', 'opaque', 'opaqueredirect', 'error'].includes(response.type)) return false;
    return sameOrigin(new URL(response.url || request.url));
  };

  const observeFrom = async (request, response) => {
    if (ownResponse(request, response)) await observeIdentity(response);
  };

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
      await observeFrom(request, response);

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
        .filter((k) => (k.startsWith('pwa-static-') && k !== STATIC) || LEGACY(k) || orphan(k))
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
      case 'GET_VERSION':
        if (event.ports[0]) event.ports[0].postMessage({ version: V });
        break;
      default:
    }
  });

  self.addEventListener('sync', (event) => {
    if (event.tag === SYNC_TAG) event.waitUntil(replayQueue());
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

    // never_cache: never stored or queued. Fetch/XHR requests still pass through the worker so it
    // sees identity changes (XHR login/logout of Inertia and Livewire hit /login, /logout).
    if (local && matches(neverCache, url.pathname)) {
      if (request.mode !== 'navigate') event.respondWith(passThrough(request));
      return;
    }

    if (request.method !== 'GET') {
      if (local && C.queue && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method) && request.mode !== 'navigate') {
        event.respondWith(handleMutation(request));
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
   * Extension API for sw.extend files
   * ------------------------------------------------------------------ */

  self.PwaWorker = Object.freeze({
    version: V,
    config: C,
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
    broadcast,
  });
})();
