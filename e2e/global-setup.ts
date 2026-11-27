import { execFileSync } from 'node:child_process';
import * as fs from 'node:fs';
import { request } from '@playwright/test';
import { getJson, login, send, writeProduct } from './helpers/api';
import { API_DIR, API_ENV, AUTH_DIR, DB_NAME, PHP, USERS, WEB_URL, statePath, type Role } from './helpers/env';

const artisan = (...args: string[]) =>
  execFileSync(PHP, ['artisan', ...args], {
    cwd: API_DIR,
    env: { ...process.env, ...API_ENV },
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 300_000,
  });

export default async function globalSetup() {
  // 1. Safety guard: never reseed anything but the e2e database, whatever api/.env or a cached config says.
  const probe =
    "echo config('database.default').'|'.config('database.connections.pgsql.host').'|'.config('database.connections.pgsql.port').'|'.app('db')->connection()->getDatabaseName();";
  const seen = artisan('tinker', `--execute=${probe}`).trim().split(/\r?\n/).pop();
  const expected = `pgsql|127.0.0.1|${API_ENV.DB_PORT}|${DB_NAME}`;
  if (seen !== expected)
    throw new Error(`e2e safety guard: expected the connection "${expected}" but the API resolves "${seen}". Nothing was changed.`);

  // 2. Known state on every run: demo users, catalog, orders, admin demo data.
  artisan('migrate:fresh', '--seed', '--force');

  // 3. One storageState per role through the real login flow (through the Vite proxy, like the browser).
  fs.rmSync(AUTH_DIR, { recursive: true, force: true });
  fs.mkdirSync(AUTH_DIR, { recursive: true });
  for (const role of Object.keys(USERS) as Role[]) {
    const ctx = await request.newContext({ baseURL: WEB_URL });
    await login(ctx, USERS[role].email);
    await ctx.storageState({ path: statePath(role) });
    await ctx.dispose();
  }

  // 4. The product the order test buys, created through the new admin API (exercises it on every run).
  const staff = await request.newContext({ baseURL: WEB_URL, storageState: statePath('staff') });
  const category = (await getJson(staff, '/api/v1/categories')).data[0];
  const res = await send(staff, 'POST', '/api/v1/admin/products', {
    category_id: category.id,
    part_number: 'E2E-0001',
    name: 'E2E test bearing',
    description: null,
    brand: 'E2E',
    material: 'Chrome steel',
    inner_diameter_mm: 10,
    outer_diameter_mm: 30,
    width_mm: 9,
    seal_type: 'open',
    stock_status: 'in_stock',
    price_on_request: false,
    price_cents: 1250,
  });
  if (res.status() !== 201) throw new Error(`creating E2E-0001 failed: ${res.status()} ${await res.text()}`);
  const body = await res.json();
  writeProduct({ id: body.data.id, part_number: body.data.part_number });
  await staff.dispose();
}
