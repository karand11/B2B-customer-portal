import { defineConfig, devices } from '@playwright/test';
import { API_DIR, API_ENV, API_ORIGIN, PHP, WEB_URL } from './helpers/env';

// Opcache makes each request about 7x faster (a Laravel boot is ~650 ms without it on Windows). Not in php.ini by default: E2E_OPCACHE=0 turns it off.
const OPCACHE = process.env.E2E_OPCACHE === '0' ? '' : '-d zend_extension=opcache -d opcache.enable_cli=1';

// E2E_WEB=dev (default): Vite dev server, no security headers. E2E_WEB=preview: `vite build` + `vite preview`, the production
// bundle with the enforced CSP and the other SPA headers from web/security-headers.ts (CI uses this; a CSP violation fails the test).
const WEB_MODE = process.env.E2E_WEB ?? 'dev';
if (WEB_MODE !== 'dev' && WEB_MODE !== 'preview') throw new Error(`E2E_WEB must be "dev" or "preview", got "${WEB_MODE}"`);
// `npm run preview` (= vite preview) serves web/dist, so build first. E2E_SKIP_BUILD=1 reuses an existing dist/ (when you just built it yourself).
const WEB_COMMAND =
  WEB_MODE === 'preview'
    ? `${process.env.E2E_SKIP_BUILD === '1' ? '' : 'npm run build && '}npm run preview -- --port 5273 --strictPort`
    : 'npm run dev -- --port 5273 --strictPort';

export default defineConfig({
  testDir: '.',
  testMatch: ['tests/**/*.spec.ts', 'a11y/**/*.spec.ts'],
  // The Lighthouse wrapper only runs through `npm run lighthouse` (it sets LH_DRIVER): a plain run must not start it.
  testIgnore: process.env.LH_DRIVER ? [] : ['a11y/lighthouse.spec.ts'],
  globalSetup: './global-setup.ts',
  fullyParallel: false,
  workers: 1, // one database shared by all tests
  retries: process.env.CI ? 1 : 0,
  forbidOnly: !!process.env.CI,
  timeout: 60_000, // the order test drives three browser contexts; the journeys raise it to 180 s
  expect: { timeout: 7_000 },
  reporter: process.env.CI
    ? [['list'], ['html', { open: 'never' }], ['github']]
    : [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: WEB_URL,
    // Traces hold cookies and the demo passwords: only recorded for a retried test (retries are on in CI only; locally pass --trace on).
    trace: 'on-first-retry',
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
      command: WEB_COMMAND,
      cwd: '../web',
      url: WEB_URL,
      reuseExistingServer: false,
      timeout: 180_000, // includes `npm run build` (tsc + vite) in preview mode
      env: { API_PROXY_TARGET: API_ORIGIN },
    },
  ],
});
