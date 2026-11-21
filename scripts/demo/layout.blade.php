<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }} · Laravel PWA demo</title>
    <x-pwa::head/>
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --fg: #0f172a; --muted: #475569; --card: #fff; --line: #e2e8f0; --accent: #4f46e5; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1220; --fg: #e2e8f0; --muted: #94a3b8; --card: #111a2e; --line: #1e293b; --accent: #818cf8; } }
        * { box-sizing: border-box; }
        body { margin: 0; font: 16px/1.55 system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--fg); }
        header { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--card); }
        header strong { margin-right: auto; }
        nav a { color: var(--accent); margin-right: 14px; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 16px 96px; }
        h1 { font-size: 1.6rem; margin: 0 0 8px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; margin: 16px 0; }
        .muted { color: var(--muted); }
        button.demo, .pwa-install { font: inherit; font-weight: 600; border: 0; border-radius: 8px; padding: 8px 14px; background: var(--accent); color: #fff; cursor: pointer; }
        code { font-size: .9em; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 0; }
        dt { color: var(--muted); }
    </style>
</head>
<body>
    <header>
        <x-pwa::nav start-url="{{ $base }}/"/>
        <strong>Laravel PWA demo</strong>
        <x-pwa::install>Install the demo</x-pwa::install>
    </header>
    <main>
        <nav><a href="{{ $base }}/">Home</a><a href="{{ $base }}/orders.html">Orders</a><a href="{{ $base }}/about.html">About</a></nav>
        {{ $slot }}
    </main>
    <x-pwa::update-toast/>
</body>
</html>
