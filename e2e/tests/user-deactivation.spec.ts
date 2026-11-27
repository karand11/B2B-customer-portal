import { expect, test, type Page } from '@playwright/test';
import { actor, actorFor, get, getJson, send } from '../helpers/api';
import { MAILPIT_URL, PASSWORD, USERS } from '../helpers/env';

const BOB = { email: 'bob@northwind.test', name: 'Bob Buyer' };

const isApi = (page: Page, method: string, matches: RegExp) =>
  page.waitForResponse(
    (r) => r.request().method() === method && matches.test(new URL(r.url()).pathname),
  );

test('admin deactivates a user and their open session is sent to login', async ({ browser }) => {
  const admin = await actor(browser, 'admin');
  const bob = await actorFor(browser, BOB.email); // an already-open session
  let bobId: number | undefined;
  try {
    await bob.page.goto('/orders');
    await expect(bob.page.getByRole('heading', { name: /^Orders for/ })).toBeVisible();

    // Admin deactivates Bob in the users table.
    const ap = admin.page;
    await ap.goto(`/admin/users?q=${BOB.email}`);
    const row = ap.getByRole('table', { name: 'Users' }).getByRole('row', { name: /Bob Buyer/ });
    await expect(row).toHaveCount(1);
    await row.getByRole('button', { name: `Deactivate ${BOB.name}` }).click();
    const done = isApi(ap, 'POST', /\/api\/v1\/admin\/users\/(\d+)\/deactivate$/);
    await ap.getByRole('dialog').getByRole('button', { name: 'Deactivate' }).click();
    const res = await done;
    expect(res.status()).toBe(200);
    bobId = Number(new URL(res.url()).pathname.match(/users\/(\d+)\//)![1]);
    await expect(row).toContainText('Inactive');

    // Bob's next action sends him to the login page.
    await bob.page.getByRole('link', { name: 'Quotes' }).click();
    await expect(bob.page).toHaveURL(/\/login(\?|$)/);
    expect((await get(bob.context, '/api/v1/me')).status()).toBe(401);

    // He cannot sign in again (same message as a wrong password).
    await bob.page.getByLabel('Email').fill(BOB.email);
    await bob.page.getByLabel('Password').fill(PASSWORD);
    await bob.page.getByRole('button', { name: 'Sign in' }).click();
    await expect(bob.page.getByRole('alert')).toBeVisible();
    await expect(bob.page).toHaveURL(/\/login/);

    // The audit log names the admin.
    const audit = await getJson(admin.context, `/api/v1/admin/audit-log?action=user.deactivated&subject_id=${bobId}`);
    expect(audit.data).toHaveLength(1);
    expect(audit.data[0].actor).toMatchObject({ name: USERS.admin.name, role: 'admin' });
  } finally {
    if (bobId) await send(admin.context, 'POST', `/api/v1/admin/users/${bobId}/reactivate`); // keep the demo user usable
    await Promise.all([admin.context.close(), bob.context.close()]);
  }
});

// Needs Mailpit (the invitation link only exists in the mail). Skipped locally when Mailpit is down; required in CI.
test.describe('invitation by email', () => {
  test.beforeAll(async ({ request }) => {
    const up = await request.get(`${MAILPIT_URL}/api/v1/info`, { timeout: 3_000 }).then((r) => r.ok(), () => false);
    if (process.env.CI) expect(up, 'Mailpit must be running in CI').toBe(true);
    else test.skip(!up, `Mailpit is not reachable at ${MAILPIT_URL}`);
  });

  test('invited user accepts, signs in, is deactivated and loses the session', async ({ browser, request, page }) => {
    const email = `e2e-invite-${Date.now()}@northwind.test`;
    const password = 'E2e-Invite-Passw0rd!';
    const admin = await actor(browser, 'admin');
    try {
      const companyId = (await getJson(admin.context, '/api/v1/admin/users?q=alice@northwind.test')).data[0].company.id;
      const invited = await send(admin.context, 'POST', '/api/v1/admin/users', {
        name: 'Ivy Invited', email, role: 'customer', company_id: companyId,
      });
      expect(invited.status()).toBe(201);
      const userId = (await invited.json()).data.id;
      expect((await invited.json()).meta.email_queued).toBe(true);

      // The mail arrives in Mailpit (sync queue: already sent when the API answered).
      let link = '';
      await expect
        .poll(async () => {
          const found = await (await request.get(`${MAILPIT_URL}/api/v1/search`, { params: { query: `to:${email}` } })).json();
          if (!found.messages?.length) return '';
          const msg = await (await request.get(`${MAILPIT_URL}/api/v1/message/${found.messages[0].ID}`)).json();
          link = /https?:\/\/[^\s"<>]*accept-invitation\?[^\s"<>]+/.exec(msg.Text ?? '')?.[0] ?? '';
          return link;
        })
        .not.toBe('');

      await page.goto(link.replace(/&amp;/g, '&'));
      await page.getByLabel('New password', { exact: true }).fill(password);
      await page.getByLabel('Confirm new password').fill(password);
      await page.getByRole('button', { name: 'Set password' }).click();
      await expect(page).toHaveURL(/\/login/);

      await page.getByLabel('Email').fill(email);
      await page.getByLabel('Password').fill(password);
      await page.getByRole('button', { name: 'Sign in' }).click();
      await expect(page.getByRole('navigation', { name: 'Main' })).toBeVisible();

      expect((await send(admin.context, 'POST', `/api/v1/admin/users/${userId}/deactivate`)).status()).toBe(200);
      await page.getByRole('link', { name: 'Quotes' }).click();
      await expect(page).toHaveURL(/\/login(\?|$)/);
    } finally {
      await admin.context.close();
    }
  });
});
