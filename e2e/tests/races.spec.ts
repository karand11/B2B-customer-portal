import { type APIResponse } from '@playwright/test';
import { expect, test } from '../helpers/fixtures';
import { actor, getJson, send, sendForm } from '../helpers/api';
import { addToCart, apiAs, apiCustomer, clearCart, createProduct, orderCount, stamp, utcDate } from '../helpers/data';
import { apiResponse } from '../helpers/ui';

/**
 * Races (docs/phase-7-design.md 8.4, S35). Real concurrent requests through the Vite proxy with the Origin header and the CSRF token
 * Sanctum needs. The assertions are about the OUTCOME that must hold whatever the interleaving: caps never exceeded, exactly one winner.
 * Note: on Windows `php -S` is single-threaded (PHP_CLI_SERVER_WORKERS is Linux only), so the server serialises the requests and the
 * lock paths are not interleaved there; on Linux (CI) four workers really run in parallel. The real-process races live in Pest.
 */

const statuses = (rs: APIResponse[]) => rs.map((r) => r.status()).sort((a, b) => a - b);
const errorKeys = async (r: APIResponse) => Object.keys((await r.json()).errors ?? {});

test.describe('races', () => {
  test('12 parallel uploads on one quote: at most 10 files are stored, every refusal is a 422 on "file"', async () => {
    const customer = await apiCustomer();
    const staff = await apiAs('staff');
    try {
      const created = await send(customer, 'POST', '/api/v1/quotes', {
        po_reference: `RACE-U-${stamp()}`,
        lines: [{ description: 'Race upload bearing', quantity: 1 }],
      });
      expect(created.status(), await created.text()).toBe(201);
      const number = (await created.json()).data.number as string;

      const uploads = Array.from({ length: 12 }, (_, i) =>
        sendForm(customer, `/api/v1/quotes/${number}/attachments`, {
          file: { name: `race-${i}.txt`, mimeType: 'text/plain', buffer: Buffer.from(`parallel upload number ${i} for the per-quote cap\n`.repeat(20)) },
        }),
      );
      const results = await Promise.all(uploads);
      const ok = results.filter((r) => r.status() === 201);
      const refused = results.filter((r) => r.status() !== 201);
      expect(statuses(results), 'only 201 and 422 are possible').toEqual([...Array(ok.length).fill(201), ...Array(refused.length).fill(422)]);
      expect(ok.length, 'the cap is 10 files per quote').toBe(10);
      for (const r of refused) expect(await errorKeys(r)).toEqual(['file']);

      const detail = (await getJson(customer, `/api/v1/quotes/${number}`)).data;
      expect(detail.attachments.length, 'stored files').toBe(10);
      expect(new Set(detail.attachments.map((a: { id: string }) => a.id)).size).toBe(10);
      // Staff sees the same count (one source of truth), and one more upload stays refused.
      expect((await getJson(staff, `/api/v1/quotes/${number}`)).data.attachments.length).toBe(10);
      const eleventh = await sendForm(customer, `/api/v1/quotes/${number}/attachments`, {
        file: { name: 'late.txt', mimeType: 'text/plain', buffer: Buffer.from('one more file after the cap\n'.repeat(10)) },
      });
      expect(eleventh.status()).toBe(422);
    } finally {
      await Promise.all([customer.dispose(), staff.dispose()]);
    }
  });

  test('two parallel CSV import applies: exactly one wins, the product is created once, one audit row', async () => {
    const staff = await apiAs('staff');
    const admin = await apiAs('admin');
    try {
      const cat = (await getJson(staff, '/api/v1/categories')).data[0];
      const part = `RACE-I-${stamp()}`;
      const csv = [
        'part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,stock_status,price_cents',
        `${part},Race import bearing,RaceBrand,${cat.slug},10,30,9,Chrome steel,open,in_stock,1500`,
      ].join('\n');
      const preview = await sendForm(staff, '/api/v1/admin/products/imports', {
        file: { name: 'race.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf8') },
      });
      expect(preview.status(), await preview.text()).toBe(201);
      const importId = (await preview.json()).data.id as string;

      const [a, b] = await Promise.all([0, 1].map(() => send(staff, 'POST', `/api/v1/admin/products/imports/${importId}/apply`, {})));
      expect(statuses([a, b])).toEqual([200, 422]);
      const loser = a.status() === 422 ? a : b;
      expect(await errorKeys(loser)).toEqual(['import']);

      expect((await getJson(staff, `/api/v1/admin/products?q=${part}`)).meta.total, 'created exactly once').toBe(1);
      const audit = (await getJson(admin, `/api/v1/admin/audit-log?subject_type=import&subject_id=${importId}`)).data;
      expect(audit.map((r: { action: string }) => r.action)).toEqual(['product.imported']);
      // A third apply later is refused too.
      expect((await send(staff, 'POST', `/api/v1/admin/products/imports/${importId}/apply`, {})).status()).toBe(422);
    } finally {
      await Promise.all([staff.dispose(), admin.dispose()]);
    }
  });

  test('five parallel checkouts of one cart: exactly one order, the others are 422 "cart"', async () => {
    const customer = await apiCustomer();
    const staff = await apiAs('staff');
    try {
      const product = await createProduct(staff, { prefix: 'R-O', price_cents: 1500 });
      await clearCart(customer);
      await addToCart(customer, product.id, 2);
      const po = `RACE-O-${stamp()}`;
      const before = await orderCount(customer);

      const results = await Promise.all(
        Array.from({ length: 5 }, () => send(customer, 'POST', '/api/v1/orders', { po_reference: po, expected_subtotal_cents: 3000 })),
      );
      expect(statuses(results)).toEqual([201, 422, 422, 422, 422]);
      for (const r of results.filter((x) => x.status() === 422)) expect(await errorKeys(r)).toEqual(['cart']);

      expect(await orderCount(customer), 'one new order').toBe(before + 1);
      const mine = (await getJson(customer, `/api/v1/orders?q=${po}`)).data;
      expect(mine).toHaveLength(1);
      expect(mine[0].total_cents).toBe(3000);
      expect((await getJson(customer, '/api/v1/cart')).data.items, 'the cart was emptied by the winner').toEqual([]);
    } finally {
      await Promise.all([customer.dispose(), staff.dispose()]);
    }
  });

  test('two parallel accepts of one quote: exactly one order is created', async () => {
    const customer = await apiCustomer();
    const staff = await apiAs('staff');
    try {
      const created = await send(customer, 'POST', '/api/v1/quotes', { lines: [{ description: 'Race accept bearing', quantity: 2 }] });
      const number = (await created.json()).data.number as string;
      await send(staff, 'POST', `/api/v1/quotes/${number}/review`);
      await send(staff, 'PUT', `/api/v1/quotes/${number}/pricing`, { lines: [{ position: 1, unit_price_cents: 5000 }], valid_until: utcDate(7) });
      const sent = await send(staff, 'POST', `/api/v1/quotes/${number}/send`, {});
      expect(sent.status(), await sent.text()).toBe(200);
      const revision = (await sent.json()).data.revision as number;

      const results = await Promise.all([0, 1, 2].map(() => send(customer, 'POST', `/api/v1/quotes/${number}/accept`, { revision })));
      expect(statuses(results)).toEqual([200, 422, 422]);
      for (const r of results.filter((x) => x.status() === 422)) expect(await errorKeys(r)).toEqual(['status']);
      const detail = (await getJson(customer, `/api/v1/quotes/${number}`)).data;
      expect(detail.status).toBe('accepted');
      const orders = (await getJson(customer, `/api/v1/orders?q=${detail.order.number}`)).data;
      expect(orders).toHaveLength(1);
      expect(orders[0].quote_number).toBe(number);
    } finally {
      await Promise.all([customer.dispose(), staff.dispose()]);
    }
  });

  test('double click on "Place order" in the browser sends one request and creates one order', async ({ browser }) => {
    const customer = await apiCustomer();
    const staff = await apiAs('staff');
    const { context, page } = await actor(browser, 'customer');
    try {
      const product = await createProduct(staff, { prefix: 'R-D', price_cents: 900 });
      await clearCart(customer);
      await addToCart(customer, product.id, 1);
      const before = await orderCount(customer);

      await page.goto('/checkout');
      await expect(page.getByRole('heading', { name: 'Checkout' })).toBeVisible();
      await page.getByLabel('PO reference (optional)').fill(`RACE-D-${stamp()}`);
      const posts: string[] = [];
      page.on('request', (r) => r.method() === 'POST' && new URL(r.url()).pathname === '/api/v1/orders' && posts.push(r.url()));
      const placed = apiResponse(page, 'POST', '/api/v1/orders');
      await page.getByRole('button', { name: 'Place order' }).dblclick();
      expect((await placed).status()).toBe(201);
      await expect(page).toHaveURL(/\/orders\/ORD-/);
      expect(posts, 'one POST for a double click').toHaveLength(1);
      expect(await orderCount(customer)).toBe(before + 1);
    } finally {
      await Promise.all([context.close(), customer.dispose(), staff.dispose()]);
    }
  });
});
