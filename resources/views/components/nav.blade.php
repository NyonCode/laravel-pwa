{{--
  <x-pwa::nav/> — tlačítka Zpět a Obnovit pro desktop tam, kde je prohlížeč nenabízí:
  standalone (Safari v Docku, Firefox web app) a Window Controls Overlay (Edge / Chrome).
  V minimal-ui (Edge / Chrome na Windows) a na telefonu/tabletu zůstává skrytá.
--}}
@props(['startUrl' => config('pwa.start_url', '/')])

@php($nonce = \Illuminate\Support\Facades\Vite::cspNonce())
@if (config('pwa.desktop_nav.enabled', true))
<nav class="pwa-nav" aria-label="{{ __('pwa::messages.app_navigation') }}" data-start-url="{{ $startUrl }}" data-min-width="{{ (int) config('pwa.desktop_nav.min_width', 900) }}" hidden>
  <button type="button" class="pwa-nav__btn" data-pwa-nav="back" aria-label="{{ __('pwa::messages.back') }}" title="{{ __('pwa::messages.back') }} (Alt+←)">
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </button>
  <button type="button" class="pwa-nav__btn" data-pwa-nav="reload" aria-label="{{ __('pwa::messages.reload_page') }}" title="{{ __('pwa::messages.reload_page') }} (F5)">
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </button>
</nav>

<style @if($nonce) nonce="{{ $nonce }}" @endif>
  .pwa-nav{display:flex; gap:4px; align-items:center; -webkit-app-region:no-drag; app-region:no-drag}
  .pwa-nav[hidden]{display:none}
  .pwa-nav__btn{
    width:32px; height:32px; display:inline-grid; place-items:center; border:0; border-radius:8px;
    background:transparent; color:inherit; cursor:pointer; opacity:.85;
  }
  .pwa-nav__btn:hover{background:color-mix(in srgb, currentColor 10%, transparent); opacity:1}
  .pwa-nav__btn:focus-visible{outline:2px solid currentColor; outline-offset:1px}
  .pwa-nav__btn[disabled]{opacity:.35; cursor:default; background:transparent}
  /* Window Controls Overlay: posadit do titulkového pruhu vedle systémových tlačítek */
  @media (display-mode: window-controls-overlay){
    .pwa-nav{position:fixed; top:env(titlebar-area-y, 0); left:env(titlebar-area-x, 0); height:env(titlebar-area-height, 33px); padding:0 6px; z-index:1000}
  }
</style>

<script @if($nonce) nonce="{{ $nonce }}" @endif>
(() => {
  const nav = document.currentScript.previousElementSibling.previousElementSibling;
  const mq = q => window.matchMedia(q).matches;
  // Desktop = jemný ukazatel + šířka; telefon a tablet mají systémové gesto zpět.
  const minWidth = Number(nav.dataset.minWidth || 900);
  const desktop = () => mq('(pointer: fine)') && mq(`(min-width: ${minWidth}px)`);
  // V minimal-ui a v prohlížeči kreslí tlačítka sám prohlížeč.
  const needed = () => desktop() && (mq('(display-mode: standalone)') || mq('(display-mode: window-controls-overlay)'));

  const back = nav.querySelector('[data-pwa-nav="back"]');
  const startPath = new URL(nav.dataset.startUrl, location.href).pathname;
  const update = () => {
    nav.hidden = !needed();
    // Na úvodní stránce aplikace není kam se vrátit.
    back.disabled = location.pathname === startPath && history.length <= 1;
  };

  back.addEventListener('click', () => history.back());
  nav.querySelector('[data-pwa-nav="reload"]').addEventListener('click', () => location.reload());

  ['(display-mode: standalone)', '(display-mode: window-controls-overlay)', '(pointer: fine)', `(min-width: ${minWidth}px)`]
    .forEach(q => window.matchMedia(q).addEventListener('change', update));
  window.addEventListener('popstate', update);
  document.addEventListener('livewire:navigated', update);
  document.addEventListener('inertia:navigate', update);
  update();
})();
</script>
@endif
