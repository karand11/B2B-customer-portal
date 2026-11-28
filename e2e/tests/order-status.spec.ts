import { type Page } from '@playwright/test';
import { expect, test } from '../helpers/fixtures';
import { actor, getJson, readProduct, send } from '../helpers/api';

const isApi = (page: Page, method: string, path: string) =>
  page.waitForResponse(
    (r) => r.request().method() === method && new URL(r.url()).pathname === path,
  );

test('staff changes order status, customer sees it', async ({ browser }) => {
  const product = readProduct();
  const customer = await actor(browser, 'customer');
  const staff = await actor(browser, 'staff');
  const admin = await actor(browser, 'admin');
  try {
    // 1-2. Customer: add to cart, check out with a unique PO reference.
    const cp = customer.page;
    await cp.goto(`/catalog?q=${product.part_number}`);
    const added = isApi(cp, 'POST', '/api/v1/cart/items');
    await cp
      .getByRole('row', { name: new RegExp(product.part_number) })
      .getByRole('button', { name: /add to cart/i })
      .click();
    expect((await added).status()).toBe(200);

    await cp.goto('/checkout');
    await cp.getByLabel(/PO reference/).fill(`E2E-${Date.now()}`);
    const placed = isApi(cp, 'POST', '/api/v1/orders');
    await cp.getByRole('button', { name: 'Place order' }).click();
    expect((await placed).status()).toBe(201);
    await cp.waitForURL(/\/orders\/ORD-[A-Z0-9-]+$/);
    const number = new URL(cp.url()).pathname.split('/').pop()!;

    // 3. Pending, one history entry.
    await expect(cp.getByRole('region', { name: 'Order summary' })).toContainText('Pending');
    await expect(
      cp.getByRole('list', { name: 'Status history' }).getByRole('listitem'),
    ).toHaveCount(1);

    // 4. Staff: find the order in the back office and confirm it.
    const sp = staff.page;
    await sp.goto(`/admin/orders?status=pending&q=${number}`);
    await sp.getByRole('link', { name: number }).click();
    await sp.waitForURL(`**/admin/orders/${number}`);
    await sp.getByRole('button', { name: 'Mark as confirmed' }).click();
    const patched = isApi(sp, 'PATCH', `/api/v1/orders/${number}/status`);
    await sp.getByRole('dialog').getByRole('button', { name: 'Confirm' }).click();
    expect((await patched).status()).toBe(200);
    await expect(sp.getByRole('region', { name: 'Order summary' })).toContainText('Confirmed');

    // 5. Customer reloads: new status and a second timeline entry.
    await cp.reload();
    await expect(cp.getByRole('region', { name: 'Order summary' })).toContainText('Confirmed');
    const history = cp.getByRole('list', { name: 'Status history' }).getByRole('listitem');
    await expect(history).toHaveCount(2);
    await expect(history.last()).toContainText('Confirmed');

    // 6. Admin: the audit log holds exactly one status-change row written by the staff actor.
    const audit = await getJson(
      admin.context,
      `/api/v1/admin/audit-log?action=order.status_changed&subject_id=${number}`,
    );
    expect(audit.data).toHaveLength(1);
    expect(audit.data[0].actor).toMatchObject({ name: 'Sam Staff', role: 'staff' });
    expect(audit.data[0].changes).toEqual({
      before: { status: 'pending' },
      after: { status: 'confirmed' },
    });

    const ap = admin.page;
    await ap.goto(`/admin/audit-log?action=order.status_changed&subject_id=${number}`);
    const row = ap.getByRole('table', { name: 'Audit log' }).getByRole('row', { name: /Sam Staff/ });
    await expect(row).toHaveCount(1);
    await expect(row).toContainText(number);

    // 7. Negative checks: a customer cannot change status; a repeated confirm is refused.
    const forbidden = await send(customer.context, 'PATCH', `/api/v1/orders/${number}/status`, {
      status: 'shipped',
    });
    expect(forbidden.status()).toBe(403);
    const again = await send(staff.context, 'PATCH', `/api/v1/orders/${number}/status`, {
      status: 'confirmed',
    });
    expect(again.status()).toBe(422);
    await cp.reload();
    await expect(cp.getByRole('region', { name: 'Order summary' })).toContainText('Confirmed');
  } finally {
    await Promise.all([customer.context.close(), staff.context.close(), admin.context.close()]);
  }
});
