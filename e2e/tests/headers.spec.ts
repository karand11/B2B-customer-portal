import { type APIResponse } from '@playwright/test';
import { expect, test } from '../helpers/fixtures';
import { API_ORIGIN, IS_PREVIEW, WEB_URL } from '../helpers/env';
import { actor } from '../helpers/api';
import { apiAs } from '../helpers/data';
import { SPA_SECURITY_HEADERS } from '../../web/security-headers';

// Headers of docs/phase-7-design.md 3.2 (API, every response) and 3.3 (SPA, `vite preview` only: the dev server sends none).

const PERMISSIONS =
  'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()';
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const STATEFUL = { Origin: WEB_URL, Accept: 'application/json' };

/** The header set every Laravel response carries, error responses and preflights included. */
function expectApiHeaders(res: APIResponse, { noStore = true }: { noStore?: boolean } = {}) {
  const h = res.headers();
  expect(h['x-content-type-options']).toBe('nosniff');
  // Must be exactly "origin": "no-referrer" would make Sanctum treat every page load as a guest request.
  expect(h['referrer-policy']).toBe('origin');
  expect(h['x-frame-options']).toBe('DENY');
  expect(h['permissions-policy']).toBe(PERMISSIONS);
  expect(h['cross-origin-resource-policy']).toBe('same-site');
  expect(h['content-security-policy']).toBe("default-src 'none'; frame-ancestors 'none'");
  if (noStore) expect(h['cache-control'], 'Cache-Control').toContain('no-store');
  expect(h['x-request-id']).toMatch(/^[A-Za-z0-9._-]{8,64}$/);
  expect(h['x-powered-by'], 'X-Powered-By must not be sent').toBeUndefined();
  expect(h['x-xss-protection'], 'X-XSS-Protection is obsolete').toBeUndefined();
  expect(h['strict-transport-security'], 'HSTS only over HTTPS in production/staging').toBeUndefined();
}

test.describe('API response headers', () => {
  test('success and error responses carry the full header set', async ({ request }) => {
    const cases: [string, () => Promise<APIResponse>, number][] = [
      ['200 health', () => request.get(`${API_ORIGIN}/api/v1/health`), 200],
      ['204 csrf cookie', () => request.get(`${API_ORIGIN}/sanctum/csrf-cookie`, { headers: STATEFUL }), 204],
      ['401 guest', () => request.get(`${API_ORIGIN}/api/v1/me`, { headers: { Accept: 'application/json' } }), 401],
      ['404 unknown api route', () => request.get(`${API_ORIGIN}/api/v1/nope`, { headers: { Accept: 'application/json' } }), 404],
      ['404 API host root (no welcome page)', () => request.get(`${API_ORIGIN}/`), 404],
      ['405 wrong method', () => request.delete(`${API_ORIGIN}/api/v1/health`, { headers: { Accept: 'application/json' } }), 405],
      [
        '419 stateful write without CSRF token',
        () => request.post(`${API_ORIGIN}/api/v1/auth/login`, { headers: STATEFUL, data: { email: 'a@b.test', password: 'x' } }),
        419,
      ],
    ];
    for (const [label, call, status] of cases) {
      const res = await call();
      expect(res.status(), label).toBe(status);
      // no-store is for api/* and sanctum/*; the bare host root is outside it
      expectApiHeaders(res, { noStore: !label.includes('host root') });
    }
  });

  test('422 validation error carries the full header set', async ({ request }) => {
    await request.get(`${API_ORIGIN}/sanctum/csrf-cookie`, { headers: STATEFUL });
    const { cookies } = await request.storageState();
    const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')!.value);
    const res = await request.post(`${API_ORIGIN}/api/v1/auth/login`, {
      headers: { ...STATEFUL, 'X-XSRF-TOKEN': xsrf },
      data: {},
    });
    expect(res.status()).toBe(422);
    expectApiHeaders(res);
  });

  test('the same headers arrive through the SPA origin (proxy)', async ({ request }) => {
    const res = await request.get('/api/v1/health');
    expect(res.status()).toBe(200);
    expectApiHeaders(res);
  });

  test('X-Request-Id: valid ids are echoed, anything else is replaced by a UUID v4', async ({ request }) => {
    const call = (id: string, path = '/api/v1/health') =>
      request.get(`${API_ORIGIN}${path}`, { headers: { 'X-Request-Id': id, Accept: 'application/json' } });

    const accepted = ['abcdefgh', 'req-2026.11_28-ABCdef', 'a'.repeat(64), 'A1._-b2C3'];
    for (const id of accepted) expect((await call(id)).headers()['x-request-id'], `valid: ${id}`).toBe(id);

    const rejected = ['short', 'abcdefg', 'a'.repeat(65), 'has space inside', 'a/b/c/d/e/f/g/h', '<script>alert(1)</script>', 'semi;colon;value1'];
    for (const id of rejected) {
      const echoed = (await call(id)).headers()['x-request-id'];
      expect(echoed, `invalid: ${id}`).not.toBe(id);
      expect(echoed, `invalid: ${id}`).toMatch(UUID_V4);
    }

    // Error responses echo it too, and two requests without an id get different ids.
    expect((await call('error-path-id-1', '/api/v1/me')).headers()['x-request-id']).toBe('error-path-id-1');
    expect((await call('error-path-id-2', '/api/v1/nope')).headers()['x-request-id']).toBe('error-path-id-2');
    const a = (await request.get(`${API_ORIGIN}/api/v1/health`)).headers()['x-request-id'];
    const b = (await request.get(`${API_ORIGIN}/api/v1/health`)).headers()['x-request-id'];
    expect(a).toMatch(UUID_V4);
    expect(a).not.toBe(b);
  });
});

test.describe('CORS', () => {
  const preflight = (request: import('@playwright/test').APIRequestContext, origin: string, base = API_ORIGIN, method = 'POST') =>
    request.fetch(`${base}/api/v1/auth/login`, {
      method: 'OPTIONS',
      headers: {
        Origin: origin,
        'Access-Control-Request-Method': method,
        'Access-Control-Request-Headers': 'content-type,x-xsrf-token,x-request-id',
      },
    });

  test('preflight from the portal origin is allowed, with credentials and the security headers', async ({ request }) => {
    const res = await preflight(request, WEB_URL);
    expect(res.status()).toBeLessThan(300);
    const h = res.headers();
    expect(h['access-control-allow-origin']).toBe(WEB_URL); // exact, never "*"
    expect(h['access-control-allow-credentials']).toBe('true');
    expect(h['access-control-allow-methods']).toMatch(/POST/);
    expect(h['access-control-allow-headers']?.toLowerCase()).toContain('x-xsrf-token');
    expect(h['vary']?.toLowerCase()).toContain('origin');
    expect(h['x-content-type-options']).toBe('nosniff');
    expect(h['referrer-policy']).toBe('origin');
    expect(h['x-request-id']).toBeTruthy();
  });

  for (const origin of ['http://evil.test', 'null', 'http://localhost:5273.evil.test', 'http://localhost:5274', 'https://localhost:5273', 'http://LOCALHOST:5273']) {
    test(`preflight from ${origin} gets no Allow-Origin`, async ({ request }) => {
      const res = await preflight(request, origin);
      const h = res.headers();
      expect(h['access-control-allow-origin']).toBeUndefined();
      expect(h['access-control-allow-credentials']).toBeUndefined();
      expect(h['x-content-type-options']).toBe('nosniff'); // the headers wrap CORS
    });
  }

  test('a disallowed method or header from the allowed origin gets no Allow-Origin', async ({ request }) => {
    const badMethod = await preflight(request, WEB_URL, API_ORIGIN, 'TRACE');
    expect(badMethod.headers()['access-control-allow-methods'] ?? '').not.toMatch(/TRACE/);
    const badHeader = await request.fetch(`${API_ORIGIN}/api/v1/auth/login`, {
      method: 'OPTIONS',
      headers: { Origin: WEB_URL, 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'x-evil-header' },
    });
    expect(badHeader.headers()['access-control-allow-headers']?.toLowerCase() ?? '').not.toContain('x-evil-header');
  });

  test('a real request: allowed origin gets Allow-Origin and the exposed request id; a foreign origin gets none', async ({ request }) => {
    const ok = await request.get(`${API_ORIGIN}/api/v1/health`, { headers: { Origin: WEB_URL } });
    expect(ok.headers()['access-control-allow-origin']).toBe(WEB_URL);
    expect(ok.headers()['access-control-allow-credentials']).toBe('true');
    expect(ok.headers()['access-control-expose-headers']?.toLowerCase()).toContain('x-request-id');
    const foreign = await request.get(`${API_ORIGIN}/api/v1/health`, { headers: { Origin: 'http://evil.test' } });
    expect(foreign.status()).toBe(200);
    expect(foreign.headers()['access-control-allow-origin']).toBeUndefined();
    expect(foreign.headers()['access-control-allow-credentials']).toBeUndefined();
  });

  test('through the SPA origin the proxy does not widen CORS', async ({ request }) => {
    const res = await preflight(request, 'http://evil.test', WEB_URL);
    expect(res.headers()['access-control-allow-origin']).toBeUndefined();
  });

  test('a valid session cookie on a request from a foreign origin is a guest (reads and writes)', async () => {
    const customer = await apiAs('customer');
    try {
      const mine = await customer.get('/api/v1/me', { headers: STATEFUL });
      expect(mine.status(), 'control: the session works from the portal origin').toBe(200);
      for (const origin of ['http://evil.test', 'null']) {
        const read = await customer.get('/api/v1/me', { headers: { Origin: origin, Accept: 'application/json' } });
        expect(read.status(), `read from ${origin}`).toBe(401);
        const { cookies } = await customer.storageState();
        const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')!.value);
        const write = await customer.post('/api/v1/cart/items', {
          headers: { Origin: origin, Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
          data: { product_id: 1, quantity: 1 },
        });
        expect([401, 419], `write from ${origin}`).toContain(write.status());
      }
    } finally {
      await customer.dispose();
    }
  });
});

test.describe('SPA response headers (vite preview, enforced CSP)', () => {
  test.skip(!IS_PREVIEW, 'the dev server sends no security headers; run with E2E_WEB=preview');

  const expectSpaHeaders = (res: APIResponse) => {
    const h = res.headers();
    for (const [name, value] of Object.entries(SPA_SECURITY_HEADERS)) {
      expect(h[name.toLowerCase()], name).toBe(value);
    }
  };

  test('index, assets and deep links carry exactly the headers of web/security-headers.ts', async ({ request }) => {
    const index = await request.get('/');
    expect(index.status()).toBe(200);
    expectSpaHeaders(index);
    const html = await index.text();

    const asset = /(?:src|href)="(\/assets\/[^"]+\.js)"/.exec(html)?.[1];
    expect(asset, 'index.html references a hashed JS asset').toBeTruthy();
    const js = await request.get(asset!);
    expect(js.status()).toBe(200);
    expectSpaHeaders(js);

    for (const path of ['/orders', '/admin/audit-log', '/does-not-exist']) {
      const res = await request.get(path);
      expect(res.status(), path).toBe(200); // SPA fallback: React Router shows its own 404 page
      expectSpaHeaders(res);
    }
  });

  test('the policy is strict and the document has no inline script or style', async ({ request }) => {
    const res = await request.get('/');
    const csp = res.headers()['content-security-policy'];
    expect(csp).toContain("default-src 'self'");
    expect(csp).toContain("script-src 'self'");
    expect(csp).toContain("style-src 'self'");
    expect(csp).toContain("object-src 'none'");
    expect(csp).toContain("frame-ancestors 'none'");
    expect(csp).toContain("form-action 'self'");
    expect(csp).not.toMatch(/unsafe-inline|unsafe-eval|\*|https?:/);
    expect(res.headers()['referrer-policy']).toBe('origin');

    const html = await res.text();
    expect(html).toContain('<meta name="referrer" content="origin"');
    expect(html, 'inline script').not.toMatch(/<script(?![^>]*\bsrc=)[^>]*>\s*\S/i);
    expect(html, 'inline style').not.toMatch(/<style|\sstyle=/i);
  });

  test('the CSP watcher really catches a violation (and the app itself triggers none)', async ({ page, csp }) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
    expect(csp.violations, 'the login page itself is clean').toEqual([]);

    csp.allow(/script-src\S* blocked inline|Executing inline script violates/); // provoked on purpose below (event and console form)
    const ran = await page.evaluate(() => {
      const s = document.createElement('script');
      s.textContent = 'window.__injected = true';
      document.head.append(s);
      return (window as unknown as { __injected?: boolean }).__injected === true;
    });
    expect(ran, 'inline script must not execute under the enforced CSP').toBe(false);
    await expect.poll(() => csp.expected.length, { message: 'the watcher saw the violation' }).toBeGreaterThan(0);
    expect(csp.expected.join('\n')).toMatch(/script-src/);
  });

  test('the session cookie still works behind the strict headers (login then reload)', async ({ browser }) => {
    const { context: ctx, page } = await actor(browser, 'customer');
    try {
      await page.goto('/orders');
      await expect(page.getByRole('heading', { name: /^Orders for/ })).toBeVisible();
      await page.reload(); // a same-site GET sends only Referer: "origin" keeps it stateful
      await expect(page.getByRole('heading', { name: /^Orders for/ })).toBeVisible();
    } finally {
      await ctx.close();
    }
  });
});
