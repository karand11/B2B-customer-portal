import { type Page } from '@playwright/test';
import { expect, test } from '../helpers/fixtures';
import { actor } from '../helpers/api';

const isApi = (page: Page, method: string, matches: (path: string) => boolean) =>
  page.waitForResponse(
    (r) => r.request().method() === method && matches(new URL(r.url()).pathname),
  );

test('staff creates a product, it shows in the customer catalog; deactivating hides it', async ({
  browser,
}) => {
  const partNumber = `E2E-N${Date.now().toString(36).toUpperCase()}`;
  const staff = await actor(browser, 'staff');
  const customer = await actor(browser, 'customer');
  try {
    // Staff creates the product through the form.
    const sp = staff.page;
    await sp.goto('/admin/products/new');
    await sp.getByLabel('Category', { exact: true }).selectOption({ index: 1 });
    await sp.getByLabel('Part number', { exact: true }).fill(partNumber);
    await sp.getByLabel('Name', { exact: true }).fill('E2E flow bearing');
    await sp.getByLabel('Brand', { exact: true }).fill('E2E');
    await sp.getByLabel('Material', { exact: true }).fill('Chrome steel');
    await sp.getByLabel('Inner diameter (mm)').fill('12');
    await sp.getByLabel('Outer diameter (mm)').fill('32');
    await sp.getByLabel('Width (mm)').fill('10');
    await sp.getByLabel('Price (USD)', { exact: true }).fill('19.90');
    const created = isApi(sp, 'POST', (p) => p === '/api/v1/admin/products');
    await sp.getByRole('button', { name: 'Create product' }).click();
    expect((await created).status()).toBe(201);
    await sp.waitForURL(/\/admin\/products\/\d+$/);
    await expect(sp.getByRole('heading', { name: `Product ${partNumber}` })).toBeVisible();

    // The customer finds it in the catalog.
    const cp = customer.page;
    await cp.goto(`/catalog?q=${partNumber}`);
    await expect(cp.getByRole('row', { name: new RegExp(partNumber) })).toHaveCount(1);

    // Staff deactivates it (confirm dialog).
    await sp.getByRole('button', { name: 'Deactivate product' }).click();
    const deactivated = isApi(sp, 'POST', (p) => /\/api\/v1\/admin\/products\/\d+\/deactivate$/.test(p));
    await sp.getByRole('dialog').getByRole('button', { name: 'Deactivate' }).click();
    expect((await deactivated).status()).toBe(200);
    await expect(sp.getByRole('button', { name: 'Activate product' })).toBeVisible();

    // Gone from the customer catalog.
    await cp.reload();
    await expect(cp.getByText('No products match your search.')).toBeVisible();
    await expect(cp.getByRole('row', { name: new RegExp(partNumber) })).toHaveCount(0);
  } finally {
    await Promise.all([staff.context.close(), customer.context.close()]);
  }
});
