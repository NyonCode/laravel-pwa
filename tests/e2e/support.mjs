import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));

/** Runs a testbench Artisan command against the workbench app. */
export const artisan = (...args) =>
  execFileSync(process.env.PHP || 'php', ['vendor/bin/testbench', ...args], { cwd: ROOT, encoding: 'utf8' });

/** Paths stored in all per-user page caches. */
export const cachedPages = (page) =>
  page.evaluate(async () => {
    const out = [];
    for (const name of (await caches.keys()).filter((k) => k.startsWith('pwa-pages-'))) {
      const cache = await caches.open(name);
      out.push(...(await cache.keys()).map((r) => new URL(r.url).pathname));
    }
    return out;
  });

export const queueCount = (page) =>
  page.evaluate(
    () =>
      new Promise((resolve) => {
        const open = indexedDB.open('pwa');
        open.onsuccess = () => {
          const count = open.result.transaction('queue').objectStore('queue').count();
          count.onsuccess = () => resolve(count.result);
        };
      }),
  );

/** Waits until the worker controls the page. */
export const controlled = async (page) => {
  await page.evaluate(() => navigator.serviceWorker.ready);
  if (!(await page.evaluate(() => !!navigator.serviceWorker.controller))) await page.reload();
  await page.waitForFunction(() => !!navigator.serviceWorker.controller);
};
