import * as fs from 'node:fs';
import * as path from 'node:path';
import { type Locator, type Page } from '@playwright/test';
import { expect, test } from '../helpers/fixtures';
import { actor } from '../helpers/api';
import { addToCart, apiCustomer, clearCart } from '../helpers/data';
import { E2E_DIR, WEB_URL } from '../helpers/env';
import { watchCsp } from '../helpers/csp';
import { describeViolations, isBlocking, scan } from '../helpers/axe';
import { createWorld, type World } from './world';

/**
 * The parts of the manual accessibility checklist (docs/phase-7-design.md 7.3) that a script can verify:
 * skip link and landmarks, focus after navigation, unique titles, dialog focus trap / Escape / return of focus, keyboard reach and
 * visible focus, no horizontal scroll at 375 / 320 / 640 CSS px (200 % and 400 % zoom reflow), reduced motion, language.
 * What a script cannot judge (screen reader wording, colour in forced-colors mode, reading order sense) stays manual.
 */

let world: World;
const REPORT = path.join(E2E_DIR, 'a11y-reports', 'manual-checks.json');
const findings: Record<string, unknown> = {};
const note = (key: string, value: unknown) => {
  findings[key] = value;
};

test.beforeAll(async () => {
  fs.mkdirSync(path.dirname(REPORT), { recursive: true });
  world = await createWorld();
});
test.afterAll(async () => {
  const c = await apiCustomer(); // leave the shared customer's cart empty for the next spec
  await clearCart(c);
  await c.dispose();
  fs.writeFileSync(REPORT, JSON.stringify({ generated: new Date().toISOString(), ...findings }, null, 2));
});

const activeId = (page: Page) => page.evaluate(() => document.activeElement?.id ?? '');
const activeName = (page: Page) =>
  page.evaluate(() => {
    const a = document.activeElement as HTMLElement | null;
    return a ? `${a.tagName.toLowerCase()}|${a.getAttribute('aria-label') ?? a.textContent?.trim().slice(0, 40) ?? ''}` : '';
  });

test.describe('keyboard, landmarks, focus, titles', () => {
  for (const [role, url] of [
    ['guest', '/login'],
    ['customer', '/catalog'],
    ['staff', '/admin'],
    ['admin', '/admin/users'],
    ['staff', '/admin/products'],
    ['admin', '/admin/audit-log'],
  ] as const) {
    test(`skip link is the first Tab stop, activates into main; landmarks are unique and named (${role} ${url})`, async ({ browser }) => {
      const { context, page } =
        role === 'guest'
          ? await (async () => {
              const c = await browser.newContext({ baseURL: WEB_URL });
              await watchCsp(c);
              return { context: c, page: await c.newPage() };
            })()
          : await actor(browser, role);
      try {
        await page.goto(url);
        const h1 = page.getByRole('heading', { level: 1 }).first();
        await expect(h1).toBeVisible();
        // A full page load: nothing has focus, so the very first Tab must land on the skip link (also on /admin pages, whose h1 only takes focus after a client-side navigation).
        await page.keyboard.press('Tab');
        const skip = page.getByRole('link', { name: 'Skip to main content' });
        expect(await skip.evaluate((el) => el === document.activeElement), 'skip link is the first Tab stop').toBe(true);
        await expect(skip).toBeFocused();
        await expect(skip, 'visible on focus').toBeInViewport();
        await page.keyboard.press('Enter');
        expect(await activeId(page), 'focus moves into main').toBe('main-content');
        expect(page.url(), 'no history entry or hash added').not.toContain('#');

        await expect(page.getByRole('main')).toHaveCount(1);
        await expect(page.getByRole('banner')).toHaveCount(1);
        await expect(page.getByRole('contentinfo')).toHaveCount(1);
        const navs = await page.getByRole('navigation').all();
        const names = await Promise.all(navs.map((n) => n.getAttribute('aria-label')));
        expect(names.every(Boolean), `every navigation landmark has a name: ${JSON.stringify(names)}`).toBe(true);
        expect(new Set(names).size, 'navigation names are unique').toBe(names.length);
        if (role === 'staff' || role === 'admin') expect(names).toEqual(expect.arrayContaining(['Main', 'Back office']));
        expect(await page.locator('[tabindex]').evaluateAll((els) => els.filter((e) => Number(e.getAttribute('tabindex')) > 0).length), 'no positive tabindex').toBe(0);
        expect(await page.locator('html').getAttribute('lang')).toBe('en');
      } finally {
        await context.close();
      }
    });
  }

  test('focus and title follow a client-side navigation; every page title is unique', async ({ browser }) => {
    const { context, page } = await actor(browser, 'customer');
    try {
      const titles = new Map<string, string>();
      const visit = async (url: string, h1: RegExp) => {
        await page.goto(url);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(h1);
        const h1Text = (await page.getByRole('heading', { level: 1 }).first().innerText()).replace(/\s+/g, ' ').trim();
        await expect.poll(() => page.title()).toBe(`${h1Text} - B2B Customer Portal`);
        titles.set(url, await page.title());
      };
      await visit('/', /home/i);
      await visit('/catalog', /^Catalog$/);
      await visit('/cart', /^Your cart$/);
      await visit('/orders', /^Orders for/);
      await visit(`/orders/${world.confirmedOrder}`, /^Order ORD-/);
      await visit('/quotes', /^Quotes for/);
      await visit('/quotes/new', /^Request a quote$/);
      await visit(`/quotes/${world.quoteWithFiles}`, /^Quote QUO-/);
      await visit('/invoices', /^Invoices for/);
      await visit(`/invoices/${world.invoiceId}`, /^Invoice INV-/);
      await visit('/no-such-page', /not found/i);
      expect(new Set(titles.values()).size, `unique titles: ${JSON.stringify([...titles])}`).toBe(titles.size);
      note('titles', Object.fromEntries(titles));

      // Client-side navigation by link: the new h1 gets focus and the change is announced in the live region.
      await page.goto('/');
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Orders', exact: true }).click();
      await expect(page.getByRole('heading', { level: 1, name: /^Orders for/ })).toBeFocused();
      await expect(page.getByRole('status', { name: 'Page change announcement' })).toHaveText(/^Orders for/);
      await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Invoices', exact: true }).click();
      await expect(page.getByRole('heading', { level: 1, name: /^Invoices for/ })).toBeFocused();
    } finally {
      await context.close();
    }
  });
});

test.describe('dialogs: initial focus, trap, Escape, return of focus, inert background', () => {
  const cases: { name: string; role: 'customer' | 'staff'; url: () => string; setup?: () => Promise<void>; opener: (p: Page) => Locator; dialog: string | RegExp }[] = [
    {
      name: 'clear cart',
      role: 'customer',
      url: () => '/cart',
      setup: async () => {
        const c = await apiCustomer();
        await clearCart(c);
        await addToCart(c, world.product.id, 1);
        await c.dispose();
      },
      opener: (p) => p.getByRole('button', { name: 'Clear cart' }),
      dialog: 'Clear cart?',
    },
    {
      name: 'accept quote (dialog with an input)',
      role: 'customer',
      url: () => `/quotes/${world.quoteQuoted}`,
      opener: (p) => p.getByRole('button', { name: 'Accept quote' }),
      dialog: 'Accept this quote?',
    },
    {
      name: 'order status change',
      role: 'staff',
      url: () => `/admin/orders/${world.pendingOrder}`,
      opener: (p) => p.getByRole('button', { name: 'Mark as confirmed' }),
      dialog: /^Mark order as/,
    },
    {
      name: 'invoice mark paid (form dialog)',
      role: 'staff',
      url: () => `/admin/invoices/${world.invoiceId}`,
      opener: (p) => p.getByRole('button', { name: 'Mark as paid' }),
      dialog: 'Mark this invoice as paid?',
    },
  ];
  for (const c of cases) {
    test(c.name, async ({ browser }) => {
      await c.setup?.();
      const { context, page } = await actor(browser, c.role);
      try {
        await page.goto(c.url());
        const opener = c.opener(page);
        await expect(opener).toBeVisible();
        await opener.focus();
        await page.keyboard.press('Enter'); // keyboard opens it
        const dialog = page.getByRole('dialog', { name: c.dialog });
        await expect(dialog).toBeVisible();
        expect(await dialog.getAttribute('aria-modal')).toBe('true');
        await expect(dialog.getByRole('button').last(), 'initial focus on the safe (cancel) button').toBeFocused();

        // Tab and Shift+Tab never leave the dialog.
        const inside = () => page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]'));
        for (let i = 0; i < 12; i++) {
          await page.keyboard.press('Tab');
          expect(await inside(), `Tab ${i + 1} stays in the dialog`).toBe(true);
        }
        for (let i = 0; i < 12; i++) {
          await page.keyboard.press('Shift+Tab');
          expect(await inside(), `Shift+Tab ${i + 1} stays in the dialog`).toBe(true);
        }
        // The background is inert: nothing behind the dialog is reachable.
        expect(await page.evaluate(() => [...document.body.children].filter((e) => !e.hasAttribute('inert') && !e.querySelector('[role="dialog"]') && !e.matches('[role="dialog"]')).length)).toBe(0);

        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(opener, 'focus returns to the opener').toBeFocused();
        // Enter and Space work on buttons: Space re-opens it.
        await page.keyboard.press('Space');
        await expect(dialog).toBeVisible();
        await page.keyboard.press('Escape');
      } finally {
        await context.close();
      }
    });
  }
});

/**
 * Walks the Tab order until it wraps around (at most `limit` stops) and reports which visible interactive controls were never
 * reached and which focused controls show neither an outline nor a box-shadow ring.
 */
async function tabWalk(page: Page, key: 'Tab' | 'Shift+Tab' = 'Tab', limit = 150) {
  const total = await page.evaluate(() => {
    const sel = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), summary';
    const visible = (e: Element) => {
      const r = (e as HTMLElement).getBoundingClientRect();
      return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden' && !e.closest('[inert]');
    };
    const all = [...document.querySelectorAll(sel)].filter(visible);
    (window as unknown as { __tabTargets: Element[] }).__tabTargets = all;
    return all.length;
  });
  const seen = new Set<number>();
  const others: string[] = [];
  const noFocusStyle: string[] = [];
  for (let i = 0; i < limit; i++) {
    await page.keyboard.press(key);
    const r = await page.evaluate(() => {
      const a = document.activeElement as HTMLElement | null;
      if (!a || a === document.body) return { idx: -2, ring: true, label: '' };
      const idx = (window as unknown as { __tabTargets: Element[] }).__tabTargets.indexOf(a);
      const cs = getComputedStyle(a);
      const ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) || cs.boxShadow !== 'none';
      return { idx, ring, label: `${a.tagName.toLowerCase()}[${(a.getAttribute('aria-label') ?? a.textContent ?? '').trim().slice(0, 40)}]` };
    });
    if (r.idx === -2) break; // focus left the document: one full cycle
    if (r.idx >= 0) {
      if (seen.has(r.idx)) break; // wrapped around
      seen.add(r.idx);
    } else others.push(r.label);
    if (!r.ring) noFocusStyle.push(r.label);
  }
  const missed = await page.evaluate((reached) => {
    const targets = (window as unknown as { __tabTargets: Element[] }).__tabTargets;
    return targets.map((e, i) => [e, i] as const).filter(([, i]) => !reached.includes(i)).map(([e]) => `${e.tagName.toLowerCase()}[${(e.getAttribute('aria-label') ?? e.textContent ?? '').trim().slice(0, 40)}]`);
  }, [...seen]);
  return { total, reached: seen.size, notInTargetList: others, missed, noFocusStyle };
}

test.describe('keyboard reach and visible focus (Tab walk)', () => {
  const pages: { name: string; role: 'customer' | 'staff'; url: () => string; setup?: () => Promise<void> }[] = [
    { name: 'catalog', role: 'customer', url: () => '/catalog' },
    {
      name: 'cart',
      role: 'customer',
      url: () => '/cart',
      setup: async () => {
        const c = await apiCustomer();
        await clearCart(c);
        await addToCart(c, world.product.id, 2);
        await c.dispose();
      },
    },
    {
      name: 'checkout',
      role: 'customer',
      url: () => '/checkout',
      setup: async () => {
        const c = await apiCustomer();
        await clearCart(c);
        await addToCart(c, world.product.id, 2);
        await c.dispose();
      },
    },
    { name: 'quote form', role: 'customer', url: () => '/quotes/new' },
    { name: 'order detail', role: 'customer', url: () => `/orders/${world.confirmedOrder}` },
    { name: 'invoice detail', role: 'customer', url: () => `/invoices/${world.invoiceId}` },
    { name: 'admin product form', role: 'staff', url: () => `/admin/products/${world.product.id}` },
    { name: 'admin import', role: 'staff', url: () => '/admin/products/import' },
    { name: 'admin order detail with status buttons', role: 'staff', url: () => `/admin/orders/${world.pendingOrder}` },
  ];
  for (const p of pages) {
    test(p.name, async ({ browser }) => {
      await p.setup?.();
      const { context, page } = await actor(browser, p.role);
      try {
        await page.goto(p.url());
        await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
        if (p.name === 'catalog') await expect(page.getByRole('table', { name: 'Product search results' })).toBeVisible();
        const walk = await tabWalk(page);
        note(`tabWalk:${p.name}`, walk);
        expect.soft(walk.missed, `controls the Tab key never reached (${walk.reached} of ${walk.total} reached)`).toEqual([]);
        expect.soft(walk.noFocusStyle, 'focused controls without an outline or ring').toEqual([]);
      } finally {
        await context.close();
      }
    });
  }
});

/**
 * Documented expected failures (owner: frontend-engineer). Key "<viewport width>:<page>"; the value is the measured scrollWidth and the cause.
 * The check below still fails for any other overflow, and ALSO fails when a listed one is gone (delete the entry then). Target: empty.
 */
// Pages with a documented, not-yet-fixed overflow (page key -> cause and fix). Empty: every page fits at 320/375/640 px.
// The test fails on any overflow that is not listed here and on any listed entry that no longer overflows.
const KNOWN_OVERFLOW: Record<string, string> = {};

test.describe('layout: no horizontal page scroll (375 / 320 px, 200 % and 400 % zoom reflow)', () => {
  const pages: { name: string; role: 'customer' | 'staff' | 'admin'; url: () => string }[] = [
    { name: 'catalog', role: 'customer', url: () => '/catalog' },
    { name: 'cart', role: 'customer', url: () => '/cart' },
    { name: 'orders', role: 'customer', url: () => '/orders' },
    { name: 'order detail', role: 'customer', url: () => `/orders/${world.confirmedOrder}` },
    { name: 'quotes', role: 'customer', url: () => '/quotes' },
    { name: 'quote form', role: 'customer', url: () => '/quotes/new' },
    { name: 'quote detail', role: 'customer', url: () => `/quotes/${world.quoteWithFiles}` },
    { name: 'invoices', role: 'customer', url: () => '/invoices' },
    { name: 'invoice detail', role: 'customer', url: () => `/invoices/${world.invoiceId}` },
    { name: 'admin dashboard', role: 'staff', url: () => '/admin' },
    { name: 'admin orders', role: 'staff', url: () => '/admin/orders' },
    { name: 'admin products', role: 'staff', url: () => '/admin/products' },
    { name: 'admin product form', role: 'staff', url: () => `/admin/products/${world.product.id}` },
    { name: 'admin import', role: 'staff', url: () => '/admin/products/import' },
    { name: 'admin quote pricing', role: 'staff', url: () => `/admin/quotes/${world.quoteInReview}` },
    { name: 'admin audit log', role: 'admin', url: () => '/admin/audit-log' },
    { name: 'checkout', role: 'customer', url: () => '/checkout' },
    { name: 'admin quotes', role: 'staff', url: () => '/admin/quotes' },
    { name: 'admin order detail', role: 'staff', url: () => `/admin/orders/${world.confirmedOrder}` },
    { name: 'admin invoices', role: 'staff', url: () => '/admin/invoices' },
    { name: 'admin invoice detail', role: 'staff', url: () => `/admin/invoices/${world.invoiceId}` },
    { name: 'admin companies', role: 'staff', url: () => '/admin/companies' },
    { name: 'admin company detail', role: 'staff', url: () => `/admin/companies/${world.companyId}` },
    { name: 'admin users', role: 'admin', url: () => '/admin/users' },
  ];
  const widths = [
    { label: '375 px (phone)', width: 375, height: 812 },
    { label: '320 px (WCAG reflow, 400 % zoom of 1280)', width: 320, height: 640 },
    { label: '640 px (200 % zoom of 1280)', width: 640, height: 512 },
  ];
  test('no horizontal page scroll at 375 px, 320 px and 640 px', async ({ browser }) => {
    const overflow: string[] = [];
    const c = await apiCustomer(); // a filled cart, so the cart table is measured too
    await clearCart(c);
    await addToCart(c, world.product.id, 2);
    await c.dispose();
    for (const role of ['customer', 'staff', 'admin'] as const) {
      const { context, page } = await actor(browser, role);
      try {
        for (const p of pages.filter((x) => x.role === role)) {
          await page.goto(p.url());
          await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
          if (/catalog|orders$|quotes$|invoices$|products$|companies$|users$|audit/.test(p.name)) await expect(page.getByRole('table').first().or(page.getByText(/no .* (found|yet|match)/i).first())).toBeVisible();
          // The layout is pure CSS (media queries): resizing the viewport re-flows the loaded page, no reload needed.
          for (const w of widths) {
            await page.setViewportSize({ width: w.width, height: w.height });
            await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))); // one layout pass
            const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
            if (m.sw > m.iw) {
              const wide = await page.evaluate((iw) => [...document.querySelectorAll('body *')].filter((e) => e.getBoundingClientRect().right > iw + 1 && !e.closest('[class*="overflow-x"]') && !e.closest('.sr-only')).slice(0, 3).map((e) => `${e.tagName.toLowerCase()}.${e.className?.toString().slice(0, 50)}`), m.iw);
              overflow.push(`${w.width}:${p.name}: scrollWidth ${m.sw} > ${m.iw} (${wide.join(' | ')})`);
            }
          }
          await page.setViewportSize({ width: 1280, height: 720 });
        }
      } finally {
        await context.close();
      }
    }
    note('overflow', overflow);
    const key = (o: string) => o.split(': scrollWidth')[0];
    const fresh = overflow.filter((o) => !(key(o) in KNOWN_OVERFLOW));
    expect(fresh, 'pages with horizontal page scroll').toEqual([]);
    const fixed = Object.keys(KNOWN_OVERFLOW).filter((k) => !overflow.some((o) => key(o) === k));
    expect(fixed, 'known overflow that no longer happens: delete it from KNOWN_OVERFLOW').toEqual([]);
    if (overflow.length) test.info().annotations.push({ type: 'KNOWN DEFECT (frontend-engineer)', description: overflow.map((o) => `${o.split(' (')[0]} -> ${KNOWN_OVERFLOW[key(o)]}`).join('\n') });
  });

  test('axe at 375 px (target size, reflow, contrast on the stacked layout)', async ({ browser }) => {
    const problems: string[] = [];
    for (const [role, url] of [
      ['customer', '/catalog'],
      ['customer', `/orders/${world.confirmedOrder}`],
      ['customer', `/invoices/${world.invoiceId}`],
      ['staff', '/admin/products'],
      ['staff', `/admin/quotes/${world.quoteInReview}`],
    ] as const) {
      const { context, page } = await actor(browser, role);
      try {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto(url);
        await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
        const r = await scan(page);
        const bad = r.violations.filter(isBlocking);
        if (bad.length) problems.push(`${url}\n${describeViolations(bad)}`);
      } finally {
        await context.close();
      }
    }
    expect(problems.join('\n')).toBe('');
  });
});

test.describe('reduced motion: no loading skeleton animates', () => {
  // Every page keeps its data requests pending (all of /api/v1 except the session lookup) so the loading skeleton stays on screen.
  const pages: { name: string; role: 'customer' | 'staff' | 'admin'; url: () => string }[] = [
    { name: 'catalog', role: 'customer', url: () => '/catalog' },
    { name: 'cart', role: 'customer', url: () => '/cart' },
    { name: 'checkout', role: 'customer', url: () => '/checkout' },
    { name: 'orders', role: 'customer', url: () => '/orders' },
    { name: 'order detail', role: 'customer', url: () => `/orders/${world.confirmedOrder}` },
    { name: 'quotes', role: 'customer', url: () => '/quotes' },
    { name: 'quote detail', role: 'customer', url: () => `/quotes/${world.quoteWithFiles}` },
    { name: 'invoices', role: 'customer', url: () => '/invoices' },
    { name: 'invoice detail', role: 'customer', url: () => `/invoices/${world.invoiceId}` },
    { name: 'product detail', role: 'customer', url: () => `/catalog/${world.product.id}` },
    { name: 'admin dashboard', role: 'staff', url: () => '/admin' },
    { name: 'admin product form', role: 'staff', url: () => `/admin/products/${world.product.id}` },
    { name: 'admin company detail', role: 'staff', url: () => `/admin/companies/${world.companyId}` },
    { name: 'admin audit log', role: 'admin', url: () => '/admin/audit-log' },
    { name: 'admin user detail', role: 'admin', url: () => `/admin/users/${world.userId}` },
  ];
  const holdData = async (page: Page) => {
    let release: () => void = () => undefined;
    const gate = new Promise<void>((r) => (release = r));
    await page.route(/\/api\/v1\/(?!me\b|auth\/)/, async (route) => {
      await gate;
      await route.continue().catch(() => undefined);
    });
    return release;
  };
  const pulseAnimations = (page: Page) => page.locator('[class*="animate-pulse"]').evaluateAll((els) => els.map((e) => getComputedStyle(e).animationName));

  test('control: without the preference the catalog skeleton does pulse (the check can see an animation)', async ({ browser }) => {
    const { context, page } = await actor(browser, 'customer');
    try {
      await page.emulateMedia({ reducedMotion: 'no-preference' });
      const release = await holdData(page);
      await page.goto('/catalog');
      await expect(page.locator('[class*="animate-pulse"]').first()).toBeVisible();
      expect(await pulseAnimations(page)).toContain('pulse');
      release();
    } finally {
      await context.close();
    }
  });

  for (const p of pages) {
    test(`${p.role} ${p.name}`, async ({ browser }) => {
      const { context, page } = await actor(browser, p.role);
      try {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        const release = await holdData(page);
        await page.goto(p.url());
        await expect(page.locator('[class*="animate-pulse"]').first(), 'a loading skeleton is on screen').toBeVisible();
        const animations = await pulseAnimations(page);
        note(`reducedMotion:${p.url()}`, animations);
        release();
        expect(animations.length).toBeGreaterThan(0);
        expect(animations.every((a) => a === 'none'), `skeleton animation under prefers-reduced-motion: ${animations.join(',')}`).toBe(true);
      } finally {
        await context.close();
      }
    });
  }
});
