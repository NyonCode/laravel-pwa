<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · PWA Workbench</title>
    <x-pwa::head/>
</head>
<body>
    <header style="display:flex;gap:12px;align-items:center">
        <x-pwa::nav/>
        <strong id="title">{{ $title }}</strong>
        <x-pwa::install>Nainstalovat</x-pwa::install>
        <x-pwa::push-toggle/>
    </header>
    <nav><a href="/">Úvod</a> · <a href="/orders">Objednávky</a> · <a href="/components">Komponenty</a></nav>
    <x-pwa::outbox/>
    <x-pwa::update-toast/>
</body>
</html>
