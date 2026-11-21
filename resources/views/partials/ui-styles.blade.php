{{--
  Base styles for <x-pwa::install>, <x-pwa::update-toast> and <x-pwa::push-toggle>, printed once per page.
  Theme them with CSS variables, or pass :styles="false" to the components and style the pwa-* classes yourself.
--}}
@once
@php($nonce = app(\NyonCode\LaravelPwa\Pwa::class)->cspNonce())
<style @if($nonce) nonce="{{ $nonce }}" @endif>
  :where(.pwa-ui){
    --pwa-surface:#1f2937; --pwa-on-surface:#fff; --pwa-muted:rgba(255,255,255,.72);
    --pwa-action:rgba(255,255,255,.16); --pwa-radius:12px; --pwa-shadow:0 8px 24px rgba(0,0,0,.18);
    --pwa-font:500 14px/1.4 system-ui,-apple-system,sans-serif;
  }
  :where(.pwa-ui[hidden], .pwa-ui [hidden]){display:none !important}
  :where(.pwa-toast, .pwa-ios-hint){
    position:fixed; left:50%; bottom:max(16px, env(safe-area-inset-bottom)); transform:translateX(-50%);
    z-index:2147483000; box-sizing:border-box; width:max-content; max-width:calc(100vw - 32px);
    padding:12px 14px; border-radius:var(--pwa-radius); box-shadow:var(--pwa-shadow);
    background:var(--pwa-surface); color:var(--pwa-on-surface); font:var(--pwa-font);
  }
  :where(.pwa-toast){display:flex; gap:12px; align-items:center}
  :where(.pwa-toast__actions){display:flex; gap:8px; margin-left:auto}
  :where(.pwa-toast button, .pwa-ios-hint button){
    font:inherit; font-weight:600; color:inherit; background:var(--pwa-action); border:0; border-radius:8px;
    padding:6px 10px; cursor:pointer;
  }
  :where(.pwa-toast button:focus-visible, .pwa-ios-hint button:focus-visible, .pwa-push button:focus-visible){outline:2px solid currentColor; outline-offset:2px}
  :where(.pwa-ios-hint){width:min(360px, calc(100vw - 32px))}
  :where(.pwa-ios-hint p, .pwa-ios-hint ol){margin:0 0 10px}
  :where(.pwa-ios-hint__title){font-weight:600}
  :where(.pwa-ios-hint ol){padding-left:1.25em; color:var(--pwa-muted)}
  :where(.pwa-ios-hint__icon){width:1.1em; height:1.1em; vertical-align:-.2em}
  :where(.pwa-ios-hint__close){display:block; margin-left:auto}
  :where(.pwa-push){display:inline-flex; flex-direction:column; gap:4px}
  :where(.pwa-push__hint){font-size:.875em; opacity:.8}
</style>
@endonce
