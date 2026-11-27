import { defineConfig, devices } from '@playwright/test';
import { API_DIR, API_ENV, API_ORIGIN, PHP, WEB_URL } from './helpers/env';

// Opcache makes each request about 7x faster (a Laravel boot is ~650 ms without it on Windows). Not in php.ini by default: E2E_OPCACHE=0 turns it off.
const OPCACHE = process.env.E2E_OPCACHE === '0' ? '' : '-d zend_extension=opcache -d opcache.enable_cli=1';

export default defineConfig({
  testDir: './tests',
  globalSetup: './global-setup.ts',
  fullyParallel: false,
  workers: 1, // one database shared by all tests
  retries: process.env.CI ? 1 : 0,
  forbidOnly: !!process.env.CI,
  timeout: 60_000, // the order test drives three browser contexts
  expect: { timeout: 7_000 },
  reporter: process.env.CI
    ? [['list'], ['html', { open: 'never' }], ['github']]
    : [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: WEB_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    // E2E_CHANNEL=msedge runs the installed Edge when the bundled Chromium is blocked.
    channel: process.env.E2E_CHANNEL || undefined,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      // The router script does require getcwd().'/index.php', so run from public/ and without -t.
      // php -S (not artisan serve) so the environment below reaches the app unchanged.
      command: `${PHP} ${OPCACHE} -S 127.0.0.1:8100 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
      cwd: `${API_DIR}/public`,
      url: `${API_ORIGIN}/api/v1/health`,
      // Never reuse: a stale server could point at another database.
      reuseExistingServer: false,
      timeout: 60_000,
      // php -S logs every request to stderr; set E2E_API_LOG=1 to see it.
      stderr: process.env.E2E_API_LOG ? 'pipe' : 'ignore',
      env: API_ENV,
    },
    {
      command: 'npm run dev -- --port 5273 --strictPort',
      cwd: '../web',
      url: WEB_URL,
      reuseExistingServer: false,
      timeout: 60_000,
      env: { API_PROXY_TARGET: API_ORIGIN },
    },
  ],
});
