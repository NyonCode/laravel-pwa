// Browser tests of the service worker against the workbench app (vendor/bin/testbench serve).
import { defineConfig, devices } from '@playwright/test';

const PORT = Number(process.env.PWA_E2E_PORT || 8123);

export default defineConfig({
  testDir: 'tests/e2e',
  testMatch: '**/*.spec.mjs',
  // The specs change server state (maintenance, kill switch): one worker, in order.
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    trace: 'retain-on-failure',
    serviceWorkers: 'allow',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    // Playwright's offline emulation in Firefox does not cover requests made by the
    // service worker, so offline scenarios are verified in Chromium only.
    { name: 'firefox', use: { ...devices['Desktop Firefox'] }, grepInvert: /@offline/ },
  ],
  webServer: {
    command: `${process.env.PHP || 'php'} vendor/bin/testbench serve --port=${PORT}`,
    url: `http://127.0.0.1:${PORT}/pwa/ping`,
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
});
