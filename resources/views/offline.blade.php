@php
    $theme = config('pwa.theme', []);
    $accent = $theme['light'] ?? '#0d6efd';
    $accentDark = $theme['accent_dark'] ?? '#5b9dff';
    $appName = config('pwa.short_name') ?: config('pwa.name', config('app.name'));
    $home = config('pwa.start_url', '/');
    // CSP: nonce z Vite, pokud ho aplikace nastavuje (Vite::useCspNonce()).
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#f6f7f9" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0e1116" media="(prefers-color-scheme: dark)">
<title>{{ __('pwa::messages.offline_title') }} · {{ $appName }}</title>
{{-- Everything inline: the page is shown without a network, no external resources. --}}
<style @if($nonce) nonce="{{ $nonce }}" @endif>
:root{
  --accent: {{ $accent }};
  --bg:#f6f7f9; --surface:#ffffff; --ink:#14171c; --muted:#5d6673; --line:#e3e6ea;
  --ok:#1f9d55; --warn:#c77700; --on-accent:#ffffff; --radius:20px;
  color-scheme: light dark;
}
@media (prefers-color-scheme: dark){
  :root{ --accent: {{ $accentDark }}; --bg:#0e1116; --surface:#161a21; --ink:#eef1f5; --muted:#9aa4b2; --line:#262c36; --ok:#3ccf7d; --warn:#f0a63a; --on-accent:#0b1220; }
}
*{box-sizing:border-box}
html{height:100%}
body{
  margin:0; min-height:100%; background:var(--bg); color:var(--ink);
  font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  -webkit-font-smoothing:antialiased; -webkit-tap-highlight-color:transparent;
  display:flex; align-items:center; justify-content:center;
  padding: max(24px, env(safe-area-inset-top)) max(20px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(20px, env(safe-area-inset-left));
}
.wrap{width:100%; max-width:520px}
.card{
  background:var(--surface); border:1px solid var(--line); border-radius:var(--radius);
  padding:32px 28px 28px; box-shadow:0 1px 2px rgba(0,0,0,.04), 0 12px 32px -12px rgba(0,0,0,.12);
}
.art{display:flex; justify-content:center; margin-bottom:20px}
.art svg{width:112px; height:112px}
.art .wave{fill:none; stroke:var(--line); stroke-width:6; stroke-linecap:round}
.art .wave.on{stroke:var(--accent)}
.art .dot{fill:var(--accent)}
.art .slash{stroke:var(--muted); stroke-width:6; stroke-linecap:round; transition:opacity .3s}
.pulse .wave.w1{animation:pulse 2.4s ease-in-out infinite}
.pulse .wave.w2{animation:pulse 2.4s ease-in-out .3s infinite}
.pulse .wave.w3{animation:pulse 2.4s ease-in-out .6s infinite}
@keyframes pulse{0%,100%{stroke:var(--line)}50%{stroke:var(--accent)}}
h1{font-size:clamp(22px,5vw,26px); line-height:1.25; margin:0 0 8px; text-align:center; letter-spacing:-.01em}
.lead{color:var(--muted); text-align:center; margin:0 auto 24px; max-width:40ch}
.status{
  display:flex; align-items:center; gap:10px; justify-content:center;
  font-size:14px; color:var(--muted); margin:0 0 20px; min-height:22px;
}
.badge{width:10px; height:10px; border-radius:50%; background:var(--warn); flex:none; box-shadow:0 0 0 4px color-mix(in srgb, var(--warn) 18%, transparent)}
.online .badge{background:var(--ok); box-shadow:0 0 0 4px color-mix(in srgb, var(--ok) 18%, transparent)}
.actions{display:flex; gap:12px; flex-wrap:wrap}
.btn{
  flex:1 1 180px; min-height:48px; border-radius:14px; border:1px solid transparent;
  font-family:inherit; font-size:15px; font-weight:600; line-height:1; cursor:pointer; padding:0 18px;
  display:inline-flex; align-items:center; justify-content:center; gap:8px; text-decoration:none;
  transition:transform .12s ease, background-color .2s ease, opacity .2s;
}
.btn:active{transform:scale(.98)}
.btn:focus-visible{outline:3px solid color-mix(in srgb, var(--accent) 45%, transparent); outline-offset:2px}
.btn-primary{background:var(--accent); color:var(--on-accent)}
.btn-primary[disabled]{opacity:.6; cursor:progress}
.btn-ghost{background:transparent; color:var(--ink); border-color:var(--line)}
.spin{width:16px; height:16px; border-radius:50%; border:2px solid rgba(255,255,255,.4); border-top-color:currentColor; animation:spin .8s linear infinite; display:none}
.busy .spin{display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
.section{margin-top:28px; padding-top:20px; border-top:1px solid var(--line)}
.section h2{font-size:13px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin:0 0 12px; font-weight:600}
.pages{list-style:none; margin:0; padding:0; display:grid; gap:8px}
.pages a{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  padding:12px 14px; border-radius:12px; background:var(--bg); color:var(--ink); text-decoration:none; font-size:15px;
}
.pages a:hover{background:color-mix(in srgb, var(--accent) 8%, var(--bg))}
.pages span{overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
.pages small{color:var(--muted); flex:none}
.queue{display:flex; gap:12px; align-items:flex-start; padding:12px 14px; border-radius:12px; background:color-mix(in srgb, var(--warn) 10%, var(--bg)); font-size:14px}
.queue strong{display:block}
.queue-icon{flex:none; margin-top:2px}
.hint{font-size:13px; color:var(--muted); text-align:center; margin:20px 0 0}
[hidden]{display:none !important}
.sr{position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap}
@media (prefers-reduced-motion: reduce){ *{animation:none !important; transition:none !important} }
@media (max-width:380px){ .card{padding:24px 18px 20px} }
@media (min-width:900px) and (display-mode: minimal-ui), (min-width:900px) and (display-mode: browser){ .card{padding:40px 40px 32px} }
</style>
</head>
<body>
<main class="wrap">
  <div class="card" id="card">
    <div class="art pulse" id="art" aria-hidden="true">
      <svg viewBox="0 0 120 120">
        <path class="wave w3" d="M18 50a60 60 0 0 1 84 0"/>
        <path class="wave w2" d="M32 64a40 40 0 0 1 56 0"/>
        <path class="wave w1" d="M46 78a20 20 0 0 1 28 0"/>
        <circle class="dot" cx="60" cy="92" r="7"/>
        <line class="slash" id="slash" x1="24" y1="24" x2="96" y2="100"/>
      </svg>
    </div>

    <h1 id="title">{{ __('pwa::messages.offline_title') }}</h1>
    <p class="lead" id="lead">{{ __('pwa::messages.offline_lead') }}</p>

    <p class="status" id="status" role="status" aria-live="polite">
      <span class="badge" aria-hidden="true"></span>
      <span id="status-text">{{ __('pwa::messages.waiting') }}</span>
    </p>

    <div class="actions">
      <button class="btn btn-primary" id="retry" type="button">
        <span class="spin" aria-hidden="true"></span>
        <span id="retry-label">{{ __('pwa::messages.retry') }}</span>
      </button>
      <a class="btn btn-ghost" href="{{ $home }}" id="home">{{ __('pwa::messages.home') }}</a>
    </div>

    <section class="section" id="queue-section" hidden>
      <h2>{{ __('pwa::messages.queued_title') }}</h2>
      <div class="queue">
        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" class="queue-icon"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 7v5l3 2M21 12a9 9 0 1 1-9-9"/></svg>
        <div>
          <strong id="queue-count"></strong>
          {{ __('pwa::messages.queued_lead') }}
        </div>
      </div>
    </section>

    <section class="section" id="cached-section" hidden>
      <h2>{{ __('pwa::messages.available_offline') }}</h2>
      <ul class="pages" id="cached-list"></ul>
    </section>

    <p class="hint" id="hint">{{ __('pwa::messages.offline_hint') }}</p>
  </div>
</main>

<script @if($nonce) nonce="{{ $nonce }}" @endif>
(() => {
  const t = {
    waiting: @json(__('pwa::messages.waiting')),
    checking: @json(__('pwa::messages.checking')),
    nextIn: @json(__('pwa::messages.next_retry')),
    back: @json(__('pwa::messages.back_online')),
    online: @json(__('pwa::messages.online')),
    retry: @json(__('pwa::messages.retry')),
    queued: @json(__('pwa::messages.queued_count')),
  };
  const ping = @json(route('pwa.ping', absolute: false));
  const $ = id => document.getElementById(id);
  const card = $('card'), statusEl = $('status'), statusText = $('status-text');
  const retryBtn = $('retry'), slash = $('slash'), art = $('art');

  // Plural forms (Czech needs three): 1 | 2–4 | 5+
  const plural = n => {
    const forms = t.queued.split('|');
    const f = forms.length < 3
      ? forms[n === 1 ? 0 : forms.length - 1]
      : (n === 1 ? forms[0] : (n >= 2 && n <= 4 ? forms[1] : forms[2]));
    return f.replace(':n', n);
  };

  // The page the user wanted (the worker serves the offline response under the original URL).
  const target = location.pathname === @json((string) config('pwa.offline.route', '/offline')) ? (document.referrer || @json($home)) : location.href;

  let delay = 2, timer = null, countdown = null, checking = false;

  const setStatus = (text, online = false) => {
    statusText.textContent = text;
    statusEl.classList.toggle('online', online);
  };

  const isReallyOnline = async () => {
    if (!navigator.onLine) return false;
    try {
      const ctrl = new AbortController();
      const to = setTimeout(() => ctrl.abort(), 4000);
      const r = await fetch(ping + '?t=' + Date.now(), { cache: 'no-store', signal: ctrl.signal });
      clearTimeout(to);
      return r.ok || r.status === 204;
    } catch { return false; }
  };

  const recover = () => {
    setStatus(t.back, true);
    slash.style.opacity = '0';
    art.classList.remove('pulse');
    document.querySelectorAll('.wave').forEach(w => w.classList.add('on'));
    navigator.serviceWorker?.controller?.postMessage({ type: 'REPLAY_QUEUE' });
    setTimeout(() => location.replace(target), 600);
  };

  const schedule = () => {
    clearTimeout(timer); clearInterval(countdown);
    let left = delay;
    setStatus(t.nextIn.replace(':s', left));
    countdown = setInterval(() => {
      left -= 1;
      if (left > 0) setStatus(t.nextIn.replace(':s', left));
    }, 1000);
    timer = setTimeout(check, delay * 1000);
    delay = Math.min(delay * 2, 30);
  };

  async function check() {
    if (checking) return;
    checking = true;
    clearInterval(countdown);
    card.classList.add('busy'); retryBtn.disabled = true;
    setStatus(t.checking);
    const ok = await isReallyOnline();
    card.classList.remove('busy'); retryBtn.disabled = false;
    checking = false;
    ok ? recover() : schedule();
  }

  retryBtn.addEventListener('click', () => { delay = 2; check(); });
  window.addEventListener('online', () => { delay = 2; check(); });
  window.addEventListener('offline', () => { setStatus(t.waiting); });
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') check(); });

  // Cached pages of this app (HTML navigations only, max. 6). Path tenants have their own
  // worker and caches (pwa-@{slug}.{hash}-pages-…, same naming as the worker runtime): list only
  // the ones of the controlling worker.
  const namespaceOf = (scope) => {
    const path = new URL(scope, location.origin).pathname;
    if (path === @json((string) config('pwa.scope', '/'))) return '';
    let hash = 0x811c9dc5;
    for (let i = 0; i < path.length; i++) hash = Math.imul(hash ^ path.charCodeAt(i), 0x01000193) >>> 0;
    return '@' + path.replace(/^\/|\/$/g, '').replace(/[^a-z0-9]+/gi, '-').toLowerCase().slice(0, 32) + '.' + hash.toString(16).padStart(8, '0') + '-';
  };
  const listCached = async () => {
    if (!('caches' in window)) return;
    try {
      const registration = await navigator.serviceWorker?.getRegistration?.();
      const own = 'pwa-' + namespaceOf(registration?.scope || '/') + 'pages-';
      const names = (await caches.keys()).filter(n => n.startsWith('pwa-') ? n.startsWith(own) : /pages|html|navigation/i.test(n));
      const seen = new Map();
      for (const name of names) {
        const cache = await caches.open(name);
        for (const req of await cache.keys()) {
          const u = new URL(req.url);
          if (u.origin !== location.origin || u.pathname === '/offline' || seen.has(u.pathname)) continue;
          const res = await cache.match(req);
          const type = res?.headers.get('content-type') || '';
          if (!type.includes('text/html')) continue;
          const date = res.headers.get('date');
          seen.set(u.pathname, { href: u.pathname + u.search, date: date ? new Date(date) : null });
        }
      }
      const items = [...seen.values()].sort((a, b) => (b.date || 0) - (a.date || 0)).slice(0, 6);
      if (!items.length) return;
      const fmt = new Intl.DateTimeFormat(document.documentElement.lang, { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' });
      const list = $('cached-list');
      for (const it of items) {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = it.href;
        const label = document.createElement('span');
        label.textContent = it.href === '/' ? '/' : decodeURIComponent(it.href);
        a.append(label);
        if (it.date) { const s = document.createElement('small'); s.textContent = fmt.format(it.date); a.append(s); }
        li.append(a); list.append(li);
      }
      $('cached-section').hidden = false;
    } catch { /* no cache access: the section stays hidden */ }
  };

  // Actions waiting in the queue (IndexedDB "pwa", store "queue", filled by the package's worker).
  const countQueue = () => {
    if (!('indexedDB' in window)) return;
    try {
      const req = indexedDB.open('pwa');
      // The database does not exist yet → do not create it (aborting the upgrade removes it).
      req.onupgradeneeded = () => req.transaction.abort();
      req.onerror = () => {};
      req.onsuccess = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains('queue')) { db.close(); return; }
        const c = db.transaction('queue', 'readonly').objectStore('queue').count();
        c.onsuccess = () => {
          if (c.result > 0) { $('queue-count').textContent = plural(c.result); $('queue-section').hidden = false; }
          db.close();
        };
      };
    } catch { /* bez fronty */ }
  };

  listCached();
  countQueue();
  navigator.onLine ? check() : (setStatus(t.waiting), schedule());
})();
</script>
</body>
</html>
