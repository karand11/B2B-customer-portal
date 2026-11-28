import { type Page } from '@playwright/test';
import { expect, test } from '../helpers/fixtures';
import { actor, getJson, send, sendForm } from '../helpers/api';
import { apiAs, apiCustomer, createProduct, tinyPdf, utcDate } from '../helpers/data';

/** The audit log action filter knows every action of the contract, including the two added in Phase 7 (docs/api-contract.md). */

/** Opens the page, asserts the request carried the action, the checkbox is ticked and returns the table rows. */
async function openFiltered(page: Page, action: string, label: string, subjectType: string, subjectId: string) {
  const request = page.waitForRequest((r) => r.url().includes('/api/v1/admin/audit-log') && new URL(r.url()).searchParams.get('action') === action);
  await page.goto(`/admin/audit-log?action=${action}&subject_type=${subjectType}&subject_id=${encodeURIComponent(subjectId)}`);
  await request;
  await page.getByText(/^Actions \(\d+ selected\)$/).click();
  await expect(page.getByRole('checkbox', { name: label, exact: true }), 'the action checkbox is ticked from the URL').toBeChecked();
  await expect(page.getByText('Actions (1 selected)')).toBeVisible();
  return page.getByRole('table', { name: 'Audit log' }).getByRole('row');
}

test.describe('audit log: new actions filter the list', () => {
  test('?action=product.import_updated (request carries it, checkbox ticked, product.created filtered out)', async ({ browser }) => {
    const staff = await apiAs('staff');
    const { context, page } = await actor(browser, 'admin');
    try {
      const product = await createProduct(staff, { prefix: 'KI', price_cents: 1000 });
      const cur = (await getJson(staff, `/api/v1/admin/products/${product.id}`)).data;
      const csv = [
        'part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,stock_status,price_cents',
        [cur.part_number, cur.name, cur.brand, cur.category.slug, cur.inner_diameter_mm, cur.outer_diameter_mm, cur.width_mm, cur.material, cur.seal_type, cur.stock_status, 1500].join(','),
      ].join('\n');
      const preview = await sendForm(staff, '/api/v1/admin/products/imports', { file: { name: 'ki.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) } });
      expect(preview.status(), await preview.text()).toBe(201);
      const applied = await send(staff, 'POST', `/api/v1/admin/products/imports/${(await preview.json()).data.id}/apply`, {});
      expect(applied.status(), await applied.text()).toBe(200);

      const rows = await openFiltered(page, 'product.import_updated', 'Product: import updated', 'product', String(product.id));
      await expect(rows.nth(1)).toBeVisible();
      await expect(rows, 'header + the one import_updated row (product.created must be filtered out)').toHaveCount(2);
      await expect(rows.nth(1)).toContainText('import updated');
    } finally {
      await Promise.all([context.close(), staff.dispose()]);
    }
  });

  test('?action=quote.attachment_purged (request carries it, checkbox ticked, only the purge row)', async ({ browser }) => {
    const staff = await apiAs('staff');
    const alice = await apiCustomer();
    const { context, page } = await actor(browser, 'admin');
    try {
      const product = await createProduct(staff, { prefix: 'KP', price_cents: 1000 });
      const created = await send(alice, 'POST', '/api/v1/quotes', { po_reference: `KP-${product.part_number}`, customer_note: 'purge audit', lines: [{ product_id: product.id, quantity: 2 }] });
      expect(created.status(), await created.text()).toBe(201);
      const number = (await created.json()).data.number as string;
      const up = await sendForm(alice, `/api/v1/quotes/${number}/attachments`, { file: { name: 'purge.pdf', mimeType: 'application/pdf', buffer: tinyPdf('purge') } });
      expect(up.status(), await up.text()).toBe(201);
      expect((await send(staff, 'POST', `/api/v1/quotes/${number}/review`)).status()).toBe(200);
      const priced = await send(staff, 'PUT', `/api/v1/quotes/${number}/pricing`, { lines: [{ position: 1, unit_price_cents: 900 }], valid_until: utcDate(10) });
      expect(priced.status(), await priced.text()).toBe(200);
      const sent = await send(staff, 'POST', `/api/v1/quotes/${number}/send`, { staff_note: 'ok' });
      expect(sent.status(), await sent.text()).toBe(200);
      // Purging after the quote left submitted/in_review is the audited case.
      const attachment = (await getJson(staff, `/api/v1/quotes/${number}`)).data.attachments[0];
      const del = await send(staff, 'DELETE', `/api/v1/quotes/${number}/attachments/${attachment.id}`);
      expect(del.status(), await del.text()).toBe(204);

      const rows = await openFiltered(page, 'quote.attachment_purged', 'Quote: attachment purged', 'quote', number);
      await expect(rows.nth(1)).toBeVisible();
      await expect(rows, 'header + the one purge row (quote.created and quote.sent must be filtered out)').toHaveCount(2);
      await expect(rows.nth(1)).toContainText('attachment purged');
    } finally {
      await Promise.all([context.close(), staff.dispose(), alice.dispose()]);
    }
  });
});
