import * as fs from 'node:fs';
import { request } from '@playwright/test';
import { expect, test } from '../helpers/fixtures';
import { watchCsp } from '../helpers/csp';
import { getJson, send } from '../helpers/api';
import { axeCheckpoint } from '../helpers/axe';
import {
  apiAs,
  apiCustomer,
  clearCart,
  createProduct,
  money,
  orderCount,
  setOrderStatus,
  tinyPdf,
  updateProduct,
  utcDate,
  stamp,
} from '../helpers/data';
import { PASSWORD, USERS, WEB_URL } from '../helpers/env';
import { mailpitUp, waitForMail } from '../helpers/mailpit';
import { apiResponse, literal, loginThroughForm, mainNav, productRow } from '../helpers/ui';

/**
 * Customer journey (docs/phase-7-design.md 8.2): one test, steps with test.step. The customer part goes through the
 * real UI; everything staff does (price change, quote pricing, order confirmation, invoice) goes through the API helpers.
 * Own product, own PO references: nothing here depends on another spec or on the seeded orders.
 */
test('customer journey: search, cart, checkout with price guard, orders, quote with attachment, accept, invoice PDF, logout', async ({
  browser,
}) => {
  test.setTimeout(180_000);
  const mailOn = await mailpitUp();
  if (process.env.CI) expect(mailOn, 'Mailpit must be running in CI').toBe(true);

  const staff = await apiAs('staff');
  const alice = await apiCustomer(); // API view of the same customer, for assertions the UI cannot make
  const mail = await request.newContext();
  const ts = stamp();
  const po = `J-C-${ts}`;

  // Own product (price 12.50) plus two seeded products with prices, found through the real catalog API.
  const own = await createProduct(staff, { prefix: 'J-C', price_cents: 1250, brand: 'JrnyBrand' });
  const seeded = (await getJson(alice, '/api/v1/products?q=6&per_page=50')).data.filter(
    (p: { stock_status: string; price_on_request: boolean; price_cents: number | null }) =>
      p.stock_status === 'in_stock' && !p.price_on_request && p.price_cents,
  ) as { id: number; part_number: string; brand: string; price_cents: number }[];
  expect(seeded.length, 'the seeded catalog has at least two orderable products').toBeGreaterThanOrEqual(2);
  const [b, c] = seeded;
  await clearCart(alice);

  const context = await browser.newContext({ baseURL: WEB_URL });
  await watchCsp(context);
  const page = await context.newPage();

  let orderNumber = '';
  let quoteNumber = '';
  let invoice = { id: '', number: '' };
  const ownPriceAfter = 1250 + 250;
  const subtotalBefore = b.price_cents * 4 + own.price_cents * 3;
  const subtotalAfter = b.price_cents * 4 + ownPriceAfter * 3;

  try {
    await test.step('1. login through the real form lands on the home page', async () => {
      await loginThroughForm(page, USERS.customer.email, PASSWORD);
      await expect(page).toHaveURL(`${WEB_URL}/`);
      await expect(mainNav(page).getByRole('link', { name: 'Catalog' })).toBeVisible();
      await expect(page.getByText(USERS.customer.name, { exact: true })).toBeVisible();
    });

    await test.step('2. catalog: search and brand filter live in the URL, product detail, back keeps the filters', async () => {
      await mainNav(page).getByRole('link', { name: 'Catalog' }).click();
      await expect(page.getByRole('heading', { name: 'Catalog' })).toBeVisible();
      await page.getByLabel('Part number', { exact: true }).fill(b.part_number);
      await expect(page).toHaveURL(new RegExp(`[?&]q=${encodeURIComponent(b.part_number).replace(/\./g, '\\.')}`));
      await expect(productRow(page, b.part_number)).toHaveCount(1);

      await page.getByRole('group', { name: 'Brand' }).getByRole('checkbox', { name: b.brand, exact: true }).click(); // controlled by the URL: click, then assert (check() would race the state update)
      await expect(page).toHaveURL(new RegExp(`[?&]brand=${encodeURIComponent(b.brand)}`));
      await expect(page.getByRole('status').filter({ hasText: /\d+ products? found/ })).toBeVisible();
      await expect(productRow(page, b.part_number)).toHaveCount(1);
      await axeCheckpoint(page, 'catalog with filters');

      await page.getByRole('link', { name: b.part_number, exact: true }).click();
      await expect(page).toHaveURL(/\/catalog\/\d+/);
      await expect(page.getByRole('heading', { level: 1 })).toContainText(b.part_number);
      await page.goBack();
      await expect(page).toHaveURL(/[?&]q=/);
      await expect(page).toHaveURL(/[?&]brand=/);
      await expect(page.getByRole('group', { name: 'Brand' }).getByRole('checkbox', { name: b.brand, exact: true })).toBeChecked();
      await expect(productRow(page, b.part_number)).toHaveCount(1);
    });

    await test.step('3. cart: add three products, change a quantity, remove a line, exact subtotal', async () => {
      const add = async (partNumber: string, quantity: number) => {
        await page.getByLabel('Part number', { exact: true }).fill(partNumber);
        const row = productRow(page, partNumber);
        await expect(row).toHaveCount(1);
        await row.getByRole('textbox', { name: `Quantity for ${partNumber}` }).fill(String(quantity));
        const added = apiResponse(page, 'POST', '/api/v1/cart/items');
        await row.getByRole('button', { name: 'Add to cart' }).click();
        expect((await added).status()).toBe(200);
        await expect(row.getByRole('status')).toContainText(`Added ${quantity} x ${partNumber}`);
      };
      await add(b.part_number, 2);
      // The brand filter of product B would hide the others: clear all filters first.
      await page.getByRole('button', { name: 'Clear all filters' }).click();
      await expect(page).not.toHaveURL(/[?&]q=/); // the box is reset from the URL: wait for it before typing
      await expect(page.getByLabel('Part number', { exact: true })).toHaveValue('');
      await add(own.part_number, 3);
      await add(c.part_number, 1);

      await expect(mainNav(page).getByRole('link', { name: 'Cart 3 items' })).toBeVisible();
      await mainNav(page).getByRole('link', { name: 'Cart 3 items' }).click();
      await expect(page.getByRole('heading', { name: 'Your cart' })).toBeVisible();
      const cartRow = (pn: string) => page.getByRole('table', { name: 'Items in your cart' }).getByRole('row', { name: literal(pn) });

      await page.getByRole('textbox', { name: `Quantity for ${b.part_number}` }).fill('4');
      const patched = apiResponse(page, 'PATCH', /\/api\/v1\/cart\/items\/\d+$/);
      await page.getByRole('button', { name: `Update quantity for ${b.part_number}` }).click();
      expect((await patched).status()).toBe(200);
      await expect(page.getByRole('status').filter({ hasText: 'Quantity updated' })).toBeVisible();

      const removed = apiResponse(page, 'DELETE', /\/api\/v1\/cart\/items\/\d+$/);
      await page.getByRole('button', { name: `Remove ${c.part_number} from cart` }).click();
      expect((await removed).status()).toBe(200);
      await expect(cartRow(c.part_number)).toHaveCount(0);

      // Hand-calculated: 4 x B + 3 x own.
      await expect(page.getByText(`Subtotal: ${money(subtotalBefore)}`)).toBeVisible();
      await expect(cartRow(own.part_number)).toContainText(money(own.price_cents * 3));
      await expect(mainNav(page).getByRole('link', { name: 'Cart 2 items' })).toBeVisible();
      await axeCheckpoint(page, 'cart');
    });

    await test.step('4. checkout: a price change is never silent (409), the second click orders at the new price', async () => {
      await page.getByRole('link', { name: 'Proceed to checkout' }).click();
      await expect(page.getByRole('heading', { name: 'Checkout' })).toBeVisible();
      await expect(page.getByText(`Total: ${money(subtotalBefore)}`)).toBeVisible();
      await axeCheckpoint(page, 'checkout');

      // Staff changes the price after the customer's page was rendered with the old one.
      await updateProduct(staff, own.id, { price_cents: ownPriceAfter });
      const before = await orderCount(alice);

      await page.getByLabel('PO reference (optional)').fill(po);
      const conflict = apiResponse(page, 'POST', '/api/v1/orders');
      await page.getByRole('button', { name: 'Place order' }).click();
      const res = await conflict;
      expect(res.status()).toBe(409);
      expect((await res.json()).cart.subtotal_cents).toBe(subtotalAfter);
      const alert = page.getByRole('alert').filter({ hasText: 'Prices in your cart changed' });
      await expect(alert).toContainText(`The new subtotal is ${money(subtotalAfter)} (was ${money(subtotalBefore)})`);
      await expect(page).toHaveURL(/\/checkout$/);
      await expect(page.getByText(`Total: ${money(subtotalAfter)}`)).toBeVisible();
      expect(await orderCount(alice), 'a 409 creates no order').toBe(before);
      await axeCheckpoint(page, 'checkout after price change');

      // No automatic resubmit: the buyer clicks again.
      const placed = apiResponse(page, 'POST', '/api/v1/orders');
      await page.getByRole('button', { name: 'Place order' }).click();
      expect((await placed).status()).toBe(201);
      await expect(page).toHaveURL(/\/orders\/ORD-[A-Z0-9-]+$/);
      orderNumber = new URL(page.url()).pathname.split('/').pop()!;
      expect(await orderCount(alice)).toBe(before + 1);

      const summary = page.getByRole('region', { name: 'Order summary' });
      await expect(summary).toContainText('Pending');
      await expect(summary).toContainText(`PO reference: ${po}`);
      await expect(page.getByRole('status').filter({ hasText: `Order ${orderNumber} was received` })).toBeVisible();
      await expect(page.getByRole('row', { name: literal(own.part_number) })).toContainText(money(ownPriceAfter));
      await expect(page.getByRole('row', { name: literal(own.part_number) })).toContainText(money(ownPriceAfter * 3));
      await expect(page.getByRole('term').filter({ hasText: /^Total$/ }).locator('xpath=following-sibling::*[1]')).toHaveText(money(subtotalAfter));
      await axeCheckpoint(page, 'order detail');
    });

    await test.step('5. order history: newest first, search by PO and status filter live in the URL, open from the list', async () => {
      await mainNav(page).getByRole('link', { name: 'Orders', exact: true }).click();
      await expect(page.getByRole('heading', { name: /^Orders for/ })).toBeVisible();
      const table = page.getByRole('table', { name: 'Orders' });
      await expect(table.getByRole('row').nth(1)).toContainText(orderNumber);

      await page.getByLabel('Order number or PO reference').fill(po);
      await expect(page).toHaveURL(new RegExp(`[?&]q=${po}`));
      await expect(page.getByRole('status').filter({ hasText: '1 order found' })).toBeVisible();
      await page.getByRole('group', { name: 'Status' }).getByRole('checkbox', { name: 'Pending' }).click();
      await expect(page).toHaveURL(/[?&]status=pending/);
      await expect(table.getByRole('row')).toHaveCount(2); // header + the one order
      await axeCheckpoint(page, 'order history');

      await table.getByRole('link', { name: orderNumber }).click();
      await expect(page).toHaveURL(new RegExp(`/orders/${orderNumber}$`));
      await expect(page.getByRole('heading', { level: 1 })).toContainText(orderNumber);
    });

    await test.step('6. quote request with a catalog line, a custom line and a PDF attachment', async () => {
      await mainNav(page).getByRole('link', { name: 'Quotes', exact: true }).click();
      await page.getByRole('link', { name: 'New quote request' }).click();
      await expect(page.getByRole('heading', { name: 'Request a quote' })).toBeVisible();

      await page.getByLabel('PO reference (optional)').fill(`${po}-Q`);
      await page.getByLabel('Note for our team (optional)').fill('Need CE certificates please');
      await page.getByRole('button', { name: 'Add catalog item' }).click();
      await page.getByLabel('Search the catalog').fill(own.part_number);
      await page.getByRole('button', { name: `Add ${own.part_number}`, exact: true }).click();
      await page.getByRole('button', { name: 'Close search' }).click();
      await page.getByRole('button', { name: 'Add custom item' }).click();
      const item1 = page.getByRole('group', { name: 'Item 1' });
      const item2 = page.getByRole('group', { name: 'Item 2' });
      await item1.getByLabel('Quantity').fill('5');
      await item2.getByLabel('Description').fill('Taper roller bearing 32208, SKF or equivalent');
      await item2.getByLabel('Quantity').fill('4');
      await page.getByLabel('Add files').setInputFiles({ name: 'drawing-rev2.pdf', mimeType: 'application/pdf', buffer: tinyPdf('drawing') });
      await expect(page.getByRole('list', { name: 'Files to upload' })).toContainText('drawing-rev2.pdf');
      await axeCheckpoint(page, 'quote form');

      const created = apiResponse(page, 'POST', '/api/v1/quotes');
      const uploaded = apiResponse(page, 'POST', /\/api\/v1\/quotes\/QUO-[A-Z0-9-]+\/attachments$/);
      await page.getByRole('button', { name: 'Submit quote request' }).click();
      expect((await created).status()).toBe(201);
      expect((await uploaded).status()).toBe(201);
      await expect(page).toHaveURL(/\/quotes\/QUO-[A-Z0-9-]+$/);
      quoteNumber = new URL(page.url()).pathname.split('/').pop()!;

      await expect(page.getByRole('heading', { level: 1 })).toContainText('Submitted');
      await expect(page.getByRole('status').filter({ hasText: `Quote request ${quoteNumber} was submitted` })).toBeVisible();
      const link = page.getByRole('link', { name: 'drawing-rev2.pdf' });
      await expect(link).toBeVisible();
      const [download] = await Promise.all([page.waitForEvent('download'), link.click()]);
      expect(download.suggestedFilename()).toBe('drawing-rev2.pdf');
      const file = await download.path();
      expect(fs.readFileSync(file).subarray(0, 5).toString('latin1')).toBe('%PDF-');
      await axeCheckpoint(page, 'quote detail');

      if (mailOn) {
        const m = await waitForMail(mail, { subject: `New quote request ${quoteNumber}` });
        expect(m.Subject).toBe(`New quote request ${quoteNumber}`);
      }
    });

    await test.step('7. staff prices and sends the quote through the API; the customer sees Quoted with the total', async () => {
      const review = await send(staff, 'POST', `/api/v1/quotes/${quoteNumber}/review`);
      expect(review.status(), await review.text()).toBe(200);
      const priced = await send(staff, 'PUT', `/api/v1/quotes/${quoteNumber}/pricing`, {
        lines: [
          { position: 1, unit_price_cents: ownPriceAfter },
          { position: 2, unit_price_cents: 3875 },
        ],
        valid_until: utcDate(10),
      });
      expect(priced.status(), await priced.text()).toBe(200);
      const sent = await send(staff, 'POST', `/api/v1/quotes/${quoteNumber}/send`, { staff_note: 'Lead time 3 weeks, ex works.' });
      expect(sent.status(), await sent.text()).toBe(200);

      const quoteTotal = 5 * ownPriceAfter + 4 * 3875;
      await page.reload();
      await expect(page.getByRole('heading', { level: 1 })).toContainText('Quoted');
      await expect(page.getByText('Lead time 3 weeks, ex works.')).toBeVisible();
      await expect(page.getByText(`Valid through ${utcDate(10)}`)).toBeVisible();
      await expect(page.getByRole('term').filter({ hasText: /^Total$/ }).locator('xpath=following-sibling::*[1]')).toHaveText(money(quoteTotal));

      if (mailOn) {
        const m = await waitForMail(mail, { to: USERS.customer.email, subject: `Your quote ${quoteNumber} is ready` });
        expect(m.Text + m.HTML).toContain(`${WEB_URL}/quotes/${quoteNumber}`);
      }
    });

    await test.step('8. customer accepts through the confirm dialog and lands on the new order', async () => {
      await page.getByRole('button', { name: 'Accept quote' }).click();
      const dialog = page.getByRole('dialog', { name: 'Accept this quote?' });
      await expect(dialog).toBeVisible();
      await expect(dialog).toContainText(money(5 * ownPriceAfter + 4 * 3875));
      const accepted = apiResponse(page, 'POST', `/api/v1/quotes/${quoteNumber}/accept`);
      await dialog.getByRole('button', { name: 'Accept quote and create order' }).click();
      expect((await accepted).status()).toBe(200);
      await expect(page).toHaveURL(/\/orders\/ORD-[A-Z0-9-]+$/);
      const quoteOrder = new URL(page.url()).pathname.split('/').pop()!;
      expect(quoteOrder).not.toBe(orderNumber);
      await expect(page.getByRole('region', { name: 'Order summary' })).toContainText('Pending');
      await expect(page.getByRole('link', { name: quoteNumber })).toBeVisible();
      await expect(page.getByRole('status').filter({ hasText: `Quote accepted. Order ${quoteOrder} was created` })).toBeVisible();
    });

    await test.step('9. staff issues the invoice through the API; customer sees it Open, lines and totals, downloads the PDF', async () => {
      await setOrderStatus(staff, orderNumber, 'confirmed');
      const issued = await send(staff, 'POST', `/api/v1/orders/${orderNumber}/invoice`, { due_date: utcDate(14) });
      expect(issued.status(), await issued.text()).toBe(201);
      const inv = (await issued.json()).data;
      invoice = { id: inv.id, number: inv.number };
      // VAT is half-up on the subtotal (contract Phase 5): hand-calculated from the rate the invoice reports.
      const tax = Math.floor((subtotalAfter * inv.vat_rate_bp + 5000) / 10000);
      expect(inv.subtotal_cents).toBe(subtotalAfter);
      expect(inv.tax_cents).toBe(tax);
      expect(inv.total_cents).toBe(subtotalAfter + tax);

      await mainNav(page).getByRole('link', { name: 'Invoices', exact: true }).click();
      await expect(page.getByRole('heading', { name: /^Invoices for/ })).toBeVisible();
      await page.getByLabel('Invoice number, order number or PO reference').fill(orderNumber);
      const row = page.getByRole('table', { name: 'Invoices' }).getByRole('row', { name: literal(inv.number) });
      await expect(row).toHaveCount(1);
      await expect(row).toContainText('Open');
      await axeCheckpoint(page, 'invoices list');

      await row.getByRole('link', { name: inv.number }).click();
      await expect(page).toHaveURL(new RegExp(`/invoices/${inv.id}$`));
      await expect(page.getByRole('heading', { level: 1 })).toContainText(inv.number);
      const lines = page.getByRole('table', { name: 'Invoice lines' });
      await expect(lines.getByRole('row', { name: literal(own.part_number) })).toContainText(money(ownPriceAfter));
      await expect(lines.getByRole('row', { name: literal(b.part_number) })).toContainText(money(b.price_cents * 4));
      await expect(page.getByRole('term').filter({ hasText: 'Subtotal' }).locator('xpath=following-sibling::*[1]')).toHaveText(money(subtotalAfter));
      await expect(page.getByRole('term').filter({ hasText: /^Total$/ }).locator('xpath=following-sibling::*[1]')).toHaveText(money(subtotalAfter + tax));
      await expect(page.getByRole('heading', { name: 'Billed to' })).toBeVisible();
      await axeCheckpoint(page, 'invoice detail');

      const pdf = apiResponse(page, 'GET', `/api/v1/invoices/${inv.id}/pdf`);
      const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: `Download PDF (${inv.number})` }).click(),
      ]);
      const headers = (await pdf).headers();
      expect(headers['content-type']).toContain('application/pdf');
      expect(headers['cache-control']).toContain('no-store');
      expect(headers['x-content-type-options']).toBe('nosniff');
      expect(headers['content-security-policy']).toBe("default-src 'none'; sandbox");
      expect(headers['content-disposition']).toBe(`attachment; filename="${inv.number}.pdf"`);
      expect(download.suggestedFilename()).toMatch(/^INV-\d{4}-\d{6,9}\.pdf$/);
      expect(download.suggestedFilename()).toBe(`${inv.number}.pdf`);
      const bytes = fs.readFileSync(await download.path());
      expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-');
      expect(bytes.length).toBeGreaterThan(1024);
      await expect(page.getByRole('status').filter({ hasText: `Download started: ${inv.number}.pdf` })).toBeVisible();
    });

    await test.step('10. logout ends the session and back shows no private data', async () => {
      await page.getByRole('button', { name: 'Log out' }).click();
      await expect(page).toHaveURL(/\/login(\?|$)/); // the page that was open is remembered as ?next=
      await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
      const me = await context.request.get('/api/v1/me', { headers: { Accept: 'application/json', Origin: WEB_URL } });
      expect(me.status()).toBe(401);

      await page.goto('/orders');
      await expect(page).toHaveURL(/\/login\?next=%2Forders$/);
      await page.goBack(); // back to /login, then once more to the invoice page that was open before logging out
      await page.goBack();
      await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
      await expect(page.getByText(invoice.number)).toHaveCount(0);
      await expect(page.getByText(orderNumber)).toHaveCount(0);
      await expect(page.getByRole('button', { name: 'Log out' })).toHaveCount(0);
    });
  } finally {
    await Promise.all([context.close(), staff.dispose(), alice.dispose(), mail.dispose()]);
  }
});
