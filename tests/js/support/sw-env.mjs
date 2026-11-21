// Simulated service worker global for unit-testing resources/sw/runtime.js.
//
// The runtime is evaluated in a fresh `vm` context per test with an in-memory
// Cache Storage, a scripted `fetch`, fake IndexedDB and recorded client messages.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { IDBFactory, IDBKeyRange } from 'fake-indexeddb';

const ROOT = fileURLToPath(new URL('../../../', import.meta.url));
const RUNTIME = readFileSync(`${ROOT}resources/sw/runtime.js`, 'utf8');

export const ORIGIN = 'https://app.test';

/** Request that resolves relative URLs against the worker origin, as inside a real worker. */
class WorkerRequest extends Request {
  constructor(input, init) {
    super(typeof input === 'string' || input instanceof URL ? new URL(String(input), ORIGIN) : input, init);
  }
}

/** In-memory Cache (one per name). Keys are URLs, like the real Cache API without Vary. */
class MemoryCache {
  constructor() {
    this.entries = new Map();
  }

  key(request) {
    return new URL(typeof request === 'string' ? request : request.url, ORIGIN).href;
  }

  async match(request) {
    const hit = this.entries.get(this.key(request));
    return hit ? hit.clone() : undefined;
  }

  async put(request, response) {
    this.entries.set(this.key(request), response.clone());
  }

  async delete(request) {
    return this.entries.delete(this.key(request));
  }

  async keys() {
    return [...this.entries.keys()].map((url) => new Request(url));
  }
}

class MemoryCacheStorage {
  constructor() {
    this.stores = new Map();
  }

  async open(name) {
    if (!this.stores.has(name)) this.stores.set(name, new MemoryCache());
    return this.stores.get(name);
  }

  async keys() {
    return [...this.stores.keys()];
  }

  async has(name) {
    return this.stores.has(name);
  }

  async delete(name) {
    return this.stores.delete(name);
  }

  async match(request, options = {}) {
    const names = options.cacheName ? [options.cacheName] : [...this.stores.keys()];
    for (const name of names) {
      const hit = await this.stores.get(name)?.match(request);
      if (hit) return hit;
    }
    return undefined;
  }

  /** Test helper: URLs stored in one cache. */
  urls(name) {
    return [...(this.stores.get(name)?.entries.keys() ?? [])].map((u) => new URL(u).pathname + new URL(u).search);
  }
}

/** Request with fields the Fetch constructor refuses (mode: navigate) or never sets (destination). */
export const makeRequest = (path, { mode, destination, ...init } = {}) => {
  const request = new Request(new URL(path, ORIGIN), init);
  if (mode) Object.defineProperty(request, 'mode', { value: mode });
  if (destination !== undefined) Object.defineProperty(request, 'destination', { value: destination });
  return request;
};

/** A rule as CacheRule::toArray() serialises it. */
export const rule = (overrides = {}) => ({
  match: null,
  strategy: 'network-first',
  cache: 'r1',
  shared: false,
  maxEntries: null,
  maxAge: null,
  timeout: null,
  destinations: [],
  ...overrides,
});

/** The default image/font rule ServiceWorkerBuilder appends. */
export const imagesRule = (overrides = {}) =>
  rule({ strategy: 'stale-while-revalidate', cache: 'images', maxEntries: 120, destinations: ['image', 'font'], ...overrides });

/** What `pwa.cache.api` patterns become. */
export const apiRule = (match) => rule({ match, cache: 'api' });

export const offline = () => {
  throw new TypeError('Failed to fetch');
};

/**
 * @param {object} options
 * @param {object} [options.config] value of self.__PWA (merged over defaults)
 * @param {(request: Request) => Response|Promise<Response>} [options.fetch] network
 * @param {object} [options.registration] extra registration members (sync, navigationPreload)
 * @param {string[]} [options.extensions] sw.extend sources, evaluated after the runtime
 * @param {MemoryCacheStorage} [options.caches] Cache Storage shared with another worker (same origin)
 * @param {object[]} [options.windows] window clients (default: one at the origin root)
 */
export const createWorker = ({ config = {}, fetch: network = offline, registration = {}, extensions = [], caches: sharedCaches, windows } = {}) => {
  const listeners = new Map();
  const messages = [];
  const requests = [];
  const notifications = [];
  const opened = [];
  const badges = [];
  const caches = sharedCaches ?? new MemoryCacheStorage();
  let handler = network;

  const client = { url: `${ORIGIN}/`, postMessage: (message) => messages.push(message) };
  const clientList = windows ?? [client];

  const self = {
    __PWA: {
      version: 'v1',
      precache: ['/offline'],
      offlineUrl: '/offline',
      queue: true,
      navigationTimeout: 50,
      neverCache: ['/login', '/pwa/*'],
      rules: [imagesRule()],
      buildPrefix: '/build/',
      telemetry: '/pwa/telemetry',
      sessionUrl: '/pwa/session',
      scope: '/',
      ...config,
    },
    location: new URL('/sw.js', ORIGIN),
    registration: {
      scope: `${ORIGIN}/`,
      navigationPreload: undefined,
      showNotification: async (title, options = {}) => { notifications.push({ title, options }); },
      ...registration,
    },
    navigator: {
      setAppBadge: async (count) => { badges.push(count); },
      clearAppBadge: async () => { badges.push(0); },
    },
    clients: {
      matchAll: async () => clientList,
      claim: async () => {},
      openWindow: async (url) => { opened.push(url); return null; },
    },
    skipWaiting: () => {},
    addEventListener: (type, fn) => {
      listeners.set(type, [...(listeners.get(type) ?? []), fn]);
    },
  };

  // BroadcastChannel reaches only workers on the same simulated origin (same Cache Storage),
  // and never keeps the test process alive.
  caches.channelScope ??= Math.random().toString(36).slice(2);
  class OriginChannel extends BroadcastChannel {
    constructor(name) {
      super(`${caches.channelScope}:${name}`);
      this.unref?.();
    }
  }

  const context = vm.createContext({
    self,
    BroadcastChannel: OriginChannel,
    caches,
    indexedDB: new IDBFactory(),
    IDBKeyRange,
    fetch: async (input, init) => {
      const request = input instanceof Request ? input : new Request(new URL(String(input), ORIGIN), init);
      requests.push(request);
      return handler(request, init);
    },
    Request: WorkerRequest,
    Response,
    Headers,
    URL,
    URLSearchParams,
    TextEncoder,
    TextDecoder,
    atob,
    Promise,
    setTimeout: (...args) => globalThis.setTimeout(...args),
    clearTimeout: (...args) => globalThis.clearTimeout(...args),
    console,
  });

  vm.runInContext(RUNTIME, context, { filename: 'runtime.js' });
  extensions.forEach((source, index) => vm.runInContext(source, context, { filename: `extend-${index}.js` }));

  /** Dispatches an ExtendableEvent / FetchEvent and resolves once every waitUntil settles. */
  const dispatch = async (type, init = {}) => {
    const lifetime = [];
    let response = null;
    const event = {
      type,
      ...init,
      waitUntil: (promise) => lifetime.push(Promise.resolve(promise)),
      respondWith: (promise) => {
        response = Promise.resolve(promise);
      },
    };

    const started = Date.now();
    for (const fn of listeners.get(type) ?? []) fn(event);

    const result = response ? await response : null;
    const respondedIn = Date.now() - started;
    // waitUntil may be registered while the response is produced; drain until stable.
    for (let settled = 0; settled < lifetime.length; settled = lifetime.length) {
      await Promise.allSettled(lifetime.slice(settled));
    }
    await new Promise((resolve) => setImmediate(resolve));

    // respondedIn: ms until respondWith settled (background waitUntil work excluded).
    return { event, response: result, handled: response !== null, respondedIn };
  };

  return {
    self,
    caches,
    messages,
    requests,
    notifications,
    opened,
    badges,
    dispatch,
    /** Replace the network for the following requests. */
    network: (fn) => {
      handler = fn;
    },
    fetchEvent: (path, init) => dispatch('fetch', { request: makeRequest(path, init), preloadResponse: undefined }),
    navigate: (path, init = {}) => dispatch('fetch', { request: makeRequest(path, { mode: 'navigate', ...init }), preloadResponse: undefined }),
    message: (data, ports = []) => dispatch('message', { data, ports }),
    /** A push event; `data` is the JSON payload (or a string). */
    push: (data) => dispatch('push', {
      data: data === undefined ? null : {
        json: () => (typeof data === 'string' ? JSON.parse(data) : data),
        text: () => (typeof data === 'string' ? data : JSON.stringify(data)),
      },
    }),
    /** A click on a notification shown before; `action` is the button. */
    click: (notification, action = '') => {
      const event = { notification: { ...notification.options, data: notification.options.data, close: () => { event.closed = true; } }, action };
      return dispatch('notificationclick', event).then((result) => ({ ...result, closed: event.closed === true }));
    },
    install: () => dispatch('install'),
    activate: () => dispatch('activate'),
  };
};

/** Response helper. */
export const html = (body, headers = {}, status = 200) =>
  new Response(body, { status, headers: { 'Content-Type': 'text/html', ...headers } });

export const json = (data, headers = {}, status = 200) =>
  new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json', ...headers } });
