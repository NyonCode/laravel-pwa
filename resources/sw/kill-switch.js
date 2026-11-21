/*
 * nyoncode/laravel-pwa — kill switch (php artisan pwa:disable).
 *
 * Replaces the regular service worker, deletes the package's caches, unregisters
 * itself and reloads open windows from the network. It does nothing else.
 */
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key.startsWith('pwa-')).map((key) => caches.delete(key)));
    await self.registration.unregister();
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach((client) => client.navigate(client.url).catch(() => {}));
  })());
});
