import * as fs from 'node:fs';
import { expect, test } from '../helpers/fixtures';
import { actor } from '../helpers/api';
import { getJson, send } from '../helpers/api';
import { axeCheckpoint } from '../helpers/axe';
import { watchCsp } from '../helpers/csp';
import {
  addToCart,
  apiAs,
  apiCustomer,
  clearCart,
  createProduct,
  money,
  placeOrder,
  stamp,
  updateProduct,
  utcDate,
} from '../helpers/data';
import { PASSWORD, USERS, WEB_URL } from '../helpers/env';
import { apiResponse, backOffice, loginThroughForm, mainNav } from '../helpers/ui';

/**
 * Staff journey (docs/phase-7-design.md 8.3): one test, steps with test.step. Staff works through the real UI; the customer's
 * side (order, quote) is set up through the customer API. Own product, own PO, own quote: re-runnable and independent.
 */
test('staff journey: dashboard, order, quote, invoice, product, company billing, CSV import, audit log, guards', async ({
  browser,
}) => {
  test.setTimeout(180_000);
  const staffApi = await apiAs('staff');
  const alice = await apiCustomer();
  const ts = stamp();
  const po = `J-S-${ts}`;

  // ---- setup through the customer API: a pending order and a submitted quote, both for the journey product ----
  const product = await createProduct(staffApi, { prefix: 'J-S', price_cents: 2000 });
  await clearCart(alice);
  await addToCart(alice, product.id, 2);
  const orderNumber = await placeOrder(alice, po);
  const created = await send(alice, 'POST', '/api/v1/quotes', {
    po_reference: `${po}-Q`,
    customer_note: 'Journey note: please quote.',
    lines: [{ product_id: product.id, quantity: 3 }],
  });
  expect(created.status(), await created.text()).toBe(201);
  const quoteNumber = (await created.json()).data.number as string;
  const me = (await getJson(staffApi, '/api/v1/me')).data;
  const companyId = (await getJson(alice, '/api/v1/me')).data.company.id as number;

  const context = await browser.newContext({ baseURL: WEB_URL });
  await watchCsp(context);
  const page = await context.newPage();
  const admin = await actor(browser, 'admin');

  let invoice = { id: '', number: '', taxId: '', address: [] as string[] };
  let importId = '';
  const newName = `${product.name} rev2`;
  const finalName = `${newName} v3`;
  const newPart = `J-S-N${ts}`;
  const badPart = `J-S-BAD${ts}`;

  try {
    await test.step('1. login through the form, dashboard with its cards', async () => {
      await loginThroughForm(page, USERS.staff.email, PASSWORD);
      await mainNav(page).getByRole('link', { name: 'Back office' }).click();
      await expect(page).toHaveURL(`${WEB_URL}/admin`);
      await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
      for (const card of ['Open quotes', 'Pending orders', 'Orders this week', 'Overdue invoices', 'Inactive products'])
        await expect(page.getByRole('link', { name: new RegExp(`^${card}`) })).toBeVisible();
      await axeCheckpoint(page, 'admin dashboard');
    });

    let pendingBefore = 0;
    await test.step('2. confirm the order; the dashboard "pending orders" card drops by one', async () => {
      pendingBefore = (await getJson(staffApi, '/api/v1/admin/dashboard')).data.orders.pending;
      expect(pendingBefore).toBeGreaterThanOrEqual(1);

      await backOffice(page).getByRole('link', { name: 'Orders' }).click();
      await expect(page).toHaveURL(/\/admin\/orders\?status=pending/);
      await page.getByLabel('Order number or PO reference').fill(po);
      await expect(page.getByRole('status').filter({ hasText: '1 order found' })).toBeVisible();
      await page.getByRole('link', { name: orderNumber }).click();
      await expect(page).toHaveURL(new RegExp(`/admin/orders/${orderNumber}$`));
      await axeCheckpoint(page, 'admin order detail');

      await page.getByRole('button', { name: 'Mark as confirmed' }).click();
      const patched = apiResponse(page, 'PATCH', `/api/v1/orders/${orderNumber}/status`);
      await page.getByRole('dialog').getByRole('button', { name: 'Confirm' }).click();
      expect((await patched).status()).toBe(200);
      await expect(page.getByRole('region', { name: 'Order summary' })).toContainText('Confirmed');
      await expect(page.getByRole('list', { name: 'Status history' }).getByRole('listitem')).toHaveCount(2);

      const pendingAfter = (await getJson(staffApi, '/api/v1/admin/dashboard')).data.orders.pending;
      expect(pendingAfter).toBe(pendingBefore - 1);
      await backOffice(page).getByRole('link', { name: 'Dashboard' }).click();
      await expect(page.getByRole('link', { name: new RegExp(`^Pending orders\\s*${pendingAfter}$`) })).toBeVisible();
    });

    await test.step('3. quote: review, price, send; staff note is shown to the customer, the internal note is not', async () => {
      await backOffice(page).getByRole('link', { name: 'Quotes' }).click();
      await expect(page).toHaveURL(/\/admin\/quotes\?status=submitted/);
      await page.getByLabel('Quote number or PO reference').fill(quoteNumber);
      await page.getByRole('link', { name: quoteNumber }).click();
      await expect(page).toHaveURL(new RegExp(`/admin/quotes/${quoteNumber}$`));
      await expect(page.getByRole('heading', { level: 1 })).toContainText('Submitted');

      const review = apiResponse(page, 'POST', `/api/v1/quotes/${quoteNumber}/review`);
      await page.getByRole('button', { name: 'Take into review' }).click();
      expect((await review).status()).toBe(200);
      await expect(page.getByRole('heading', { level: 1 })).toContainText('In review');

      await page.getByRole('textbox', { name: 'Unit price for item 1' }).fill('21.50');
      await page.getByLabel(/^Valid until/).fill(utcDate(14));
      const priced = apiResponse(page, 'PUT', `/api/v1/quotes/${quoteNumber}/pricing`);
      await page.getByRole('button', { name: 'Save prices' }).click();
      expect((await priced).status()).toBe(200);
      await expect(page.getByRole('status').filter({ hasText: 'Prices saved.' })).toBeVisible();
      await axeCheckpoint(page, 'admin quote pricing form');

      await page.getByRole('button', { name: 'Send quote' }).click();
      const dialog = page.getByRole('dialog', { name: 'Send this quote to the customer?' });
      await dialog.getByLabel('Note for the customer (optional)').fill('Lead time 3 weeks.');
      const sent = apiResponse(page, 'POST', `/api/v1/quotes/${quoteNumber}/send`);
      await dialog.getByRole('button', { name: 'Send quote' }).click();
      expect((await sent).status()).toBe(200);
      await expect(page.getByRole('heading', { level: 1 })).toContainText('Quoted');
      await expect(page.getByText(money(3 * 2150)).first()).toBeVisible();

      await page.getByLabel('Internal note (never shown to the customer)').fill('Margin ok, supplier confirmed');
      const noted = apiResponse(page, 'PUT', `/api/v1/quotes/${quoteNumber}/internal-note`);
      await page.getByRole('button', { name: 'Save internal note' }).click();
      expect((await noted).status()).toBe(200);
      await expect(page.getByRole('status').filter({ hasText: 'Internal note saved.' })).toBeVisible();

      const customerView = (await getJson(alice, `/api/v1/quotes/${quoteNumber}`)).data;
      expect(customerView.status).toBe('quoted');
      expect(customerView.staff_note).toBe('Lead time 3 weeks.');
      expect(customerView.total_cents).toBe(3 * 2150);
      expect(Object.keys(customerView), 'the internal note never reaches the customer').not.toContain('internal_note');
      expect(JSON.stringify(customerView)).not.toContain('Margin ok');
    });

    await test.step('4. invoice from the confirmed order, mark paid, download the PDF', async () => {
      await backOffice(page).getByRole('link', { name: 'Orders' }).click();
      await page.getByRole('group', { name: 'Status' }).getByRole('checkbox', { name: 'Pending' }).click(); // the default filter hides a confirmed order
      await expect(page).not.toHaveURL(/status=pending/);
      await page.getByLabel('Order number or PO reference').fill(po);
      await page.getByRole('link', { name: orderNumber }).click();
      await page.getByRole('button', { name: 'Create invoice' }).click();
      const dialog = page.getByRole('dialog', { name: 'Create invoice?' });
      const issued = apiResponse(page, 'POST', `/api/v1/orders/${orderNumber}/invoice`);
      await dialog.getByRole('button', { name: 'Create invoice' }).click();
      const res = await issued;
      expect(res.status()).toBe(201);
      const inv = (await res.json()).data;
      invoice = { id: inv.id, number: inv.number, taxId: inv.buyer.tax_id, address: inv.buyer.address_lines };
      await expect(page).toHaveURL(new RegExp(`/admin/invoices/${inv.id}$`));

      await expect(page.getByRole('heading', { level: 1 })).toContainText(`${inv.number}`);
      await expect(page.getByRole('heading', { level: 1 })).toContainText('Open');
      await expect(page.getByRole('heading', { name: 'Seller' })).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Billed to' })).toBeVisible();
      const tax = Math.floor((4000 * inv.vat_rate_bp + 5000) / 10000);
      expect(inv.subtotal_cents).toBe(4000);
      expect(inv.total_cents).toBe(4000 + tax);
      await expect(page.getByRole('term').filter({ hasText: /^Total$/ }).locator('xpath=following-sibling::*[1]')).toHaveText(money(4000 + tax));
      await axeCheckpoint(page, 'admin invoice detail');

      await page.getByRole('button', { name: 'Mark as paid' }).click();
      const paid = apiResponse(page, 'POST', `/api/v1/invoices/${inv.id}/mark-paid`);
      await page.getByRole('dialog').getByRole('button', { name: 'Mark as paid' }).click();
      expect((await paid).status()).toBe(200);
      await expect(page.getByRole('heading', { level: 1 })).toContainText('Paid');
      await expect(page.getByRole('status').filter({ hasText: 'Invoice marked as paid.' })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Mark as paid' })).toHaveCount(0);
      await expect(page.getByRole('button', { name: 'Void invoice' })).toHaveCount(0);

      const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: `Download PDF (${inv.number})` }).click(),
      ]);
      expect(download.suggestedFilename()).toBe(`${inv.number}.pdf`);
      expect(fs.readFileSync(await download.path()).subarray(0, 5).toString('latin1')).toBe('%PDF-');
    });

    await test.step('5. product: edit, stale-version conflict and reload, customer sees it, deactivate and reactivate', async () => {
      await backOffice(page).getByRole('link', { name: 'Products' }).click();
      await page.getByLabel('Part number', { exact: true }).fill(product.part_number);
      await page.getByRole('link', { name: product.part_number, exact: true }).click();
      await expect(page).toHaveURL(/\/admin\/products\/\d+$/);
      await expect(page.getByRole('heading', { level: 1 })).toContainText(product.part_number);

      await page.getByLabel('Name', { exact: true }).fill(newName);
      await page.getByLabel('Price (USD)', { exact: true }).fill('25.00');
      await expect(page.getByText(/Saving will change the price from \$20\.00 to \$25\.00/)).toBeVisible();
      await axeCheckpoint(page, 'admin product form');
      const saved = apiResponse(page, 'PUT', `/api/v1/admin/products/${product.id}`);
      await page.getByRole('button', { name: 'Save changes' }).click();
      expect((await saved).status()).toBe(200);
      await expect(page.getByRole('status').filter({ hasText: 'Product saved.' })).toBeVisible();

      // Someone else edits the product in between: the next save from this page is a 409, nothing is overwritten.
      await updateProduct(staffApi, product.id, { width_mm: 9 });
      await page.getByLabel('Name', { exact: true }).fill(`${newName} again`);
      const conflict = apiResponse(page, 'PUT', `/api/v1/admin/products/${product.id}`);
      await page.getByRole('button', { name: 'Save changes' }).click();
      expect((await conflict).status()).toBe(409);
      const banner = page.getByRole('alert').filter({ hasText: 'This record was changed by someone else' });
      await expect(banner).toBeVisible();
      expect((await getJson(staffApi, `/api/v1/admin/products/${product.id}`)).data.name).toBe(newName);
      await banner.getByRole('button', { name: 'Reload latest' }).click();
      await expect(banner).toHaveCount(0);
      await expect(page.getByLabel('Width (mm)')).toHaveValue('9');
      await expect(page.getByLabel('Name', { exact: true })).toHaveValue(newName);

      await page.getByLabel('Name', { exact: true }).fill(finalName);
      const resaved = apiResponse(page, 'PUT', `/api/v1/admin/products/${product.id}`);
      await page.getByRole('button', { name: 'Save changes' }).click();
      expect((await resaved).status()).toBe(200);
      await expect(page.getByRole('status').filter({ hasText: 'Product saved.' })).toBeVisible();

      const customerSees = async () => (await getJson(alice, `/api/v1/products?q=${product.part_number}`)).data as { name: string; price_cents: number }[];
      await expect.poll(async () => (await customerSees())[0]?.name).toBe(finalName);
      expect((await customerSees())[0].price_cents).toBe(2500);

      await page.getByRole('button', { name: 'Deactivate product' }).click();
      const off = apiResponse(page, 'POST', /\/api\/v1\/admin\/products\/\d+\/deactivate$/);
      await page.getByRole('dialog', { name: 'Deactivate this product?' }).getByRole('button', { name: 'Deactivate' }).click();
      expect((await off).status()).toBe(200);
      await expect(page.getByRole('button', { name: 'Activate product' })).toBeVisible();
      expect(await customerSees(), 'a deactivated product is gone from the customer catalog').toEqual([]);

      await page.getByRole('button', { name: 'Activate product' }).click();
      const on = apiResponse(page, 'POST', /\/api\/v1\/admin\/products\/\d+\/activate$/);
      await page.getByRole('dialog', { name: 'Activate this product?' }).getByRole('button', { name: 'Activate' }).click();
      expect((await on).status()).toBe(200);
      await expect(page.getByRole('button', { name: 'Deactivate product' })).toBeVisible();
      expect((await customerSees()).length).toBe(1);
    });

    await test.step('6. company billing edit; the issued invoice keeps its old buyer snapshot', async () => {
      const company = (await getJson(staffApi, `/api/v1/admin/companies/${companyId}`)).data;
      await backOffice(page).getByRole('link', { name: 'Companies' }).click();
      await page.getByRole('link', { name: company.name, exact: true }).click();
      await expect(page).toHaveURL(new RegExp(`/admin/companies/${companyId}$`));

      const address = [`${ts} Journey Street 1`, `Journeytown ${ts}`];
      await page.getByLabel('Billing address').fill(address.join('\n'));
      await page.getByLabel('Tax ID').fill(`J-S-${ts}`);
      const billing = apiResponse(page, 'PUT', `/api/v1/admin/companies/${companyId}/billing`);
      await page.getByRole('button', { name: 'Save company' }).click();
      expect((await billing).status()).toBe(200);
      await expect(page.getByRole('status').filter({ hasText: 'Company saved.' })).toBeVisible();

      await page.reload();
      await expect(page.getByLabel('Billing address')).toHaveValue(address.join('\n'));
      await expect(page.getByLabel('Tax ID')).toHaveValue(`J-S-${ts}`);
      const after = (await getJson(staffApi, `/api/v1/admin/companies/${companyId}`)).data;
      expect(after.version).toBe(company.version + 1);

      const old = (await getJson(alice, `/api/v1/invoices/${invoice.id}`)).data.buyer;
      expect(old.tax_id, 'the invoice keeps the tax id of its issue date').toBe(invoice.taxId);
      expect(old.tax_id).not.toBe(`J-S-${ts}`);
      expect(old.address_lines).toEqual(invoice.address);
      expect(old.address_lines).not.toEqual(address);
    });

    await test.step('7. CSV import wizard: upload, preview with a line-numbered error, apply with "skip invalid"', async () => {
      const cur = (await getJson(staffApi, `/api/v1/admin/products/${product.id}`)).data;
      const row = (p: string, name: string, brand: string, inner: number, outer: number, cents: number) =>
        [p, name, brand, cur.category.slug, inner, outer, cur.width_mm, cur.material, cur.seal_type, cur.stock_status, cents].join(',');
      const csv = [
        'part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,stock_status,price_cents',
        row(product.part_number, cur.name, cur.brand, cur.inner_diameter_mm, cur.outer_diameter_mm, 3100), // update: price only
        row(newPart, 'Journey imported bearing', 'JourneyBrand', 12, 32, 1800), // create
        row(badPart, 'Journey invalid bearing', 'JourneyBrand', 40, 30, 900), // invalid: outer not above inner
      ].join('\n');

      await backOffice(page).getByRole('link', { name: 'Products' }).click();
      await page.getByRole('link', { name: 'Import CSV' }).click();
      await expect(page.getByRole('heading', { level: 1 })).toContainText('step 1 of 3');
      await page.getByLabel('CSV file').setInputFiles({ name: 'journey.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf8') });
      const preview = apiResponse(page, 'POST', '/api/v1/admin/products/imports');
      await page.getByRole('button', { name: 'Upload and check' }).click();
      const previewRes = await preview;
      expect(previewRes.status()).toBe(201);
      importId = (await previewRes.json()).data.id;

      await expect(page.getByRole('heading', { level: 1 })).toContainText('step 2 of 3');
      const counts = (term: string) => page.getByRole('term').filter({ hasText: new RegExp(`^${term}$`) }).locator('xpath=following-sibling::*[1]');
      await expect(counts('Rows read')).toHaveText('3');
      await expect(counts('Would create')).toHaveText('1');
      await expect(counts('Would update')).toHaveText('1');
      await expect(counts('Invalid rows')).toHaveText('1');
      const invalid = page.getByRole('table', { name: 'Invalid rows' });
      await expect(invalid.getByRole('row').nth(1)).toContainText('4'); // header is line 1, the bad row is line 4
      await expect(invalid).toContainText('outer_diameter_mm: must be greater than inner_diameter_mm');
      await axeCheckpoint(page, 'admin import preview');

      const apply = page.getByRole('button', { name: 'Apply import' });
      await expect(apply).toBeDisabled();
      await page.getByRole('checkbox', { name: /Skip the 1 invalid row/ }).check();
      await expect(apply).toBeEnabled();
      await apply.click();
      const applied = apiResponse(page, 'POST', `/api/v1/admin/products/imports/${importId}/apply`);
      await page.getByRole('dialog', { name: 'Apply this import?' }).getByRole('button', { name: 'Confirm' }).click();
      expect((await applied).status()).toBe(200);
      await expect(page.getByRole('heading', { level: 1 })).toContainText('step 3 of 3');
      await expect(page.getByRole('term').filter({ hasText: /^Created$/ }).locator('xpath=following-sibling::*[1]')).toHaveText('1');
      await expect(page.getByRole('term').filter({ hasText: /^Updated$/ }).locator('xpath=following-sibling::*[1]')).toHaveText('1');

      expect((await getJson(staffApi, `/api/v1/admin/products?q=${newPart}`)).meta.total).toBe(1);
      expect((await getJson(staffApi, `/api/v1/admin/products?q=${badPart}`)).meta.total, 'the invalid row was skipped').toBe(0);
      expect((await getJson(staffApi, `/api/v1/admin/products/${product.id}`)).data.price_cents).toBe(3100);
    });

    await test.step('8. audit log (admin): every action is there, written by Sam Staff', async () => {
      type Row = { action: string; actor: { name: string; role: string } | null; changes: { before?: Record<string, unknown>; after?: Record<string, unknown> }; metadata: Record<string, unknown> };
      const rows = async (query: string): Promise<Row[]> => (await getJson(admin.context, `/api/v1/admin/audit-log?per_page=100&${query}`)).data;
      const actions = (rs: Row[]) => rs.map((r) => r.action).sort();
      const staffOnly = (rs: Row[]) => rs.forEach((r) => expect(r.actor, r.action).toMatchObject({ name: USERS.staff.name, role: 'staff' }));

      const order = await rows(`subject_type=order&subject_id=${orderNumber}`);
      expect(actions(order)).toEqual(['order.status_changed']);
      expect(order[0].changes).toEqual({ before: { status: 'pending' }, after: { status: 'confirmed' } });
      const quote = await rows(`subject_type=quote&subject_id=${quoteNumber}`);
      expect(actions(quote)).toEqual(['quote.priced', 'quote.reviewed', 'quote.sent']);
      const inv = await rows(`subject_type=invoice&subject_id=${invoice.id}`);
      expect(actions(inv)).toEqual(['invoice.created', 'invoice.paid']);
      const prod = await rows(`subject_type=product&subject_id=${product.id}`);
      expect(actions(prod)).toEqual(['product.activated', 'product.created', 'product.deactivated', 'product.import_updated', 'product.updated', 'product.updated', 'product.updated']);
      const updatedByImport = prod.find((r) => r.action === 'product.import_updated')!;
      expect(updatedByImport.changes, 'exact before and after price of the import update').toEqual({ before: { price_cents: 2500 }, after: { price_cents: 3100 } });
      expect(updatedByImport.metadata).toMatchObject({ import_id: importId, file_name: 'journey.csv' });
      const company = await rows(`subject_type=company&subject_id=${companyId}&action=company.updated`);
      expect(company.length).toBeGreaterThanOrEqual(1);
      expect(company[0].metadata).toMatchObject({ billing_address_changed: true });
      const imp = await rows(`subject_type=import&subject_id=${importId}`);
      expect(actions(imp)).toEqual(['product.imported']);
      expect(imp[0].metadata).toMatchObject({ rows_read: 3, created: 1, updated: 1, bad_rows: 1, skipped_rows: 1, file_name: 'journey.csv' });
      for (const rs of [order, quote, inv, prod, company, imp]) staffOnly(rs);

      const all = await rows(`actor_id=${me.id}&from=${utcDate(0)}`);
      expect(JSON.stringify(all), 'no audit row for the skipped invalid CSV row').not.toContain(badPart);

      // The same rows in the UI.
      const ap = admin.page;
      await ap.goto(`/admin/audit-log?subject_type=product&subject_id=${product.id}`);
      const table = ap.getByRole('table', { name: 'Audit log' });
      await expect(table.getByRole('row')).toHaveCount(prod.length + 1);
      await expect(table.getByRole('row', { name: /Sam Staff \(staff\)/ })).toHaveCount(prod.length);
      await expect(table.getByRole('row', { name: /Product: import updated/ })).toHaveCount(1);
      await ap.getByRole('row', { name: /Product: import updated/ }).getByText('Details').click();
      await expect(ap.getByRole('row', { name: /Product: import updated/ })).toContainText('price_cents: 2500 to 3100');
      await axeCheckpoint(ap, 'admin audit log');
      await ap.goto(`/admin/audit-log?subject_type=order&subject_id=${orderNumber}`);
      await expect(ap.getByRole('table', { name: 'Audit log' }).getByRole('row', { name: /Order: status changed/ })).toContainText('Sam Staff (staff)');
    });

    await test.step('9. staff has no Users or Audit log; logout', async () => {
      await expect(backOffice(page).getByRole('link', { name: 'Users' })).toHaveCount(0);
      await expect(backOffice(page).getByRole('link', { name: 'Audit log' })).toHaveCount(0);
      await page.goto('/admin/users');
      await expect(page.getByRole('heading', { name: 'Not allowed' })).toBeVisible();
      expect((await staffApi.get('/api/v1/admin/audit-log', { headers: { Accept: 'application/json', Origin: WEB_URL } })).status()).toBe(403);
      await page.getByRole('button', { name: 'Log out' }).click();
      await expect(page).toHaveURL(/\/login(\?|$)/);
      const gone = await context.request.get('/api/v1/me', { headers: { Accept: 'application/json', Origin: WEB_URL } });
      expect(gone.status()).toBe(401);
    });
  } finally {
    await Promise.all([context.close(), admin.context.close(), staffApi.dispose(), alice.dispose()]);
  }
});
