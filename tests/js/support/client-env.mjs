// Browser environment for unit-testing resources/js/pwa.js in happy-dom.
//
// Each call builds an isolated window with the #pwa-config element, a scripted
// window.fetch and a fake navigator.serviceWorker, then runs the client in it.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { Window } from 'happy-dom';

const ROOT = fileURLToPath(new URL('../../../', import.meta.url));
const CLIENT = readFileSync(`${ROOT}resources/js/pwa.js`, 'utf8');

export const ORIGIN = 'https://app.test';

export const baseConfig = (overrides = {}) => ({
  enabled: true,
  killed: false,
  sw: '/sw.js',
  scope: '/',
  startUrl: '/?source=pwa',
  session: { url: '/pwa/session', keepalive: 0, refreshCsrf: true },
  ping: '/pwa/ping',
  telemetry: '/pwa/telemetry',
  install: { minVisits: 1, dismissDays: 30, iosHint: true },
  nav: { enabled: true, minWidth: 900 },
  adapters: { livewire: true, inertia: true, reloadOnResume: false },
  offlineUrl: '/offline',
  i18n: { offline: 'Offline', notSent: 'Not sent', maintenance: 'Maintenance', update: 'Update', reload: 'Reload' },
  ...overrides,
});

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

/**
 * @param {object} options
 * @param {object} [options.config] client config (merged over baseConfig)
 * @param {(url: URL, init: object) => Response|Promise<Response>} [options.fetch]
 * @param {string} [options.displayMode] value matched by (display-mode: …)
 * @param {boolean} [options.serviceWorker] expose navigator.serviceWorker
 * @param {(window: Window) => void} [options.before] runs before the client (e.g. set window.axios)
 * @param {string} [options.userAgent] navigator.userAgent (e.g. an iPhone)
 * @param {string} [options.body] HTML of <body> (components rendered by the server)
 */
export const createClient = async ({ config = {}, fetch: network, displayMode = 'browser', serviceWorker = true, before, userAgent, body = '' } = {}) => {
  const window = new Window({ url: `${ORIGIN}/dashboard`, ...(userAgent ? { settings: { navigator: { userAgent } } } : {}) });
  const { document } = window;

  document.head.innerHTML = `
    <meta name="csrf-token" content="old">
    <script type="application/json" id="pwa-config">${JSON.stringify(baseConfig(config))}</script>`;
  document.body.innerHTML = `<form><input name="_token" value="old"></form>${body}`;

  const calls = [];
  const fetchImpl = async (input, init = {}) => {
    const url = new URL(input instanceof window.Request ? input.url : String(input), window.location.href);
    calls.push({ url, init, input });
    return network ? network(url, init, input) : new window.Response('{}', { status: 200 });
  };
  window.fetch = fetchImpl;

  window.matchMedia = (query) => ({
    matches: query === `(display-mode: ${displayMode})`,
    media: query,
    addEventListener() {},
    removeEventListener() {},
  });

  const sw = {
    posted: [],
    registered: [],
    unregistered: 0,
    listeners: new Map(),
    // Requests with a reply port (OUTBOX, RETRY…) are answered by sw.answer(message), when set.
    answer: null,
    controller: {
      postMessage: (message, ports = []) => {
        sw.posted.push(message);
        if (sw.answer && ports[0]) Promise.resolve(sw.answer(message)).then((reply) => ports[0].postMessage(reply));
      },
    },
    register: async (url, options) => {
      sw.registered.push({ url, options });
      return sw.registration;
    },
    unregisteredUrls: [],
    getRegistrations: async () =>
      [`${ORIGIN}/sw.js`, `${ORIGIN}/other-app/worker.js`].map((scriptURL) => ({
        active: { scriptURL },
        unregister: async () => {
          sw.unregistered += 1;
          sw.unregisteredUrls.push(scriptURL);
          return true;
        },
      })),
    addEventListener: (type, fn) => sw.listeners.set(type, [...(sw.listeners.get(type) ?? []), fn]),
    registration: {
      waiting: null,
      installing: null,
      update: async () => {},
      listeners: new Map(),
      addEventListener(type, fn) { this.listeners.set(type, [...(this.listeners.get(type) ?? []), fn]); },
    },
    get ready() {
      return Promise.resolve(sw.registration);
    },
    /** Simulates a message from the worker. */
    receive: (data) => (sw.listeners.get('message') ?? []).forEach((fn) => fn({ data })),
    /** Simulates a new worker that installed and now waits (updatefound → statechange 'installed'). */
    installUpdate: () => {
      const worker = fakeWorker('installing');
      sw.registration.installing = worker;
      (sw.registration.listeners.get('updatefound') ?? []).forEach((fn) => fn());
      worker.state = 'installed';
      sw.registration.installing = null;
      sw.registration.waiting = worker;
      worker.fire('statechange');
      return worker;
    },
    /** Simulates the waiting worker taking control (after SKIP_WAITING). */
    takeControl: () => (sw.listeners.get('controllerchange') ?? []).forEach((fn) => fn()),
  };

  function fakeWorker(state) {
    const handlers = new Map();
    return {
      state,
      posted: [],
      postMessage(message) { this.posted.push(message); },
      addEventListener: (type, fn) => handlers.set(type, [...(handlers.get(type) ?? []), fn]),
      fire: (type) => (handlers.get(type) ?? []).forEach((fn) => fn()),
    };
  }
  sw.fakeWorker = fakeWorker;

  if (serviceWorker) Object.defineProperty(window.navigator, 'serviceWorker', { value: sw, configurable: true });

  const events = [];
  const record = (name) => document.addEventListener(name, (e) => events.push({ name, detail: e.detail }));
  for (const name of ['ready', 'csrf', 'offline', 'maintenance', 'logout', 'queue', 'replayed', 'resume', 'online', 'csrf-refreshed', 'installed', 'update-available', 'activated', 'push', 'push-received', 'notification-click', 'identity', 'installable', 'warmed', 'push-renewed', 'replay-failed', 'queue-full']) {
    record(`pwa:${name}`);
  }

  before?.(window);

  // location.reload() is counted instead of reloading happy-dom.
  const reloads = { count: 0 };
  const location = new Proxy(window.location, {
    get: (target, key) => (key === 'reload' ? () => { reloads.count += 1; } : Reflect.get(target, key)),
  });

  const globals = {
    window,
    document,
    navigator: window.navigator,
    location,
    localStorage: window.localStorage,
    sessionStorage: window.sessionStorage,
    CustomEvent: window.CustomEvent,
    MessageChannel: window.MessageChannel ?? globalThis.MessageChannel,
    Request: window.Request,
    Headers: window.Headers,
    FormData: window.FormData,
    URL,
    console,
    setInterval: (...args) => window.setInterval(...args),
    setTimeout: (...args) => window.setTimeout(...args),
    caches: undefined,
  };

  // The client is an ES module: strict mode, no globals leaking into Node.
  const run = new Function(...Object.keys(globals), `'use strict';\n${CLIENT}`);
  run(...Object.values(globals));
  await flush();

  return {
    window,
    document,
    Pwa: window.Pwa,
    sw,
    calls,
    events,
    reloads,
    flush,
    close: () => window.happyDOM.close(),
  };
};
