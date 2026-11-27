import * as fs from 'node:fs';
import * as path from 'node:path';
import {
  expect,
  type APIRequestContext,
  type APIResponse,
  type Browser,
  type BrowserContext,
  type Page,
} from '@playwright/test';
import { DATA_FILE, PASSWORD, USERS, WEB_URL, statePath, type Role } from './env';

// Both a BrowserContext and an APIRequestContext expose the cookie jar through storageState().
type Jar = Pick<APIRequestContext, 'storageState'>;

async function xsrfHeaders(jar: Jar) {
  const { cookies } = await jar.storageState();
  const token = cookies.find((c) => c.name === 'XSRF-TOKEN')?.value;
  if (!token) throw new Error('No XSRF-TOKEN cookie: call login() first');
  return {
    'X-XSRF-TOKEN': decodeURIComponent(token),
    Accept: 'application/json',
    Origin: WEB_URL, // Sanctum only starts a session for requests from a stateful origin
  };
}

/** Real SPA login flow over the API: CSRF cookie, then POST /auth/login with the XSRF header. */
export async function login(request: APIRequestContext, email: string, password = PASSWORD) {
  await request.get(`${WEB_URL}/sanctum/csrf-cookie`, { headers: { Origin: WEB_URL } });
  const res = await request.post(`${WEB_URL}/api/v1/auth/login`, {
    headers: await xsrfHeaders(request),
    data: { email, password },
  });
  expect(res.status(), `login ${email}: ${await res.text()}`).toBe(200);
}

/** Write call (POST/PUT/PATCH/DELETE) from an authenticated context, with the CSRF header. */
export async function send(
  context: BrowserContext | APIRequestContext,
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  url: string,
  data?: unknown,
): Promise<APIResponse> {
  const request = 'request' in context ? context.request : context;
  return request.fetch(url, { method, headers: await xsrfHeaders(context), data });
}

/** GET with the Origin header Sanctum needs to treat the call as part of the SPA session. */
export async function get(context: BrowserContext | APIRequestContext, url: string): Promise<APIResponse> {
  const request = 'request' in context ? context.request : context;
  return request.get(url, { headers: { Accept: 'application/json', Origin: WEB_URL } });
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export async function getJson(context: BrowserContext | APIRequestContext, url: string): Promise<any> {
  const res = await get(context, url);
  expect(res.status(), `GET ${url}: ${await res.text()}`).toBe(200);
  return res.json();
}

export interface E2eProduct {
  id: number;
  part_number: string;
}
export const readProduct = (): E2eProduct => JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
export const writeProduct = (p: E2eProduct) => {
  fs.mkdirSync(path.dirname(DATA_FILE), { recursive: true });
  fs.writeFileSync(DATA_FILE, JSON.stringify(p));
};

/** A browser context signed in as a demo role from the storageState written by global-setup. */
export async function actor(browser: Browser, role: Role): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL: WEB_URL, storageState: statePath(role) });
  return { context, page: await context.newPage() };
}

/** A browser context signed in as any user (fresh session, independent of the saved states). */
export async function actorFor(browser: Browser, email: string, password = PASSWORD) {
  const context = await browser.newContext({ baseURL: WEB_URL });
  await login(context.request, email, password);
  return { context, page: await context.newPage() };
}

export const roleEmail = (role: Role) => USERS[role].email;
