/*
 * nyoncode/laravel-pwa — klient (načítá <x-pwa::head/>).
 *
 * Registrace a aktualizace service workeru, instalace, keep-alive session
 * a CSRF, lišty offline/údržba a adaptéry pro Livewire a Inertia.
 * Veřejné API: window.Pwa.
 */
const configEl = document.getElementById('pwa-config');
const C = configEl ? JSON.parse(configEl.textContent || '{}') : {};

const listeners = new Map();
const emit = (name, detail = {}) => {
  document.dispatchEvent(new CustomEvent(`pwa:${name}`, { detail }));
  (listeners.get(name) || []).forEach((fn) => { try { fn(detail); } catch (e) { console.error(e); } });
};

const store = {
  get(key, fallback = null) {
    try { const v = localStorage.getItem(`pwa:${key}`); return v === null ? fallback : JSON.parse(v); } catch { return fallback; }
  },
  set(key, value) {
    try { localStorage.setItem(`pwa:${key}`, JSON.stringify(value)); } catch { /* soukromý režim */ }
  },
};

/* ---------------------------------------------------------------------- *
 * Režim zobrazení
 * ---------------------------------------------------------------------- */

const MODES = ['window-controls-overlay', 'tabbed', 'fullscreen', 'standalone', 'minimal-ui'];

const displayMode = () => {
  if (navigator.standalone === true) return 'standalone'; // iOS
  return MODES.find((m) => window.matchMedia(`(display-mode: ${m})`).matches) || 'browser';
};

const isInstalled = () => displayMode() !== 'browser';

const isIos = () =>
  /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.userAgent.includes('Macintosh') && navigator.maxTouchPoints > 1);

// Nápověda pro server (RememberStandalone, PwaWebMiddleware).
const rememberMode = () => {
  document.cookie = `pwa_display=${displayMode()}; path=/; max-age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
};

/* ---------------------------------------------------------------------- *
 * Lišta (offline, údržba, aktualizace, fronta)
 * ---------------------------------------------------------------------- */

let bar = null;

const showBar = (text, action = null, tone = 'neutral') => {
  if (!bar) {
    bar = document.createElement('div');
    bar.setAttribute('role', 'status');
    bar.setAttribute('aria-live', 'polite');
    bar.className = 'pwa-bar';
    Object.assign(bar.style, {
      position: 'fixed', left: '50%', bottom: 'max(16px, env(safe-area-inset-bottom))', transform: 'translateX(-50%)',
      zIndex: '2147483000', display: 'flex', gap: '12px', alignItems: 'center', maxWidth: 'calc(100vw - 32px)',
      padding: '10px 14px', borderRadius: '12px', font: '500 14px/1.4 system-ui, -apple-system, sans-serif',
      boxShadow: '0 8px 24px rgba(0,0,0,.18)', color: '#fff',
    });
    document.body.appendChild(bar);
  }
  bar.style.background = tone === 'warn' ? '#8a5a00' : '#1f2937';
  bar.replaceChildren(Object.assign(document.createElement('span'), { textContent: text }));
  if (action) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = action.label;
    Object.assign(btn.style, { font: 'inherit', fontWeight: '600', color: 'inherit', background: 'rgba(255,255,255,.16)', border: '0', borderRadius: '8px', padding: '6px 10px', cursor: 'pointer' });
    btn.addEventListener('click', action.run);
    bar.appendChild(btn);
  }
  bar.hidden = false;
};

const hideBar = () => { if (bar) bar.hidden = true; };

const t = (key, fallback) => (C.i18n && C.i18n[key]) || fallback;

/* ---------------------------------------------------------------------- *
 * Service worker
 * ---------------------------------------------------------------------- */

let registration = null;
let acceptedUpdate = false;

const unregisterAll = async () => {
  if (!('serviceWorker' in navigator)) return;
  const regs = await navigator.serviceWorker.getRegistrations();
  await Promise.all(regs.filter((r) => (r.active || r.waiting || r.installing || {}).scriptURL?.endsWith(C.sw)).map((r) => r.unregister()));
  if ('caches' in window) {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k.startsWith('pwa-')).map((k) => caches.delete(k)));
  }
};

const offerUpdate = (worker) => {
  emit('update-available', { worker });
  showBar(t('update', 'Je k dispozici nová verze.'), {
    label: t('reload', 'Načíst'),
    run: () => { acceptedUpdate = true; worker.postMessage({ type: 'SKIP_WAITING' }); },
  });
};

const registerServiceWorker = async () => {
  if (!('serviceWorker' in navigator)) return;

  if (!C.enabled) {
    await unregisterAll();
    return;
  }

  registration = await navigator.serviceWorker.register(C.sw, { scope: C.scope, updateViaCache: 'none' });

  if (registration.waiting && navigator.serviceWorker.controller) offerUpdate(registration.waiting);

  registration.addEventListener('updatefound', () => {
    const worker = registration.installing;
    worker?.addEventListener('statechange', () => {
      if (worker.state === 'installed' && navigator.serviceWorker.controller) offerUpdate(worker);
    });
  });

  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (acceptedUpdate) location.reload();
  });

  navigator.serviceWorker.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.source !== 'pwa') return;
    switch (data.type) {
      case 'maintenance':
        showBar(t('maintenance', 'Probíhá údržba. Zkuste to za chvíli.'), null, 'warn');
        emit('maintenance', data);
        break;
      case 'offline':
        showBar(t('offline', 'Jste offline. Změny se odešlou po připojení.'), null, 'warn');
        emit('offline', data);
        break;
      case 'logout':
        emit('logout', data);
        break;
      case 'queued':
      case 'queue':
        emit('queue', { count: data.count });
        break;
      case 'replayed':
        emit('replayed', data);
        break;
      default:
        emit(data.type, data);
    }
  });

  if (navigator.onLine) navigator.serviceWorker.controller?.postMessage({ type: 'REPLAY_QUEUE' });
};

/* ---------------------------------------------------------------------- *
 * Keep-alive session a CSRF
 * ---------------------------------------------------------------------- */

const applyCsrf = (token) => {
  if (!token) return;
  document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
  document.querySelectorAll('input[name="_token"]').forEach((input) => { input.value = token; });
  if (window.axios?.defaults?.headers?.common) window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
  if (window.livewireScriptConfig) window.livewireScriptConfig.csrf = token;
  emit('csrf', { token });
};

let lastKeepAlive = 0;

const keepAlive = async (force = false) => {
  if (!C.session || (!force && Date.now() - lastKeepAlive < 30_000)) return null;
  lastKeepAlive = Date.now();
  try {
    const res = await nativeFetch(C.session.url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-PWA-Display': displayMode() },
    });
    if (!res.ok) return null;
    const data = await res.json();
    if (C.session.refreshCsrf) applyCsrf(data.csrf);
    // The worker never sees /pwa/session: tell it who is signed in, so it purges another user's data.
    if (data.user) navigator.serviceWorker?.controller?.postMessage({ type: 'IDENTITY', user: data.user });
    return data;
  } catch {
    return null;
  }
};

const nativeFetch = window.fetch.bind(window);

const installFetchRetry = () => {
  if (!C.session?.refreshCsrf) return;
  window.fetch = async (input, init = {}) => {
    const response = await nativeFetch(input, init);
    const method = (init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
    const url = new URL(input instanceof Request ? input.url : String(input), location.href);
    if (response.status !== 419 || method === 'GET' || url.origin !== location.origin || init.__pwaRetried) return response;

    const data = await keepAlive(true);
    if (!data?.csrf) return response;

    const headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
    headers.set('X-CSRF-TOKEN', data.csrf);
    const body = init.body;
    if (body instanceof FormData && body.has('_token')) body.set('_token', data.csrf);
    return nativeFetch(input, { ...init, headers, body, __pwaRetried: true });
  };
};

// Cílová adresa požadavku axios (stejně jako buildFullPath v axios): token jen na vlastní origin.
const sameOriginAxios = (config) => {
  const url = String(config.url || '');
  const absolute = /^([a-z][a-z\d+\-.]*:)?\/\//i.test(url);
  const full = absolute || !config.baseURL ? url : `${String(config.baseURL).replace(/\/+$/, '')}/${url.replace(/^\/+/, '')}`;
  try {
    return new URL(full, location.href).origin === location.origin;
  } catch {
    return false;
  }
};

// axios z window (Laravel bootstrap.js): po 419 jednou zopakovat s novým tokenem.
const installAxiosRetry = () => {
  const axios = window.axios;
  if (!C.session?.refreshCsrf || !axios?.interceptors?.response) return;
  axios.interceptors.response.use(undefined, async (error) => {
    const config = error?.config;
    if (error?.response?.status !== 419 || !config || config.__pwaRetried || !sameOriginAxios(config)) throw error;
    const data = await keepAlive(true);
    if (!data?.csrf) throw error;
    config.__pwaRetried = true;
    config.headers = { ...(config.headers || {}), 'X-CSRF-TOKEN': data.csrf };
    return axios.request(config);
  });
};

const resume = () => {
  rememberMode();
  keepAlive();
  registration?.update().catch(() => {});
  if (navigator.onLine) navigator.serviceWorker?.controller?.postMessage({ type: 'REPLAY_QUEUE' });
  emit('resume', { displayMode: displayMode() });
  if (C.adapters?.reloadOnResume && window.Inertia?.reload) window.Inertia.reload();
};

/* ---------------------------------------------------------------------- *
 * Instalace
 * ---------------------------------------------------------------------- */

let deferredPrompt = null;

const installEligible = () => {
  if (isInstalled()) return false;
  const dismissed = store.get('install-dismissed', 0);
  if (dismissed && Date.now() - dismissed < (C.install?.dismissDays || 30) * 864e5) return false;
  return store.get('visits', 0) >= (C.install?.minVisits || 1);
};

const updateInstallUi = () => {
  const eligible = installEligible();
  const canPrompt = !!deferredPrompt || 'install' in navigator;
  document.querySelectorAll('[data-pwa-install]').forEach((el) => { el.hidden = !(eligible && canPrompt); });
  document.querySelectorAll('[data-pwa-ios-hint]').forEach((el) => {
    el.hidden = !(eligible && isIos() && C.install?.iosHint);
  });
};

const install = async () => {
  try {
    if ('install' in navigator && !deferredPrompt) {
      await navigator.install();
      return 'accepted';
    }
    if (!deferredPrompt) return 'unavailable';
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    deferredPrompt = null;
    if (outcome === 'dismissed') store.set('install-dismissed', Date.now());
    updateInstallUi();
    return outcome;
  } catch {
    return 'dismissed';
  }
};

window.addEventListener('beforeinstallprompt', (event) => {
  event.preventDefault();
  deferredPrompt = event;
  updateInstallUi();
});

window.addEventListener('appinstalled', () => {
  deferredPrompt = null;
  updateInstallUi();
  emit('installed', {});
});

document.addEventListener('click', (event) => {
  const trigger = event.target.closest?.('[data-pwa-install]');
  if (trigger) { event.preventDefault(); install(); }
  const dismiss = event.target.closest?.('[data-pwa-install-dismiss]');
  if (dismiss) { store.set('install-dismissed', Date.now()); updateInstallUi(); }
});

/* ---------------------------------------------------------------------- *
 * Adaptéry: Livewire 3 a Inertia
 * ---------------------------------------------------------------------- */

const livewireAdapter = () => {
  if (!C.adapters?.livewire) return;
  document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
      fail(async ({ status, preventDefault }) => {
        if (status === 503) { preventDefault(); location.reload(); }
        else if (status === 419) { preventDefault(); await keepAlive(true); emit('csrf-refreshed', {}); }
        else if (status === 0 || !navigator.onLine) { preventDefault(); showBar(t('offline', 'Jste offline.'), null, 'warn'); }
      });
    });
  });
};

const inertiaAdapter = () => {
  if (!C.adapters?.inertia) return;
  document.addEventListener('inertia:invalid', (event) => {
    const response = event.detail?.response;
    const status = response?.status;
    const headers = response?.headers || {};
    const queued = headers['x-pwa-queued'] || (typeof headers.get === 'function' && headers.get('x-pwa-queued'));
    if (queued) { event.preventDefault(); emit('queue', {}); showBar(t('offline', 'Jste offline. Změny se odešlou po připojení.'), null, 'warn'); }
    else if (status === 503) { event.preventDefault(); location.reload(); }
  });
  document.addEventListener('inertia:exception', (event) => {
    if (!navigator.onLine) { event.preventDefault(); showBar(t('offline', 'Jste offline.'), null, 'warn'); }
  });
};

/* ---------------------------------------------------------------------- *
 * Start
 * ---------------------------------------------------------------------- */

window.addEventListener('online', () => {
  hideBar();
  navigator.serviceWorker?.controller?.postMessage({ type: 'REPLAY_QUEUE' });
  emit('online', {});
});
window.addEventListener('offline', () => showBar(t('offline', 'Jste offline.'), null, 'warn'));

document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') resume(); });
window.addEventListener('pageshow', (event) => { if (event.persisted) resume(); });

const boot = async () => {
  store.set('visits', store.get('visits', 0) + 1);
  rememberMode();
  installFetchRetry();
  installAxiosRetry();
  livewireAdapter();
  inertiaAdapter();
  updateInstallUi();

  try { await registerServiceWorker(); } catch (e) { console.warn('[pwa]', e); }

  if (C.session?.keepalive > 0) {
    setInterval(() => { if (document.visibilityState === 'visible') keepAlive(true); }, C.session.keepalive * 60_000);
  }
};

window.Pwa = {
  config: C,
  displayMode,
  isInstalled,
  isIos,
  install,
  update: () => registration?.update(),
  keepAlive: () => keepAlive(true),
  on(name, fn) { listeners.set(name, [...(listeners.get(name) || []), fn]); return () => listeners.set(name, (listeners.get(name) || []).filter((f) => f !== fn)); },
  badge: {
    set: (count) => navigator.setAppBadge?.(count).catch(() => {}),
    clear: () => navigator.clearAppBadge?.().catch(() => {}),
  },
  version: () => new Promise((resolve) => {
    const ctrl = navigator.serviceWorker?.controller;
    if (!ctrl) { resolve(null); return; }
    const channel = new MessageChannel();
    channel.port1.onmessage = (e) => resolve(e.data?.version ?? null);
    ctrl.postMessage({ type: 'GET_VERSION' }, [channel.port2]);
  }),
};

emit('ready', { displayMode: displayMode() });
boot();
