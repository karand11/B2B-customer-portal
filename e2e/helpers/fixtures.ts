import { expect, test as base } from '@playwright/test';
import { CspGuard, setCurrentGuard, watchCsp } from './csp';

export { expect };

/**
 * Every spec imports `test` from here. Two things are added to Playwright's test:
 * - `csp` (automatic): any Content-Security-Policy violation seen by a page of the test fails it. Pages created by the
 *   `page`/`context` fixtures are watched here, pages created through `actor()`/`actorFor()` in helpers/api.ts too.
 * - `context` is watched before its first page exists.
 */
export const test = base.extend<{ csp: CspGuard }>({
  csp: [
    async ({}, use, testInfo) => {
      const guard = new CspGuard();
      setCurrentGuard(guard);
      await use(guard);
      setCurrentGuard(null);
      // A failing test gets the browser-side evidence (page errors, failed requests, HTTP errors) next to its screenshot.
      if (testInfo.status !== testInfo.expectedStatus && guard.log.length) await testInfo.attach('browser-log', { body: guard.log.join('\n'), contentType: 'text/plain' });
      expect(guard.violations, 'Content-Security-Policy violations').toEqual([]);
    },
    { auto: true },
  ],
  context: async ({ context, csp }, use) => {
    void csp;
    await watchCsp(context);
    await use(context);
  },
});
