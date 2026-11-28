import { type Page } from '@playwright/test';
import { expect, test } from '../helpers/fixtures';
import { actor, get, send } from '../helpers/api';
import { PASSWORD, USERS } from '../helpers/env';

const backOffice = (page: Page) => page.getByRole('navigation', { name: 'Back office' });

test('login through the UI: guest on /admin is sent to login and returns after signing in', async ({
  page,
}) => {
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/login\?next=%2Fadmin$/);

  await page.getByLabel('Email').fill(USERS.staff.email);
  await page.getByLabel('Password').fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();

  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
});

test('guest API calls on admin endpoints are 401', async ({ request }) => {
  for (const url of ['/api/v1/admin/dashboard', '/api/v1/admin/users', '/api/v1/admin/audit-log']) {
    expect((await get(request, url)).status(), url).toBe(401);
  }
});

test('customer has no back office', async ({ browser }) => {
  const { context, page } = await actor(browser, 'customer');
  try {
    await page.goto('/');
    await expect(page.getByRole('navigation', { name: 'Main' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back office' })).toHaveCount(0);
    await expect(backOffice(page)).toHaveCount(0);

    for (const path of ['/admin', '/admin/products', '/admin/users']) {
      await page.goto(path);
      await expect(page.getByRole('heading', { name: 'Not allowed' }), path).toBeVisible();
      await expect(backOffice(page)).toHaveCount(0);
    }
    // Hiding is not security: the API refuses too.
    for (const url of [
      '/api/v1/admin/dashboard',
      '/api/v1/admin/products',
      '/api/v1/admin/users',
      '/api/v1/admin/audit-log',
    ]) {
      expect((await get(context, url)).status(), url).toBe(403);
    }
    expect((await send(context, 'POST', '/api/v1/admin/products', { name: 'x' })).status()).toBe(403);
  } finally {
    await context.close();
  }
});

test('staff sees the dashboard but not Users or Audit log', async ({ browser }) => {
  const { context, page } = await actor(browser, 'staff');
  try {
    await page.goto('/admin');
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    const nav = backOffice(page);
    for (const name of ['Dashboard', 'Orders', 'Quotes', 'Invoices', 'Products', 'Companies'])
      await expect(nav.getByRole('link', { name })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Users' })).toHaveCount(0);
    await expect(nav.getByRole('link', { name: 'Audit log' })).toHaveCount(0);

    for (const path of ['/admin/users', '/admin/audit-log']) {
      await page.goto(path);
      await expect(page.getByRole('heading', { name: 'Not allowed' }), path).toBeVisible();
    }
    for (const url of ['/api/v1/admin/users', '/api/v1/admin/audit-log']) {
      expect((await get(context, url)).status(), url).toBe(403);
    }
  } finally {
    await context.close();
  }
});

test('admin sees Users and Audit log', async ({ browser }) => {
  const { context, page } = await actor(browser, 'admin');
  try {
    await page.goto('/admin');
    const nav = backOffice(page);
    await expect(nav.getByRole('link', { name: 'Users' })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Audit log' })).toBeVisible();

    await nav.getByRole('link', { name: 'Users' }).click();
    await expect(page.getByRole('table', { name: 'Users' })).toBeVisible();
    await nav.getByRole('link', { name: 'Audit log' }).click();
    await expect(page.getByRole('table', { name: 'Audit log' })).toBeVisible();
  } finally {
    await context.close();
  }
});

test('a deactivated account gets the same sign-in message as an unknown email', async ({ page }) => {
  const message = async (email: string) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    const alert = page.getByRole('alert');
    await expect(alert).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
    return alert.innerText();
  };
  const inactive = await message('dave@northwind.test'); // seeded inactive customer
  const unknown = await message('nobody@northwind.test');
  expect(inactive.trim()).not.toBe('');
  expect(inactive).toBe(unknown);
});
