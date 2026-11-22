/*
 * nyoncode/laravel-pwa — browser client (loaded by <x-pwa::head/>).
 *
 * Service worker registration and updates, installation, session and CSRF
 * keep-alive, offline/maintenance bars, web push subscriptions and adapters for
 * Livewire and Inertia.
 * Public API: window.Pwa.
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
    try { localStorage.setItem(`pwa:${key}`, JSON.stringify(value)); } catch { /* private mode */ }
  },
};

// Per tab: "Later" on the update toast holds until the tab closes (or a newer version installs).
const tabStore = {
  get: (key) => { try { return sessionStorage.getItem(`pwa:${key}`); } catch { return null; } },
  set: (key, value) => {
    try { if (value === null) sessionStorage.removeItem(`pwa:${key}`); else sessionStorage.setItem(`pwa:${key}`, value); } catch { /* private mode */ }
  },
};

/* ---------------------------------------------------------------------- *
 * Display mode
 * ---------------------------------------------------------------------- */

const MODES = ['window-controls-overlay', 'tabbed', 'fullscreen', 'standalone', 'minimal-ui'];

const displayMode = () => {
  if (navigator.standalone === true) return 'standalone'; // iOS
  return MODES.find((m) => window.matchMedia(`(display-mode: ${m})`).matches) || 'browser';
};

const isInstalled = () => displayMode() !== 'browser';

const isIos = () =>
  /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.userAgent.includes('Macintosh') && navigator.maxTouchPoints > 1);

// A hint for the server (RememberStandalone, PwaWebMiddleware).
const rememberMode = () => {
  document.cookie = `pwa_display=${displayMode()}; path=/; max-age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
};

/* ---------------------------------------------------------------------- *
 * Status bar (offline, maintenance, update, queue)
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
let waitingWorker = null;
let updateDismissed = false;

const unregisterAll = async () => {
  if (!('serviceWorker' in navigator)) return;
  const regs = await navigator.serviceWorker.getRegistrations();
  await Promise.all(regs.filter((r) => (r.active || r.waiting || r.installing || {}).scriptURL?.endsWith(C.sw)).map((r) => r.unregister()));
  if ('caches' in window) {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k.startsWith('pwa-')).map((k) => caches.delete(k)));
  }
};

/** Activates the waiting worker; the page reloads once it controls the page. */
const applyUpdate = () => {
  const worker = waitingWorker || registration?.waiting;
  if (!worker) return false;
  acceptedUpdate = true;
  tabStore.set('update-dismissed', null);
  worker.postMessage({ type: 'SKIP_WAITING' });
  return true;
};

// Pages with their own update UI (<x-pwa::update-toast/>, [data-pwa-update]) or updates.bar = false
// do not get the built-in bar.
const ownUpdateUi = () => C.updates?.bar === false || !!document.querySelector('[data-pwa-update]');

const updateUpdateUi = () => {
  const visible = !!waitingWorker && !updateDismissed;
  document.querySelectorAll('[data-pwa-update]').forEach((el) => { el.hidden = !visible; });
};

// fresh: the worker installed while this page was open (a newer version than one dismissed earlier).
const offerUpdate = (worker, fresh = false) => {
  waitingWorker = worker;
  if (fresh) tabStore.set('update-dismissed', null);
  updateDismissed = tabStore.get('update-dismissed') !== null;
  emit('update-available', { worker });
  updateUpdateUi();
  if (ownUpdateUi() || updateDismissed) return;
  showBar(t('update', 'A new version is available.'), { label: t('reload', 'Reload'), run: applyUpdate });
};

const registerServiceWorker = async () => {
  if (!('serviceWorker' in navigator)) return;

  if (!C.enabled) {
    await unregisterAll();
    return;
  }

  registration = await navigator.serviceWorker.register(C.sw, { scope: C.scope, updateViaCache: 'none' });

  if (registration.waiting && navigator.serviceWorker.controller) offerUpdate(registration.waiting);
  else tabStore.set('update-dismissed', null);

  registration.addEventListener('updatefound', () => {
    const worker = registration.installing;
    worker?.addEventListener('statechange', () => {
      if (worker.state === 'installed' && navigator.serviceWorker.controller) offerUpdate(worker, true);
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
        showBar(t('maintenance', 'Maintenance in progress. Please try again shortly.'), null, 'warn');
        emit('maintenance', data);
        break;
      case 'offline':
        showBar(t('offline', 'You are offline. Changes will be sent once connected.'), null, 'warn');
        emit('offline', data);
        break;
      case 'logout':
        if (C.push) dropSubscription().catch(() => {});
        emit('logout', data);
        break;
      case 'queued':
      case 'queue':
        emit('queue', { count: data.count });
        break;
      case 'replayed':
        emit('replayed', data);
        break;
      case 'identity':
        // Another user signed in without a navigation (Inertia, Livewire): fetch their warm-up list.
        emit('identity', data);
        if (C.push && store.get('push')?.user && store.get('push').user !== data.user) dropSubscription().catch(() => {});
        if (C.warm?.enabled && data.user !== 'guest') keepAlive(true);
        break;
      default:
        emit(data.type, data);
    }
  });

  if (navigator.onLine) navigator.serviceWorker.controller?.postMessage({ type: 'REPLAY_QUEUE' });
};

/* ---------------------------------------------------------------------- *
 * Session keep-alive, CSRF and offline warm-up
 * ---------------------------------------------------------------------- */

const applyCsrf = (token) => {
  if (!token) return;
  document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
  document.querySelectorAll('input[name="_token"]').forEach((input) => { input.value = token; });
  document.querySelectorAll('[data-csrf]').forEach((el) => el.setAttribute('data-csrf', token)); // Livewire script tag
  if (window.axios?.defaults?.headers?.common) window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
  if (window.livewireScriptConfig) window.livewireScriptConfig.csrf = token;
  emit('csrf', { token });
};

let lastKeepAlive = 0;

const keepAlive = async (force = false, autoWarm = true) => {
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
    if (autoWarm) warmUp(data);
    if (C.push) syncPush(data).catch(() => {});
    return data;
  } catch {
    return null;
  }
};

const WARM_TAG = 'pwa-warm';

const warmDue = (user) => {
  const last = store.get('warm', {});
  return last.user !== user || Date.now() - (last.at || 0) >= (C.warm?.interval ?? 60) * 60_000;
};

/** Sends the list from /pwa/session to the worker: on sign-in, then at most once per interval. */
const warmUp = (data, force = false) => {
  const urls = data?.warm;
  const worker = navigator.serviceWorker?.controller;
  if (!worker || !Array.isArray(urls) || urls.length === 0) return false;
  if (!navigator.onLine || navigator.connection?.saveData) return false;
  if (!force && !warmDue(data.user)) return false;
  store.set('warm', { user: data.user, at: Date.now() });
  worker.postMessage({ type: 'WARM', urls, user: data.user, force });
  return true;
};

const warm = async () => {
  if (!navigator.serviceWorker?.controller) return null;
  const done = new Promise((resolve) => {
    const off = window.Pwa.on('warmed', (detail) => { off(); resolve(detail); });
    setTimeout(() => { off(); resolve(null); }, 60_000);
  });
  lastKeepAlive = 0;
  const data = await keepAlive(true, false);
  return data && warmUp(data, true) ? done : null;
};

// Installed Chromium apps refresh the warm-up pages in the background.
const registerPeriodicWarm = async () => {
  if (!C.warm?.enabled || !isInstalled() || !registration?.periodicSync) return;
  try {
    const { state } = await navigator.permissions.query({ name: 'periodic-background-sync' });
    if (state === 'granted') await registration.periodicSync.register(WARM_TAG, { minInterval: (C.warm.interval || 60) * 60_000 });
  } catch { /* unsupported */ }
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

// The axios request URL (like axios' buildFullPath): the token only goes to our own origin.
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

// axios from window (Laravel bootstrap.js): after a 419, retry once with a fresh token.
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
 * Web push (pwa.push.enabled)
 * ---------------------------------------------------------------------- */

// localStorage pwa:push = { endpoint, user, at }: who subscribed this browser and when it was last
// confirmed with the server. Another user on the device never inherits the subscription.
const PUSH_SYNC = 24 * 60 * 60_000;

const pushSupported = () => !!C.push?.key && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

const pushPermission = () => (window.Notification ? window.Notification.permission : 'unsupported');

const keyBytes = (base64url) => {
  const raw = window.atob(base64url.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(base64url.length / 4) * 4, '='));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
};

const sameKey = (buffer, bytes) => {
  if (!buffer) return true;
  const a = new Uint8Array(buffer);
  return a.length === bytes.length && a.every((v, i) => v === bytes[i]);
};

const pushRegistration = async () => registration || (await navigator.serviceWorker.ready);

// The browser refused to subscribe although it has the push API: a private window in Chrome, no push
// service (some Chromium builds), a policy. Nothing can be detected up front, so remember it for this page.
let pushUnavailable = false;

const currentSubscription = async () => {
  if (!pushSupported()) return null;
  try {
    return (await (await pushRegistration()).pushManager.getSubscription()) || null;
  } catch {
    return null;
  }
};

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

/** POST/DELETE to the subscriptions route, retried once with a fresh token after 419. */
const pushRequest = async (method, body) => {
  const send = (token) => nativeFetch(C.push.url, {
    method,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
    body: JSON.stringify(body),
  });
  let res = await send(csrfToken());
  if (res.status === 419) {
    const data = await keepAlive(true, false);
    if (data?.csrf) res = await send(data.csrf);
  }
  return res;
};

const encoding = () => ((window.PushManager?.supportedContentEncodings || []).includes('aes128gcm') ? 'aes128gcm' : 'aesgcm');

const saveSubscription = async (subscription) => {
  const res = await pushRequest('POST', { ...subscription.toJSON(), contentEncoding: encoding() });
  if (!res.ok) return false;
  const data = await res.json().catch(() => ({}));
  store.set('push', { endpoint: subscription.endpoint, user: data.user ?? null, at: Date.now() });
  return true;
};

const pushState = async () => {
  if (!pushSupported()) return C.push && isIos() && !isInstalled() ? 'install-required' : 'unsupported';
  if (pushPermission() === 'denied') return 'denied';
  if (pushUnavailable) return 'unavailable';
  // Only a device that subscribed through this app can be subscribed: skip the push API otherwise.
  return store.get('push') && (await currentSubscription()) ? 'subscribed' : 'unsubscribed';
};

/** Asks for permission and subscribes. Call it from a click: browsers refuse the prompt otherwise. */
const subscribe = async () => {
  if (!pushSupported()) return null;
  const permission = await window.Notification.requestPermission();
  if (permission !== 'granted') {
    emit('push', { state: permission === 'denied' ? 'denied' : 'unsubscribed' });
    return null;
  }

  const reg = await pushRegistration();
  const key = keyBytes(C.push.key);
  let subscription = await reg.pushManager.getSubscription();
  // Made with an older VAPID key (or by another push system): it cannot receive our messages.
  if (subscription && !sameKey(subscription.options?.applicationServerKey, key)) {
    await subscription.unsubscribe();
    subscription = null;
  }
  if (!subscription) {
    try {
      subscription = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    } catch (error) {
      pushUnavailable = true;
      emit('push', { state: 'unavailable', error: error?.name || 'Error' });
      return null;
    }
  }

  if (!(await saveSubscription(subscription))) {
    await subscription.unsubscribe().catch(() => {});
    emit('push', { state: 'unsubscribed', error: true });
    return null;
  }
  emit('push', { state: 'subscribed' });
  return subscription;
};

const unsubscribe = async () => {
  const subscription = await currentSubscription();
  store.set('push', null);
  if (!subscription) return false;
  await pushRequest('DELETE', { endpoint: subscription.endpoint }).catch(() => null);
  await subscription.unsubscribe().catch(() => {});
  emit('push', { state: 'unsubscribed' });
  return true;
};

/** Drops the browser's subscription without asking the server (logout already removed it there). */
const dropSubscription = async () => {
  const saved = store.get('push');
  if (!saved) return;
  store.set('push', null);
  const subscription = await currentSubscription();
  if (subscription?.endpoint === saved.endpoint) await subscription.unsubscribe().catch(() => {});
  emit('push', { state: 'unsubscribed' });
};

/**
 * After keep-alive: confirms this browser's subscription with the server once a day, renews it
 * after a VAPID key change and notices when the browser dropped it. Never subscribes a user who
 * did not subscribe on this device.
 */
const syncPush = async (session) => {
  const saved = store.get('push');
  if (!saved || !pushSupported() || !session?.authenticated) return;
  if (saved.user && session.user && saved.user !== session.user) { await dropSubscription(); return; }

  let subscription = await currentSubscription();
  if (!subscription) {
    store.set('push', null);
    emit('push', { state: 'unsubscribed', lost: true });
    return;
  }

  const key = keyBytes(C.push.key);
  const rotated = !sameKey(subscription.options?.applicationServerKey, key);
  if (rotated && pushPermission() === 'granted') {
    await subscription.unsubscribe();
    subscription = await (await pushRegistration()).pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
  }
  if (rotated || subscription.endpoint !== saved.endpoint || Date.now() - (saved.at || 0) >= PUSH_SYNC) {
    await saveSubscription(subscription);
  }
};

// [data-pwa-push] (<x-pwa::push-toggle/>): data-pwa-push-state carries the state, the matching
// [data-pwa-push-label] is shown, the toggle is disabled when the browser cannot subscribe.
const updatePushUi = async () => {
  const elements = document.querySelectorAll('[data-pwa-push]');
  if (elements.length === 0) return;
  const state = C.push ? await pushState() : 'unsupported';
  elements.forEach((el) => {
    el.hidden = state === 'unsupported';
    el.setAttribute('data-pwa-push-state', state);
    el.querySelectorAll('[data-pwa-push-label]').forEach((label) => {
      label.hidden = label.getAttribute('data-pwa-push-label') !== state;
    });
    el.querySelectorAll('[data-pwa-push-toggle]').forEach((button) => {
      button.disabled = state === 'denied' || state === 'install-required' || state === 'unavailable';
      button.setAttribute('aria-pressed', String(state === 'subscribed'));
    });
  });
};

const togglePush = async () => ((await pushState()) === 'subscribed' ? unsubscribe() : subscribe());

// One toggle at a time: a second click during the permission prompt or the request would start a
// parallel subscribe (two subscriptions, one of them orphaned).
let pushBusy = false;
const togglePushOnce = async (button) => {
  if (pushBusy) return;
  pushBusy = true;
  button.setAttribute('aria-busy', 'true');
  try { await togglePush(); } catch { /* state below shows the result */ } finally {
    pushBusy = false;
    button.removeAttribute('aria-busy');
    await updatePushUi();
  }
};

/* ---------------------------------------------------------------------- *
 * Installation
 * ---------------------------------------------------------------------- */

let deferredPrompt = null;

const installEligible = () => {
  if (isInstalled()) return false;
  const dismissed = store.get('install-dismissed', 0);
  if (dismissed && Date.now() - dismissed < (C.install?.dismissDays || 30) * 864e5) return false;
  return store.get('visits', 0) >= (C.install?.minVisits || 1);
};

const canPrompt = () => !!deferredPrompt || 'install' in navigator;

const iosHintVisible = () => installEligible() && isIos() && !!C.install?.iosHint;

/** Can this browser install the app right now: through a prompt, or (iPhone, iPad) through the Share menu? */
const canInstall = () => installEligible() && (canPrompt() || (isIos() && !!C.install?.iosHint));

let lastInstallable = null;

const updateInstallUi = () => {
  const prompt = installEligible() && canPrompt();
  const ios = iosHintVisible();
  document.querySelectorAll('[data-pwa-install]').forEach((el) => { el.hidden = !prompt; });
  document.querySelectorAll('[data-pwa-ios-hint]').forEach((el) => { el.hidden = !ios; });
  const state = `${prompt}:${ios}`;
  if (state !== lastInstallable) {
    lastInstallable = state;
    if (prompt || ios) emit('installable', { prompt, ios });
  }
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
  const on = (selector) => event.target.closest?.(selector);
  if (on('[data-pwa-install]')) { event.preventDefault(); install(); }
  if (on('[data-pwa-install-dismiss]')) { store.set('install-dismissed', Date.now()); updateInstallUi(); }
  if (on('[data-pwa-update-apply]')) { event.preventDefault(); applyUpdate(); }
  if (on('[data-pwa-update-dismiss]')) { updateDismissed = true; tabStore.set('update-dismissed', '1'); updateUpdateUi(); }
  const toggle = on('[data-pwa-push-toggle]');
  if (toggle && !toggle.disabled) { event.preventDefault(); togglePushOnce(toggle); }
});

/** Re-reads the [data-pwa-*] elements: after Livewire/Inertia navigation or a framework render. */
const refreshUi = () => {
  updateInstallUi();
  updateUpdateUi();
  updatePushUi().catch(() => {});
};

document.addEventListener('livewire:navigated', refreshUi);
document.addEventListener('inertia:navigate', () => setTimeout(refreshUi, 0));

/* ---------------------------------------------------------------------- *
 * Adapters: Livewire 3 and Inertia
 * ---------------------------------------------------------------------- */

const livewireAdapter = () => {
  if (!C.adapters?.livewire) return;
  // Livewire updates are never queued (a replayed snapshot would be stale): say the change was lost.
  const offline = () => showBar(t('notSent', 'You are offline. The change was not sent, try again once connected.'), null, 'warn');
  const failed = async (status, preventDefault) => {
    if (status === 503) { preventDefault(); location.reload(); }
    else if (status === 419) { preventDefault(); await keepAlive(true); emit('csrf-refreshed', {}); }
    else if (status === 0 || !navigator.onLine) { preventDefault(); offline(); }
  };
  document.addEventListener('livewire:init', () => {
    const { Livewire } = window;
    if (typeof Livewire.interceptRequest === 'function') {
      // Livewire 4: server errors in onError, network errors in onFailure.
      Livewire.interceptRequest(({ onError, onFailure }) => {
        onError(({ response, preventDefault }) => failed(response?.status ?? 0, preventDefault));
        onFailure(() => offline());
      });
      return;
    }
    // Livewire 3
    Livewire.hook('request', ({ fail }) => {
      fail(({ status, preventDefault }) => failed(status, preventDefault));
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
    if (queued) { event.preventDefault(); emit('queue', {}); showBar(t('offline', 'You are offline. Changes will be sent once connected.'), null, 'warn'); }
    else if (status === 503) { event.preventDefault(); location.reload(); }
  });
  document.addEventListener('inertia:exception', (event) => {
    if (!navigator.onLine) { event.preventDefault(); showBar(t('offline', 'You are offline. Changes will be sent once connected.'), null, 'warn'); }
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
window.addEventListener('offline', () => showBar(t('offline', 'You are offline. Changes will be sent once connected.'), null, 'warn'));

document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') resume(); });
window.addEventListener('pageshow', (event) => { refreshUi(); if (event.persisted) resume(); });

const boot = async () => {
  store.set('visits', store.get('visits', 0) + 1);
  rememberMode();
  installFetchRetry();
  installAxiosRetry();
  livewireAdapter();
  inertiaAdapter();
  updateInstallUi();
  if (C.push) listeners.set('push', [...(listeners.get('push') || []), () => { updatePushUi().catch(() => {}); }]);

  try { await registerServiceWorker(); } catch (e) { console.warn('[pwa]', e); }
  updatePushUi().catch(() => {});

  if (C.warm?.enabled && navigator.serviceWorker?.controller && warmDue(store.get('warm', {}).user)) keepAlive(true);
  else if (C.push && Date.now() - (store.get('push')?.at || Infinity) >= PUSH_SYNC) keepAlive(true);
  registerPeriodicWarm();

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
  canInstall,
  update: () => registration?.update(),
  updateAvailable: () => !!waitingWorker,
  applyUpdate,
  refreshUi,
  keepAlive: () => keepAlive(true),
  warm,
  on(name, fn) { listeners.set(name, [...(listeners.get(name) || []), fn]); return () => listeners.set(name, (listeners.get(name) || []).filter((f) => f !== fn)); },
  push: {
    supported: pushSupported,
    permission: pushPermission,
    state: pushState,
    subscribe,
    unsubscribe,
    subscription: currentSubscription,
    toggle: togglePush,
  },
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
