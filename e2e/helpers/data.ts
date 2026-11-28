import { expect, request, type APIRequestContext } from '@playwright/test';
import { getJson, login, send } from './api';
import { PASSWORD, USERS, WEB_URL, statePath, type Role } from './env';

/** An API client holding the saved session of a demo role (no browser). */
export const apiAs = (role: Role) => request.newContext({ baseURL: WEB_URL, storageState: statePath(role) });

/** An API client with a fresh login (own session, independent of the saved states). */
export async function apiLogin(email: string, password = PASSWORD) {
  const ctx = await request.newContext({ baseURL: WEB_URL });
  await login(ctx, email, password);
  return ctx;
}
export const apiCustomer = () => apiLogin(USERS.customer.email);

/** Unique and short: base 36 timestamp plus 3 random characters, upper case (part numbers are upper-cased by the API). */
export const stamp = () => `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`.toUpperCase();

export interface JourneyProduct {
  id: number;
  part_number: string;
  name: string;
  brand: string;
  price_cents: number;
}

/** Creates an in-stock, priced product through the admin API. Unique per call, so tests never share one. */
export async function createProduct(
  staff: APIRequestContext,
  { prefix, price_cents = 1000, brand = 'JourneyBrand' }: { prefix: string; price_cents?: number; brand?: string },
): Promise<JourneyProduct> {
  const category = (await getJson(staff, '/api/v1/categories')).data[0];
  const part_number = `${prefix}-${stamp()}`;
  const name = `Journey bearing ${part_number}`;
  const res = await send(staff, 'POST', '/api/v1/admin/products', {
    category_id: category.id,
    part_number,
    name,
    description: null,
    brand,
    material: 'Chrome steel',
    inner_diameter_mm: 11,
    outer_diameter_mm: 31,
    width_mm: 8,
    seal_type: 'open',
    stock_status: 'in_stock',
    price_on_request: false,
    price_cents,
  });
  expect(res.status(), await res.text()).toBe(201);
  const p = (await res.json()).data;
  return { id: p.id, part_number: p.part_number, name: p.name, brand: p.brand, price_cents: p.price_cents };
}

/** Full-replacement update of a product through the admin API (the current version is read first). `patch` wins over the current values. */
export async function updateProduct(staff: APIRequestContext, id: number, patch: Record<string, unknown>) {
  const cur = (await getJson(staff, `/api/v1/admin/products/${id}`)).data;
  const body = {
    category_id: cur.category.id,
    part_number: cur.part_number,
    name: cur.name,
    description: cur.description,
    brand: cur.brand,
    material: cur.material,
    inner_diameter_mm: cur.inner_diameter_mm,
    outer_diameter_mm: cur.outer_diameter_mm,
    width_mm: cur.width_mm,
    seal_type: cur.seal_type,
    stock_status: cur.stock_status,
    price_on_request: cur.price_on_request,
    price_cents: cur.price_cents,
    version: cur.version,
    ...patch,
  };
  const res = await send(staff, 'PUT', `/api/v1/admin/products/${id}`, body);
  expect(res.status(), await res.text()).toBe(200);
  return (await res.json()).data;
}

/** Empties the signed-in customer's cart. */
export async function clearCart(customer: APIRequestContext) {
  const res = await send(customer, 'DELETE', '/api/v1/cart');
  expect(res.status()).toBe(200);
}

export async function addToCart(customer: APIRequestContext, product_id: number, quantity: number) {
  const res = await send(customer, 'POST', '/api/v1/cart/items', { product_id, quantity });
  expect(res.status(), await res.text()).toBe(200);
}

/** Places an order for the current cart and returns the order number. */
export async function placeOrder(customer: APIRequestContext, po_reference: string): Promise<string> {
  const res = await send(customer, 'POST', '/api/v1/orders', { po_reference });
  expect(res.status(), await res.text()).toBe(201);
  return (await res.json()).data.number;
}

export async function orderCount(customer: APIRequestContext): Promise<number> {
  return (await getJson(customer, '/api/v1/orders?per_page=1')).meta.total;
}

/** UTC date `YYYY-MM-DD`, `days` from today. */
export const utcDate = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);

export async function setOrderStatus(staff: APIRequestContext, number: string, status: string) {
  const res = await send(staff, 'PATCH', `/api/v1/orders/${number}/status`, { status });
  expect(res.status(), await res.text()).toBe(200);
}

/** en-US currency exactly like the SPA's formatMoney (cents in, "$1,234.50" out). */
export const money = (cents: number, currency = 'USD') =>
  new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(cents / 100);

/** A tiny but valid one-page PDF (libmagic and the upload inspector accept it). */
export function tinyPdf(text = 'E2E attachment'): Buffer {
  const stream = `BT /F1 12 Tf 20 50 Td (${text}) Tj ET`;
  const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 100] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];
  let out = '%PDF-1.4\n';
  const offsets: number[] = [];
  objs.forEach((o, i) => {
    offsets.push(out.length);
    out += `${i + 1} 0 obj\n${o}\nendobj\n`;
  });
  const xref = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n`;
  offsets.forEach((o) => (out += `${String(o).padStart(10, '0')} 00000 n \n`));
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(out, 'latin1');
}
