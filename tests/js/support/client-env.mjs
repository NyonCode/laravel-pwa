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
  i18n: { offline: 'Offline', maintenance: 'Maintenance', update: 'Update', reload: 'Reload' },
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
 */
export const createClient = async ({ config = {}, fetch: network, displayMode = 'browser', serviceWorker = true, before } = {}) => {
  const window = new Window({ url: `${ORIGIN}/dashboard` });
  const { document } = window;

  document.head.innerHTML = `
    <meta name="csrf-token" content="old">
    <script type="application/json" id="pwa-config">${JSON.stringify(baseConfig(config))}</script>`;
  document.body.innerHTML = '<form><input name="_token" value="old"></form>';

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
    controller: { postMessage: (message) => sw.posted.push(message) },
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
      addEventListener() {},
    },
    get ready() {
      return Promise.resolve(sw.registration);
    },
    /** Simulates a message from the worker. */
    receive: (data) => (sw.listeners.get('message') ?? []).forEach((fn) => fn({ data })),
  };

  if (serviceWorker) Object.defineProperty(window.navigator, 'serviceWorker', { value: sw, configurable: true });

  const events = [];
  const record = (name) => document.addEventListener(name, (e) => events.push({ name, detail: e.detail }));
  for (const name of ['ready', 'csrf', 'offline', 'maintenance', 'logout', 'queue', 'replayed', 'resume', 'online', 'csrf-refreshed', 'installed', 'update-available', 'activated', 'push', 'push-received', 'notification-click', 'identity']) {
    record(`pwa:${name}`);
  }

  before?.(window);

  const globals = {
    window,
    document,
    navigator: window.navigator,
    location: window.location,
    localStorage: window.localStorage,
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
    flush,
    close: () => window.happyDOM.close(),
  };
};
