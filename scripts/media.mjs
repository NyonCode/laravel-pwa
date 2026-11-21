// Screenshots for the README, taken from the static demo: node scripts/media.mjs
// Needs the demo (php scripts/demo-build.php) and Playwright browsers (npx playwright install).
import { createServer } from 'node:http';
import { mkdirSync, readFileSync, statSync } from 'node:fs';
import { extname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, devices, webkit } from '@playwright/test';

const ROOT = fileURLToPath(new URL('../', import.meta.url));
const DEMO = join(ROOT, 'docs/public/demo');
const OUT = join(ROOT, '.github/media');
const BASE = '/laravel-pwa/demo/';
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.png': 'image/png', '.webmanifest': 'application/manifest+json' };

mkdirSync(OUT, { recursive: true });

const server = createServer((req, res) => {
  const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (!path.startsWith(BASE)) { res.writeHead(404).end(); return; }
  let file = join(DEMO, path.slice(BASE.length));
  try { if (statSync(file).isDirectory()) file = join(file, 'index.html'); } catch { res.writeHead(404).end(); return; }
  res.writeHead(200, { 'Content-Type': TYPES[extname(file)] || 'application/json' }).end(readFileSync(file));
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const url = `http://127.0.0.1:${server.address().port}${BASE}`;

const controlled = async (page) => {
  await page.evaluate(() => navigator.serviceWorker.ready);
  if (!(await page.evaluate(() => !!navigator.serviceWorker.controller))) await page.reload();
  await page.waitForFunction(() => !!navigator.serviceWorker.controller);
};

// 1. Update toast after a (simulated) deploy, desktop.
{
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1100, height: 680 }, deviceScaleFactor: 2, colorScheme: 'light' });
  await page.goto(url);
  await controlled(page);
  await page.click('#simulate-update');
  await page.locator('[data-pwa-update]').waitFor({ state: 'visible' });
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: join(OUT, 'update-toast.png') });

  // 2. Offline: a page that was never opened gets the offline screen, the status bar shows on cached ones.
  await page.goto(`${url}orders.html`);
  await page.context().setOffline(true);
  await page.goto(`${url}about.html`);
  await page.screenshot({ path: join(OUT, 'offline.png') });
  await page.goto(`${url}orders.html`);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await page.locator('.pwa-bar').waitFor({ state: 'visible' });
  await page.screenshot({ path: join(OUT, 'offline-cached.png') });
  await browser.close();
}

// 3. iPhone Safari: Share → Add to Home Screen instructions.
{
  const browser = await webkit.launch();
  const context = await browser.newContext({ ...devices['iPhone 15'], colorScheme: 'light' });
  await context.addInitScript(() => { delete window.PushManager; });
  const page = await context.newPage();
  await page.goto(url);
  await controlled(page);
  await page.waitForFunction(() => document.getElementById('worker').textContent.startsWith('active'));
  await page.locator('[data-pwa-ios-hint]').waitFor({ state: 'visible' });
  await page.screenshot({ path: join(OUT, 'ios-install.png') });
  await browser.close();
}

server.close();
console.log(`Screenshots written to ${OUT}`);
