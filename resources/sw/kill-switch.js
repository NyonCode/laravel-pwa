/*
 * nyoncode/laravel-pwa — nouzové vypnutí (php artisan pwa:disable).
 *
 * Nahradí běžný service worker, smaže cache balíčku, odregistruje se
 * a otevřená okna znovu načte ze sítě. Nic dalšího nedělá.
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
