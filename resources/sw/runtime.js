/*
 * nyoncode/laravel-pwa — runtime service workeru.
 *
 * Konfigurace přichází v self.__PWA (sestavuje ServiceWorkerBuilder).
 * Soubory z config('pwa.sw.import') jsou načtené před tímto kódem a mohou
 * registrovat vlastní listenery (push, notificationclick …).
 */
(() => {
  'use strict';

  const C = self.__PWA || {};
  const V = C.version || 'dev';
  const STATIC = `pwa-static-${V}`;
  const IMAGES = 'pwa-images';
  const META = 'pwa-meta';
  const PAGES_PREFIX = 'pwa-pages-';
  const API_PREFIX = 'pwa-api-';
  const DB_NAME = 'pwa';
  const QUEUE = 'queue';
  const SYNC_TAG = 'pwa-queue';

  /* ------------------------------------------------------------------ *
   * Pomocné funkce
   * ------------------------------------------------------------------ */

  const origin = self.location.origin;
  const sameOrigin = (url) => url.origin === origin;

  const toRegExp = (pattern) =>
    new RegExp('^' + pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');

  const neverCache = (C.neverCache || []).map(toRegExp);
  const apiRules = (C.api || []).map(toRegExp);
  const matches = (rules, path) => rules.some((r) => r.test(path));

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

  const userCaches = async (user) => {
    const keys = await caches.keys();
    return keys.filter((k) => (k.startsWith(PAGES_PREFIX) || k.startsWith(API_PREFIX)) && (user === undefined || !k.endsWith(`-${user}`)));
  };

  /* ------------------------------------------------------------------ *
   * Oddělení uživatelů: hlavička X-PWA-User (hash, nikdy ID)
   * ------------------------------------------------------------------ */

  let currentUser = null;

  const currentUserHash = async () => {
    if (currentUser === null) currentUser = (await meta.get('user')) || 'guest';
    return currentUser;
  };

  const purgeUserData = async (keepUser) => {
    const stale = await userCaches(keepUser);
    await Promise.all(stale.map((k) => caches.delete(k)));
    await queueClear();
  };

  const observeIdentity = async (response) => {
    const logout = response.headers.get('X-PWA-Logout') === '1';
    const user = response.headers.get('X-PWA-User');

    if (logout) {
      await purgeUserData();
      currentUser = 'guest';
      await meta.set('user', 'guest');
      await broadcast({ type: 'logout' });
      return;
    }

    if (user && user !== (await currentUserHash())) {
      await purgeUserData(user);
      currentUser = user;
      await meta.set('user', user);
    }
  };

  /* ------------------------------------------------------------------ *
   * Fronta offline akcí (IndexedDB „pwa“ / „queue“)
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

  const queuePush = async (request) => {
    const headers = {};
    request.headers.forEach((value, key) => {
      if (['content-type', 'accept', 'x-csrf-token', 'x-xsrf-token', 'x-requested-with', 'x-inertia', 'x-livewire'].includes(key)) {
        headers[key] = value;
      }
    });
    const body = await request.clone().arrayBuffer();
    await tx('readwrite', (store) => store.add({ url: request.url, method: request.method, headers, body, at: Date.now() }));
    if (self.registration.sync) {
      try { await self.registration.sync.register(SYNC_TAG); } catch { /* Background Sync nedostupný */ }
    }
    await broadcast({ type: 'queued', count: await queueCount() });
  };

  const queueAll = () => tx('readonly', (store) => store.getAll());
  const queueCount = () => tx('readonly', (store) => store.count());
  const queueDelete = (id) => tx('readwrite', (store) => store.delete(id));
  const queueClear = async () => {
    try { await tx('readwrite', (store) => store.clear()); } catch { /* DB ještě neexistuje */ }
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
        let init = { method: entry.method, headers: entry.headers, body: entry.body, credentials: 'same-origin' };
        let res;
        try {
          res = await fetch(entry.url, init);
          if (res.status === 419) {
            token ??= await freshCsrf();
            if (token) res = await fetch(entry.url, { ...init, ...withToken(entry, token) });
          }
        } catch {
          break; // stále offline
        }
        if (res.status === 503) break; // údržba, zkusit později
        await queueDelete(entry.id);
        await broadcast({ type: 'replayed', url: entry.url, status: res.status });
      }
    } finally {
      replaying = false;
      await broadcast({ type: 'queue', count: await queueCount() });
    }
  };

  /* ------------------------------------------------------------------ *
   * Telemetrie chyb
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
   * Životní cyklus
   * ------------------------------------------------------------------ */

  self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
      const cache = await caches.open(STATIC);
      // Po jednom, aby jeden chybějící soubor nezablokoval celou instalaci.
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
      const keys = await caches.keys();
      await Promise.all(keys
        .filter((k) => k.startsWith('pwa-static-') && k !== STATIC)
        .map((k) => caches.delete(k)));
      // Stránky odkazují na assety staré verze: po aktualizaci je zahodit.
      const meta_version = await meta.get('version');
      if (meta_version && meta_version !== V) {
        await Promise.all((await userCaches()).filter((k) => k.startsWith(PAGES_PREFIX)).map((k) => caches.delete(k)));
      }
      await meta.set('version', V);
      if (self.registration.navigationPreload) {
        try { await self.registration.navigationPreload.enable(); } catch { /* nepodporováno */ }
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
        event.waitUntil(purgeUserData().then(() => meta.set('user', 'guest')));
        break;
      case 'GET_VERSION':
        event.ports[0] && event.ports[0].postMessage({ version: V });
        break;
      default:
    }
  });

  self.addEventListener('sync', (event) => {
    if (event.tag === SYNC_TAG) event.waitUntil(replayQueue());
  });

  /* ------------------------------------------------------------------ *
   * Strategie
   * ------------------------------------------------------------------ */

  const timeout = (ms) => new Promise((resolve) => setTimeout(() => resolve(null), ms));

  const cacheable = (response) => {
    if (!response || response.status !== 200 || response.type === 'opaque') return false;
    const cc = (response.headers.get('Cache-Control') || '').toLowerCase();
    return !cc.includes('no-store');
  };

  // HTML jen se souhlasem serveru (middleware pwa.cache).
  const pageCacheable = (response) => cacheable(response) && response.headers.get('X-PWA-Cache') === 'allow';

  const offlineFallback = async () => {
    if (!C.offlineUrl) return Response.error();
    const cached = await caches.match(C.offlineUrl, { cacheName: STATIC });
    return cached || Response.error();
  };

  const handleNavigation = async (event) => {
    const { request } = event;
    const user = await currentUserHash();
    const pages = `${PAGES_PREFIX}${user}`;

    const network = (async () => {
      const preloaded = await event.preloadResponse;
      const response = preloaded || (await fetch(request));
      await observeIdentity(response);

      if (response.status === 503) {
        await broadcast({ type: 'maintenance', retryAfter: response.headers.get('Retry-After') });
        return response; // obrazovka údržby, nikdy necachovat
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

      // Síť je pomalá: použít cache, jinak dál čekat.
      const cached = await caches.match(request, { cacheName: pages });
      return cached || (await network);
    } catch (error) {
      const cached = await caches.match(request, { cacheName: pages });
      if (cached) return cached;
      await broadcast({ type: 'offline' });
      return offlineFallback();
    }
  };

  const cacheFirst = async (request, cacheName) => {
    const cached = await caches.match(request);
    if (cached) return cached;
    const response = await fetch(request);
    if (cacheable(response)) {
      const copy = response.clone();
      caches.open(cacheName).then((c) => c.put(request, copy));
    }
    return response;
  };

  const trim = async (cacheName, maxEntries) => {
    if (!maxEntries) return;
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    await Promise.all(keys.slice(0, Math.max(0, keys.length - maxEntries)).map((k) => cache.delete(k)));
  };

  const staleWhileRevalidate = async (event, cacheName, maxEntries) => {
    const { request } = event;
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    const network = fetch(request).then(async (response) => {
      if (cacheable(response)) {
        await cache.put(request, response.clone());
        await trim(cacheName, maxEntries);
      }
      return response;
    });
    if (cached) {
      event.waitUntil(network.catch(() => {}));
      return cached;
    }
    return network;
  };

  const networkFirstApi = async (request) => {
    const cacheName = `${API_PREFIX}${await currentUserHash()}`;
    try {
      const response = await fetch(request);
      if (response.status === 503) {
        await broadcast({ type: 'maintenance', retryAfter: response.headers.get('Retry-After') });
        return (await caches.match(request, { cacheName })) || response;
      }
      if (cacheable(response)) {
        const copy = response.clone();
        caches.open(cacheName).then((c) => c.put(request, copy));
      }
      return response;
    } catch (error) {
      const cached = await caches.match(request, { cacheName });
      if (cached) return cached;
      throw error;
    }
  };

  const handleMutation = async (request) => {
    const copy = request.clone();
    try {
      return await fetch(request);
    } catch (error) {
      await queuePush(copy);
      await broadcast({ type: 'offline' });
      return new Response(JSON.stringify({ queued: true }), {
        status: 202,
        headers: { 'Content-Type': 'application/json', 'X-PWA-Queued': '1' },
      });
    }
  };

  self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (!sameOrigin(url) || url.pathname === '/sw.js' || matches(neverCache, url.pathname)) return;

    if (request.method !== 'GET') {
      if (C.queue && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method) && request.mode !== 'navigate') {
        event.respondWith(handleMutation(request));
      }
      return;
    }

    if (request.mode === 'navigate') {
      event.respondWith(handleNavigation(event));
      return;
    }

    if (url.pathname.startsWith(C.buildPrefix || '/build/') || (C.precache || []).includes(url.pathname)) {
      event.respondWith(cacheFirst(request, STATIC));
      return;
    }

    if (['image', 'font'].includes(request.destination)) {
      event.respondWith(staleWhileRevalidate(event, IMAGES, (C.images || {}).max_entries || 120));
      return;
    }

    if (matches(apiRules, url.pathname)) {
      event.respondWith(networkFirstApi(request));
    }
  });
})();
