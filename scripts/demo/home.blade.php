<x-demo::layout :title="'Home'" :base="$base">
    <h1 id="title">nyoncode/laravel-pwa</h1>
    <p class="muted">A static copy of a Laravel app with the package installed. Install it, go offline, and try the update toast.</p>

    <div class="card">
        <h2>Status</h2>
        <dl>
            <dt>Display mode</dt><dd id="mode">…</dd>
            <dt>Service worker</dt><dd id="worker">…</dd>
            <dt>Network</dt><dd id="network">…</dd>
        </dl>
    </div>

    <div class="card">
        <h2>Try it</h2>
        <ol>
            <li><strong>Install</strong>: the button in the header (Chrome, Edge), or Share → Add to Home Screen on iPhone and iPad.</li>
            <li><strong>Offline</strong>: open Orders and About once, then turn on airplane mode (or DevTools → Network → Offline) and reload. Pages you opened come from the cache, others get the offline screen.</li>
            <li><strong>Update</strong>: <button type="button" class="demo" id="simulate-update">Simulate a new version</button> registers the worker again under a new URL, as a deploy would. The toast at the bottom asks before switching.</li>
        </ol>
    </div>

    <div class="card muted">
        <h2>What a real app adds</h2>
        <p>GitHub Pages serves static files, so this demo has no server: no sign-in, no per-user caches, no CSRF keep-alive and no web push. A Laravel app allows page caching with the <code>pwa.cache</code> middleware; here a small <code>sw.import</code> script marks the demo pages as cacheable instead.</p>
        <p><a href="{{ $docs }}">Read the documentation</a> · <a href="https://github.com/NyonCode/laravel-pwa">Source on GitHub</a></p>
    </div>

    <script>
        const set = (id, text) => { document.getElementById(id).textContent = text; };
        const refresh = () => {
            set('mode', window.Pwa ? Pwa.displayMode() : 'browser');
            set('network', navigator.onLine ? 'online' : 'offline');
            set('worker', navigator.serviceWorker?.controller ? 'active, controls this page' : 'installing…');
        };
        addEventListener('online', refresh); addEventListener('offline', refresh);
        document.addEventListener('pwa:ready', refresh);
        navigator.serviceWorker?.addEventListener('controllerchange', refresh);
        setTimeout(refresh, 500);

        document.getElementById('simulate-update').addEventListener('click', async () => {
            const config = JSON.parse(document.getElementById('pwa-config').textContent);
            await navigator.serviceWorker.register(`${config.sw}?deploy=${Date.now()}`, { scope: config.scope, updateViaCache: 'none' });
        });
    </script>
</x-demo::layout>
