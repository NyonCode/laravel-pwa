@php
    /*
     | Šablona se při `php artisan down --render="pwa::maintenance"` PŘEDRENDERUJE:
     | nemá request, session ani auth. Laravel předá jen $retryAfter (--retry).
     | `pwa:down` nastaví před voláním `down` config pwa.maintenance.message / until.
     */
    $theme = config('pwa.theme', []);
    $accent = $theme['light'] ?? '#0d6efd';
    $accentDark = $theme['accent_dark'] ?? '#5b9dff';
    $appName = config('pwa.short_name') ?: config('pwa.name', config('app.name'));
    $message = config('pwa.maintenance.message') ?: __('pwa::messages.maintenance_lead');
    $until = config('pwa.maintenance.until');              // ISO 8601 nebo null
    $retry = is_numeric($retryAfter ?? null) ? (int) $retryAfter : (int) config('pwa.maintenance.retry', 60);
    $startedAt = now()->toIso8601String();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#f6f7f9" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0e1116" media="(prefers-color-scheme: dark)">
<title>{{ __('pwa::messages.maintenance_title') }} · {{ $appName }}</title>
{{-- Vše inline: během údržby nejsou dostupné ani assety.
     Šablona je předrenderovaná, nonce mít nemůže: pro CSP použijte Pwa::cspHashes(). --}}
<style>
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
.badge{width:10px; height:10px; border-radius:50%; background:var(--accent); flex:none; box-shadow:0 0 0 4px color-mix(in srgb, var(--accent) 18%, transparent)}
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
.hint{font-size:13px; color:var(--muted); text-align:center; margin:20px 0 0}
[hidden]{display:none !important}
.sr{position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap}
@media (prefers-reduced-motion: reduce){ *{animation:none !important; transition:none !important} }
@media (max-width:380px){ .card{padding:24px 18px 20px} }
@media (min-width:900px) and (display-mode: minimal-ui), (min-width:900px) and (display-mode: browser){ .card{padding:40px 40px 32px} }

.art .gear{fill:none; stroke:var(--accent); stroke-width:6; stroke-linejoin:round; transform-origin:60px 60px}
.art .gear-hub{fill:none; stroke:var(--line); stroke-width:6}
.spinning .gear{animation:turn 8s linear infinite}
@keyframes turn{to{transform:rotate(360deg)}}
.eta{display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin:0 0 24px}
.eta div{background:var(--bg); border-radius:14px; padding:14px 16px; text-align:center}
.eta dt{font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin:0 0 4px; font-weight:600}
.eta dd{margin:0; font-size:22px; font-weight:650; font-variant-numeric:tabular-nums; letter-spacing:-.01em}
.bar{height:4px; border-radius:4px; background:var(--line); overflow:hidden; margin:-8px 0 24px}
.bar i{display:block; height:100%; width:0; background:var(--accent); transition:width 1s linear}
</style>
</head>
<body>
<main class="wrap" id="pwa-maintenance"
      data-retry="{{ $retry }}" data-until="{{ $until }}" data-started="{{ $startedAt }}"
      data-t-checking="{{ __('pwa::messages.maintenance_checking') }}" data-t-still="{{ __('pwa::messages.maintenance_still') }}"
      data-t-done="{{ __('pwa::messages.maintenance_done') }}" data-t-offline="{{ __('pwa::messages.maintenance_offline') }}">
  <div class="card" id="card">
    <div class="art spinning" id="art" aria-hidden="true">
      <svg viewBox="0 0 120 120">
        <path class="gear" d="M60 22l7 2 3 9 8 4 9-3 5 5-3 9 4 8 9 3 2 7-2 7-9 3-4 8 3 9-5 5-9-3-8 4-3 9-7 2-7-2-3-9-8-4-9 3-5-5 3-9-4-8-9-3-2-7 2-7 9-3 4-8-3-9 5-5 9 3 8-4 3-9z"/>
        <circle class="gear-hub" cx="60" cy="60" r="14"/>
      </svg>
    </div>

    <h1>{{ __('pwa::messages.maintenance_title') }}</h1>
    <p class="lead">{{ $message }}</p>

    <dl class="eta">
      <div><dt>{{ __('pwa::messages.eta') }}</dt><dd id="eta-time">—</dd></div>
      <div><dt>{{ __('pwa::messages.next_check') }}</dt><dd id="eta-next">—</dd></div>
    </dl>
    <div class="bar" aria-hidden="true"><i id="bar"></i></div>

    <p class="status" id="status" role="status" aria-live="polite">
      <span class="badge" aria-hidden="true"></span>
      <span id="status-text">{{ __('pwa::messages.maintenance_auto') }}</span>
    </p>

    <div class="actions">
      <button class="btn btn-primary" id="retry" type="button">
        <span class="spin" aria-hidden="true"></span>
        <span>{{ __('pwa::messages.retry') }}</span>
      </button>
    </div>

    <p class="hint">{{ __('pwa::messages.maintenance_hint') }}</p>
  </div>
</main>

<script>
(() => {
  // Žádná data z PHP uvnitř skriptu: obsah je stejný při každém renderu,
  // takže jde podepsat SHA-256 hashem pro CSP (Pwa::cspHashes()).
  const $ = id => document.getElementById(id);
  const root = $('pwa-maintenance');
  const d = root.dataset;
  const retryAfter = Math.max(15, Math.min(Number(d.retry) || 60, 300));
  const until = d.until ? new Date(d.until) : new Date(Date.parse(d.started) + (Number(d.retry) || 60) * 1000);
  const statusText = $('status-text'), card = $('card'), btn = $('retry'), bar = $('bar');
  const fmtTime = new Intl.DateTimeFormat(document.documentElement.lang, { hour: '2-digit', minute: '2-digit' });

  $('eta-time').textContent = isNaN(until) ? '—' : fmtTime.format(until);

  let nextAt = Date.now() + retryAfter * 1000, checking = false;

  const tick = () => {
    const left = Math.max(0, Math.round((nextAt - Date.now()) / 1000));
    $('eta-next').textContent = left >= 60 ? `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}` : `${left} s`;
    bar.style.width = `${100 - (left / retryAfter) * 100}%`;
    if (left === 0) check();
  };

  async function check() {
    if (checking) return;
    if (!navigator.onLine) { statusText.textContent = d.tOffline; nextAt = Date.now() + retryAfter * 1000; return; }
    checking = true; card.classList.add('busy'); btn.disabled = true;
    statusText.textContent = d.tChecking;
    let up = false;
    try {
      const r = await fetch(location.href, { method: 'HEAD', cache: 'no-store', credentials: 'same-origin' });
      up = r.status !== 503;
    } catch { up = false; }
    checking = false; card.classList.remove('busy'); btn.disabled = false;
    if (up) {
      statusText.textContent = d.tDone;
      location.reload();
    } else {
      statusText.textContent = d.tStill;
      nextAt = Date.now() + retryAfter * 1000;
    }
  }

  btn.addEventListener('click', check);
  window.addEventListener('online', check);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') check(); });
  setInterval(tick, 1000); tick();
})();
</script>
</body>
</html>
