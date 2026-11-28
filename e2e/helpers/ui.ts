import { type Locator, type Page, type Response } from '@playwright/test';

/** Resolves with the next response of `method` whose path matches. Create it BEFORE the click that triggers the call. */
export const apiResponse = (page: Page, method: string, path: RegExp | string): Promise<Response> =>
  page.waitForResponse((r) => {
    if (r.request().method() !== method) return false;
    const p = new URL(r.url()).pathname;
    return typeof path === 'string' ? p === path : path.test(p);
  });

const escapeRe = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** The catalog or list row whose part-number link is exactly `partNumber` ("6205" must not match "6205-2RS"). */
export const productRow = (page: Page, partNumber: string): Locator =>
  page.getByRole('row').filter({ has: page.getByRole('link', { name: partNumber, exact: true }) });

/** A regular expression that matches the text exactly as a literal. */
export const literal = (s: string) => new RegExp(escapeRe(s));

export const mainNav = (page: Page) => page.getByRole('navigation', { name: 'Main' });
export const backOffice = (page: Page) => page.getByRole('navigation', { name: 'Back office' });

/** Signs in through the real form and waits for the redirect away from /login. */
export async function loginThroughForm(page: Page, email: string, password: string) {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  const login = apiResponse(page, 'POST', '/api/v1/auth/login');
  await page.getByRole('button', { name: 'Sign in' }).click();
  const res = await login;
  if (res.status() !== 200) throw new Error(`form login failed: ${res.status()} ${await res.text()}`);
  await page.waitForURL((u) => !u.pathname.startsWith('/login'));
}
