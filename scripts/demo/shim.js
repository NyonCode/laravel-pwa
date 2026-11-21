// Demo only: GitHub Pages cannot send the X-PWA-Cache header that the pwa.cache middleware adds in a
// Laravel app, so this script (loaded through pwa.sw.import, before the runtime) caches the demo's
// own pages network-first and leaves everything else to the package's worker.
(() => {
  const scope = new URL(self.registration.scope);
  const CACHE = 'pwa-demo-pages';

  self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.mode !== 'navigate' || event.request.method !== 'GET' || !url.href.startsWith(scope.href)) return;
    if (url.pathname.endsWith('/offline.html')) return;

    event.stopImmediatePropagation();
    event.respondWith((async () => {
      try {
        const response = await fetch(event.request);
        if (response.ok) {
          const copy = response.clone();
          event.waitUntil(caches.open(CACHE).then((cache) => cache.put(event.request, copy)));
        }
        return response;
      } catch {
        return (await caches.match(event.request, { cacheName: CACHE, ignoreSearch: true }))
          || (await caches.match(new URL('offline.html', scope).href))
          || Response.error();
      }
    })());
  });
})();
