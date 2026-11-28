import { spawnSync } from 'node:child_process';
import * as path from 'node:path';
import { expect, test } from '@playwright/test';
import { WEB_URL } from '../helpers/env';

/**
 * Only runs through `npm run lighthouse` (a11y/lighthouse.mjs sets LH_DRIVER): Playwright's config starts the isolated stack
 * (API 8100, `vite build` + `vite preview` 5273, portal_e2e reseeded) and this test runs the audit script against it.
 * It is ignored by a plain `npx playwright test` (see testIgnore in playwright.config.ts).
 */
test('Lighthouse accessibility >= 0.90 on login, catalog and orders, desktop and mobile', async () => {
  test.setTimeout(600_000);
  expect(process.env.E2E_WEB, 'Lighthouse audits the production build').toBe('preview');
  const run = spawnSync(process.execPath, [path.join(__dirname, 'lighthouse.mjs'), '--inner'], {
    env: { ...process.env, LH_BASE_URL: WEB_URL },
    encoding: 'utf8',
    timeout: 540_000,
  });
  console.log(run.stdout);
  if (run.stderr) console.error(run.stderr);
  expect(run.status, 'lighthouse.mjs exit code (non-zero: a score below the minimum or a failed run)').toBe(0);
});
