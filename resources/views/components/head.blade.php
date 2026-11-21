{{--
  <x-pwa::head/> — vložit do <head> layoutu.
  Manifest, barvy, meta pro iOS a klientský skript (registrace SW, instalace,
  keep-alive). Viewport s viewport-fit=cover patří do layoutu aplikace.
--}}
@php
    $pwa = app(\NyonCode\LaravelPwa\Pwa::class);
    $nonce = $pwa->cspNonce();
    $iconPath = trim((string) config('pwa.icons.path', 'pwa/icons'), '/');
    $appleIcon = is_file(public_path($iconPath.'/apple-touch-icon.png')) ? '/'.$iconPath.'/apple-touch-icon.png' : null;
    $clientFile = dirname((new ReflectionClass(\NyonCode\LaravelPwa\Pwa::class))->getFileName(), 2).'/resources/js/pwa.js';
    $clientVersion = substr((string) @hash_file('sha256', $clientFile), 0, 12);
    $title = (string) (config('pwa.short_name') ?: config('pwa.name'));
@endphp
<link rel="manifest" href="{{ route('pwa.manifest', absolute: false) }}">
<meta name="theme-color" content="{{ config('pwa.theme.light') }}" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="{{ config('pwa.theme.dark') }}" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $title }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
@if ($appleIcon)
<link rel="apple-touch-icon" href="{{ $appleIcon }}">
@endif
<script type="application/json" id="pwa-config">@json($pwa->clientConfig())</script>
<script type="module" src="{{ route('pwa.client', absolute: false) }}?v={{ $clientVersion }}" @if ($nonce) nonce="{{ $nonce }}" @endif></script>
