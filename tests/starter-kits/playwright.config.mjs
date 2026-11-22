// Smoke test of the package inside a Laravel starter kit. Run through scripts/starter-kit.sh,
// which creates the app and starts `php artisan serve` (KIT_URL).
import { defineConfig, devices } from '@playwright/test';

if (!process.env.KIT_URL) throw new Error('KIT_URL is not set: run scripts/starter-kit.sh <livewire|react|vue>');

export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.mjs',
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  outputDir: '../../test-results/starter-kits',
  use: {
    baseURL: process.env.KIT_URL,
    trace: 'retain-on-failure',
    serviceWorkers: 'allow',
  },
  // Offline emulation covers requests from the service worker in Chromium only (see playwright.config.mjs).
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
