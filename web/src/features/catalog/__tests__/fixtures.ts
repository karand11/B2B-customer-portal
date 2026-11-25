import { screen } from '@testing-library/react';
import {
  customer,
  meOk,
  mockApi,
  renderApp,
  type Req,
  type Spec,
} from '../../../test/utils';
import type { Filters, Product } from '../api';

export const ME = 'GET /api/v1/me';
export const LIST = 'GET /api/v1/products';

export const product = (o: Partial<Product> = {}): Product => ({
  id: 17,
  part_number: '6205-2RS',
  name: 'Deep groove ball bearing 6205-2RS 25x52x15',
  brand: 'SKF',
  category: {
    id: 1,
    slug: 'deep-groove-ball',
    name: 'Deep groove ball bearings',
  },
  inner_diameter_mm: 25,
  outer_diameter_mm: 52,
  width_mm: 15,
  material: 'Chrome steel',
  seal_type: '2rs',
  price_cents: 125050,
  price_on_request: false,
  stock_status: 'in_stock',
  ...o,
});

export const P1 = product();
export const P2 = product({
  id: 18,
  part_number: 'NU206',
  brand: 'FAG',
  category: {
    id: 4,
    slug: 'cylindrical-roller',
    name: 'Cylindrical roller bearings',
  },
  inner_diameter_mm: 30,
  outer_diameter_mm: 62,
  width_mm: 16,
  seal_type: 'open',
  price_cents: null,
  price_on_request: true,
  stock_status: 'on_order',
});
export const P3 = product({
  id: 19,
  part_number: '30205',
  brand: 'NSK',
  width_mm: 16.25,
  seal_type: 'zz',
  price_cents: 990,
  stock_status: 'low_stock',
});
export const P4 = product({
  id: 20,
  part_number: '6305',
  price_cents: 5000,
  stock_status: 'out_of_stock',
});

export const filtersBody = (currency = 'USD'): { data: Filters } => ({
  data: {
    currency,
    brands: ['FAG', 'NSK', 'SKF'],
    materials: ['Chrome steel', 'Stainless steel'],
    seal_types: ['open', '2rs', 'zz'],
    stock_statuses: ['in_stock', 'low_stock', 'out_of_stock', 'on_order'],
    ranges: {
      inner_diameter_mm: { min: 3, max: 120 },
      outer_diameter_mm: { min: 10, max: 215 },
      width_mm: { min: 3, max: 60 },
    },
  },
});

export const CATS = {
  data: [
    {
      id: 1,
      slug: 'deep-groove-ball',
      name: 'Deep groove ball bearings',
      product_count: 3,
    },
    {
      id: 2,
      slug: 'tapered-roller',
      name: 'Tapered roller bearings',
      product_count: 1,
    },
  ],
};

export const listBody = (
  items: Product[],
  meta: Partial<{
    current_page: number;
    last_page: number;
    total: number;
  }> = {},
) => ({
  data: items,
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 24,
    total: items.length,
    from: items.length ? 1 : null,
    to: items.length || null,
    ...meta,
  },
});

type Routes = Parameters<typeof mockApi>[0];
export type Handler = Spec | ((req: Req) => Spec | Promise<Spec>);

export function setup(path: string, routes: Routes = {}) {
  const api = mockApi({
    [ME]: meOk(customer),
    [LIST]: { body: listBody([P1, P2, P3]) },
    'GET /api/v1/products/filters': { body: filtersBody() },
    'GET /api/v1/categories': { body: CATS },
    ...routes,
  });
  const app = renderApp(path);
  return { api, ...app };
}

/** URLs of every list request (not filters, not detail). */
export const listUrls = (api: ReturnType<typeof mockApi>) =>
  api.calls
    .map((c) => c.url)
    .filter(
      (u) => u === '/api/v1/products' || u.startsWith('/api/v1/products?'),
    );

export const loc = () => screen.getByTestId('loc').textContent;

export const deferred = <T>() => {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((r) => (resolve = r));
  return { promise, resolve };
};
