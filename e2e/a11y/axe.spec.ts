import * as fs from 'node:fs';
import * as path from 'node:path';
import { type Browser, type Page } from '@playwright/test';
import { expect, test } from '../helpers/fixtures';
import { actor } from '../helpers/api';
import { addToCart, apiCustomer, clearCart } from '../helpers/data';
import { E2E_DIR, WEB_URL } from '../helpers/env';
import { watchCsp } from '../helpers/csp';
import { describeViolations, isBlocking, scan, type Result } from '../helpers/axe';
import { createWorld, type World } from './world';

/**
 * axe-core over every page of docs/phase-7-design.md 7.2, for each role that can see it, with WCAG 2.0/2.1/2.2 A and AA plus the
 * best-practice rules. Any serious or critical violation fails the test (no waivers); moderate and
 * minor ones are listed in the report. JSON per page and one summary go to e2e/a11y-reports/ (git-ignored, a CI artifact).
 * Pages are scanned when settled (a heading, table or message is awaited, never a timer); dialogs are scanned open.
 */

const REPORT_DIR = path.join(E2E_DIR, 'a11y-reports', 'axe');
type Role = 'guest' | 'customer' | 'staff' | 'admin';

interface Entry {
  name: string;
  role: Role;
  path: (w: World) => string;
  /** Wait until the page is settled; may also put the page into the state to scan (open a dialog, submit empty). */
  ready: (page: Page, w: World) => Promise<void>;
  /** Runs before navigation (data changes through the API). */
  setup?: (w: World) => Promise<void>;
}

const heading = (name: string | RegExp, level = 1) => async (page: Page) => {
  await expect(page.getByRole('heading', { name, level }).first()).toBeVisible();
};
const table = (name: string) => async (page: Page) => {
  await expect(page.getByRole('table', { name })).toBeVisible();
};
const all = (...fns: ((p: Page, w: World) => Promise<void>)[]) => async (page: Page, w: World) => {
  for (const f of fns) await f(page, w);
};

/** Every cart page sets up its own cart, so a retried test does not depend on the one before it. */
const withCart = async (w: World) => {
  const c = await apiCustomer();
  await clearCart(c);
  await addToCart(c, w.product.id, 3);
  await c.dispose();
};

const PAGES: Entry[] = [
  // ---- guest ----
  { name: 'guest-login', role: 'guest', path: () => '/login', ready: heading('Sign in') },
  {
    name: 'guest-login-errors',
    role: 'guest',
    path: () => '/login',
    ready: async (page) => {
      await page.getByRole('button', { name: 'Sign in' }).click();
      await expect(page.getByText('Email is required')).toBeVisible();
    },
  },
  { name: 'guest-forgot-password', role: 'guest', path: () => '/forgot-password', ready: heading(/forgot|reset/i) },
  { name: 'guest-reset-password', role: 'guest', path: () => '/reset-password?token=x&email=a%40b.test', ready: heading(/password/i) },
  { name: 'guest-accept-invitation', role: 'guest', path: () => '/accept-invitation?token=x&email=a%40b.test', ready: heading(/invitation|account|password/i) },
  { name: 'guest-not-found', role: 'guest', path: () => '/no-such-page', ready: heading(/not found/i) },

  // ---- customer ----
  { name: 'customer-home', role: 'customer', path: () => '/', ready: heading(/./) },
  { name: 'customer-catalog', role: 'customer', path: () => '/catalog', ready: all(heading('Catalog'), table('Product search results')) },
  {
    name: 'customer-catalog-filtered',
    role: 'customer',
    path: (w) => `/catalog?q=${w.product.part_number}&brand=${w.product.brand}`,
    ready: all(heading('Catalog'), async (page, w) => {
      await expect(page.getByRole('link', { name: w.product.part_number, exact: true })).toBeVisible();
    }),
  },
  {
    name: 'customer-catalog-empty',
    role: 'customer',
    path: () => '/catalog?q=ZZZZZZZZ9',
    ready: async (page) => {
      await expect(page.getByText('No products match your search.')).toBeVisible();
    },
  },
  { name: 'customer-product-detail', role: 'customer', path: (w) => `/catalog/${w.product.id}`, ready: heading(/./) },
  {
    name: 'customer-cart-empty',
    role: 'customer',
    path: () => '/cart',
    setup: async () => {
      const c = await apiCustomer();
      await clearCart(c);
      await c.dispose();
    },
    ready: async (page) => {
      await expect(page.getByText('Your cart is empty.')).toBeVisible();
    },
  },
  {
    name: 'customer-cart',
    role: 'customer',
    path: () => '/cart',
    setup: withCart,
    ready: table('Items in your cart'),
  },
  {
    name: 'customer-cart-clear-dialog',
    role: 'customer',
    path: () => '/cart',
    setup: withCart,
    ready: async (page) => {
      await table('Items in your cart')(page);
      await page.getByRole('button', { name: 'Clear cart' }).click();
      await expect(page.getByRole('dialog', { name: 'Clear cart?' })).toBeVisible();
    },
  },
  { name: 'customer-checkout', role: 'customer', path: () => '/checkout', setup: withCart, ready: all(heading('Checkout'), table('Order review')) },
  { name: 'customer-orders', role: 'customer', path: () => '/orders', ready: all(heading(/^Orders for/), table('Orders')) },
  { name: 'customer-order-detail', role: 'customer', path: (w) => `/orders/${w.confirmedOrder}`, ready: heading(/^Order ORD-/) },
  { name: 'customer-quotes', role: 'customer', path: () => '/quotes', ready: all(heading(/^Quotes for/), table('Quotes')) },
  { name: 'customer-quote-form', role: 'customer', path: () => '/quotes/new', ready: heading('Request a quote') },
  {
    name: 'customer-quote-form-picker-and-errors',
    role: 'customer',
    path: () => '/quotes/new',
    ready: async (page) => {
      await heading('Request a quote')(page);
      await page.getByRole('button', { name: 'Add catalog item' }).click();
      await expect(page.getByLabel('Search the catalog')).toBeVisible();
      await page.getByRole('button', { name: 'Add custom item' }).click();
      await page.getByRole('button', { name: 'Submit quote request' }).click();
      await expect(page.getByRole('group', { name: 'Please fix the following' })).toBeVisible();
    },
  },
  { name: 'customer-quote-detail-attachments', role: 'customer', path: (w) => `/quotes/${w.quoteWithFiles}`, ready: all(heading(/^Quote QUO-/), async (page) => {
      await expect(page.getByRole('link', { name: 'drawing.pdf' })).toBeVisible();
    }) },
  {
    name: 'customer-quote-accept-dialog',
    role: 'customer',
    path: (w) => `/quotes/${w.quoteQuoted}`,
    ready: async (page) => {
      await heading(/^Quote QUO-/)(page);
      await page.getByRole('button', { name: 'Accept quote' }).click();
      await expect(page.getByRole('dialog', { name: 'Accept this quote?' })).toBeVisible();
    },
  },
  { name: 'customer-invoices', role: 'customer', path: () => '/invoices', ready: all(heading(/^Invoices for/), table('Invoices')) },
  { name: 'customer-invoice-detail', role: 'customer', path: (w) => `/invoices/${w.invoiceId}`, ready: heading(/^Invoice INV-/) },
  {
    name: 'customer-not-allowed-admin',
    role: 'customer',
    path: () => '/admin',
    ready: heading('Not allowed'),
  },

  // ---- staff ----
  { name: 'staff-dashboard', role: 'staff', path: () => '/admin', ready: all(heading('Dashboard'), async (page) => {
      await expect(page.getByRole('link', { name: /^Pending orders/ })).toBeVisible();
    }) },
  { name: 'staff-orders', role: 'staff', path: () => '/admin/orders', ready: all(heading('Orders'), table('Orders')) },
  { name: 'staff-order-detail', role: 'staff', path: (w) => `/admin/orders/${w.confirmedOrder}`, ready: heading(/^Order ORD-/) },
  {
    name: 'staff-order-status-dialog',
    role: 'staff',
    path: (w) => `/admin/orders/${w.pendingOrder}`,
    ready: async (page) => {
      await heading(/^Order ORD-/)(page);
      await page.getByRole('button', { name: 'Mark as confirmed' }).click();
      await expect(page.getByRole('dialog')).toBeVisible();
    },
  },
  { name: 'staff-quotes', role: 'staff', path: () => '/admin/quotes?status=submitted,in_review,quoted', ready: all(heading('Quotes'), table('Quotes')) },
  {
    name: 'staff-quote-pricing-form',
    role: 'staff',
    path: (w) => `/admin/quotes/${w.quoteInReview}`,
    ready: all(heading(/^Quote QUO-/), async (page) => {
      await expect(page.getByRole('textbox', { name: 'Unit price for item 1' })).toBeVisible();
    }),
  },
  { name: 'staff-quote-detail-attachments', role: 'staff', path: (w) => `/admin/quotes/${w.quoteWithFiles}`, ready: all(heading(/^Quote QUO-/), async (page) => {
      await expect(page.getByRole('link', { name: 'parts.csv' })).toBeVisible();
    }) },
  { name: 'staff-invoices', role: 'staff', path: () => '/admin/invoices?status=open,overdue,paid', ready: all(heading('Invoices'), table('Invoices')) },
  {
    name: 'staff-invoice-detail-mark-paid-dialog',
    role: 'staff',
    path: (w) => `/admin/invoices/${w.invoiceId}`,
    ready: async (page) => {
      await heading(/^Invoice INV-/)(page);
      await page.getByRole('button', { name: 'Mark as paid' }).click();
      await expect(page.getByRole('dialog', { name: 'Mark this invoice as paid?' })).toBeVisible();
    },
  },
  { name: 'staff-products', role: 'staff', path: () => '/admin/products', ready: all(heading('Products'), table('Products')) },
  { name: 'staff-product-new', role: 'staff', path: () => '/admin/products/new', ready: heading('New product') },
  { name: 'staff-product-edit', role: 'staff', path: (w) => `/admin/products/${w.product.id}`, ready: heading(/^Product /) },
  { name: 'staff-product-import-step1', role: 'staff', path: () => '/admin/products/import', ready: heading(/step 1 of 3/) },
  {
    name: 'staff-product-import-preview',
    role: 'staff',
    path: () => '/admin/products/import',
    ready: async (page) => {
      await heading(/step 1 of 3/)(page);
      const csv = [
        'part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,stock_status,price_cents',
        'AX-PREVIEW-1,Preview bearing,AxeBrand,deep-groove-ball,10,30,9,Chrome steel,open,in_stock,1000',
        'AX-PREVIEW-BAD,Bad bearing,AxeBrand,deep-groove-ball,40,30,9,Chrome steel,open,in_stock,1000',
      ].join('\n');
      await page.getByLabel('CSV file').setInputFiles({ name: 'axe.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf8') });
      await page.getByRole('button', { name: 'Upload and check' }).click();
      await expect(page.getByRole('heading', { level: 1 })).toContainText('step 2 of 3');
      await expect(page.getByRole('table', { name: 'Invalid rows' })).toBeVisible();
    },
  },
  { name: 'staff-companies', role: 'staff', path: () => '/admin/companies', ready: all(heading('Companies'), table('Companies')) },
  { name: 'staff-company-detail', role: 'staff', path: (w) => `/admin/companies/${w.companyId}`, ready: async (page) => {
      await expect(page.getByLabel('Billing address')).toBeVisible();
    } },

  // ---- admin ----
  { name: 'admin-users', role: 'admin', path: () => '/admin/users', ready: all(heading('Users'), table('Users')) },
  {
    name: 'admin-users-invite-dialog',
    role: 'admin',
    path: () => '/admin/users',
    ready: async (page) => {
      await table('Users')(page);
      await page.getByRole('button', { name: 'Invite user' }).click();
      await expect(page.getByRole('dialog', { name: 'Invite user' })).toBeVisible();
    },
  },
  { name: 'admin-user-detail', role: 'admin', path: (w) => `/admin/users/${w.userId}`, ready: heading(/./) },
  { name: 'admin-audit-log', role: 'admin', path: () => '/admin/audit-log', ready: all(heading('Audit log'), table('Audit log')) },
];

interface Row {
  page: string;
  role: Role;
  url: string;
  critical: number;
  serious: number;
  moderate: number;
  minor: number;
}
const summary: Row[] = [];

async function open(browser: Browser, role: Role) {
  if (role === 'guest') {
    const context = await browser.newContext({ baseURL: WEB_URL });
    await watchCsp(context);
    return { context, page: await context.newPage() };
  }
  return actor(browser, role);
}

const toJson = (v: Result) => ({
  id: v.id,
  impact: v.impact,
  help: v.help,
  helpUrl: v.helpUrl,
  tags: v.tags,
  nodes: v.nodes.map((n) => ({ target: n.target, html: n.html.slice(0, 300), summary: n.failureSummary?.slice(0, 400) })),
});

test.describe('axe: every page, WCAG 2 A/AA + best practice', () => {
  let world: World;
  test.beforeAll(async () => {
    fs.rmSync(REPORT_DIR, { recursive: true, force: true });
    fs.mkdirSync(REPORT_DIR, { recursive: true });
    world = await createWorld();
  });
  test.afterAll(async () => {
    const c = await apiCustomer(); // the cart pages left lines in the shared customer's cart
    await clearCart(c);
    await c.dispose();
    summary.sort((a, b) => a.page.localeCompare(b.page));
    fs.writeFileSync(
      path.join(REPORT_DIR, 'summary.json'),
      JSON.stringify({ generated: new Date().toISOString(), tags: 'wcag2a wcag2aa wcag21a wcag21aa wcag22aa best-practice', pages: summary }, null, 2),
    );
  });

  for (const entry of PAGES) {
    test(`${entry.name}`, async ({ browser }, testInfo) => {
      await entry.setup?.(world);
      const { context, page } = await open(browser, entry.role);
      try {
        const url = entry.path(world);
        await page.goto(url);
        await entry.ready(page, world);
        const results = await scan(page);
        const finalPath = new URL(page.url()).pathname;

        const byImpact = (i: string) => results.violations.filter((v) => v.impact === i);
        const blocking = results.violations.filter(isBlocking);
        summary.push({
          page: entry.name,
          role: entry.role,
          url: finalPath,
          critical: byImpact('critical').length,
          serious: byImpact('serious').length,
          moderate: byImpact('moderate').length,
          minor: byImpact('minor').length,
        });
        fs.writeFileSync(
          path.join(REPORT_DIR, `${entry.name}.json`),
          JSON.stringify({ page: entry.name, role: entry.role, url: page.url(), violations: results.violations.map(toJson), passes: results.passes.length, incomplete: results.incomplete.map((v) => v.id) }, null, 2),
        );
        const minor = [...byImpact('moderate'), ...byImpact('minor')];
        if (minor.length) testInfo.annotations.push({ type: 'moderate/minor', description: describeViolations(minor) });
        expect(blocking, `serious/critical violations on ${entry.name} (${finalPath}):\n${describeViolations(blocking)}`).toEqual([]);
      } finally {
        await context.close();
      }
    });
  }
});
