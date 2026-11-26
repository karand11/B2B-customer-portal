import { customer, meOk, mockApi, renderApp } from '../../../test/utils';
import type { Req, Spec } from '../../../test/utils';
import type { Cart, CartLine } from '../api';
import type { OrderDetail } from '../../orders/api';
import {
  CATS,
  LIST,
  ME,
  P1,
  P2,
  P3,
  P4,
  filtersBody,
  listBody,
} from '../../catalog/__tests__/fixtures';
import type { Product } from '../../catalog/api';

export { ME, LIST, P1, P2, P3, P4 };
export const CART = 'GET /api/v1/cart';
export const ORDERS = 'GET /api/v1/orders';

export function line(
  p: Product,
  quantity: number,
  o: Partial<CartLine> = {},
): CartLine {
  const unit = p.price_cents;
  return {
    product_id: p.id,
    part_number: p.part_number,
    name: p.name,
    brand: p.brand,
    stock_status: p.stock_status,
    unit_price_cents: unit,
    quantity,
    line_total_cents: unit === null ? null : unit * quantity,
    available: true,
    unavailable_reason: null,
    ...o,
  };
}

/** Builds a CartResource the way the API does: subtotal only counts available lines. */
export function cartOf(items: CartLine[], currency = 'USD'): { data: Cart } {
  return {
    data: {
      currency,
      items,
      line_count: items.length,
      unit_count: items.reduce((n, i) => n + i.quantity, 0),
      subtotal_cents: items.reduce(
        (n, i) => n + (i.available ? (i.line_total_cents ?? 0) : 0),
        0,
      ),
      can_checkout: items.length > 0 && items.every((i) => i.available),
    },
  };
}

export const order = (o: Partial<OrderDetail> = {}): OrderDetail => ({
  number: 'ORD-K7Q2M-X9PA3',
  status: 'pending',
  currency: 'USD',
  subtotal_cents: 250100,
  tax_cents: 0,
  shipping_cents: 0,
  total_cents: 250100,
  line_count: 1,
  po_reference: null,
  quote_number: null,
  company: { id: 1, name: 'Northwind Machinery Ltd' },
  placed_by: { name: 'Alice Customer' },
  created_at: '2026-11-25T12:00:00Z',
  updated_at: '2026-11-25T12:00:00Z',
  items: [
    {
      product_id: 17,
      part_number: '6205-2RS',
      name: 'Deep groove ball bearing 6205-2RS 25x52x15',
      brand: 'SKF',
      unit_price_cents: 125050,
      quantity: 2,
      line_total_cents: 250100,
    },
  ],
  history: [
    {
      from_status: null,
      to_status: 'pending',
      actor: { name: 'Alice Customer', role: 'customer' },
      created_at: '2026-11-25T12:00:00Z',
    },
  ],
  allowed_transitions: ['cancelled'],
  invoice: null,
  can_invoice: false,
  ...o,
});

export const listOf = (
  orders: ReturnType<typeof order>[],
  meta: Partial<{
    current_page: number;
    last_page: number;
    total: number;
  }> = {},
) => ({
  data: orders.map((o) => ({
    number: o.number,
    status: o.status,
    currency: o.currency,
    subtotal_cents: o.subtotal_cents,
    tax_cents: o.tax_cents,
    shipping_cents: o.shipping_cents,
    total_cents: o.total_cents,
    line_count: o.line_count,
    po_reference: o.po_reference,
    quote_number: o.quote_number,
    company: o.company,
    placed_by: o.placed_by,
    created_at: o.created_at,
    updated_at: o.updated_at,
  })),
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: orders.length,
    from: orders.length ? 1 : null,
    to: orders.length || null,
    ...meta,
  },
});

/** A tiny in-memory API: the cart lives here so a "reload" sees the same server state. */
export function cartServer(initial: CartLine[] = [], currency = 'USD') {
  let items = [...initial];
  const catalog = [P1, P2, P3, P4];
  const body = (): Spec => ({ body: cartOf(items, currency) });
  const idOf = (r: Req) => Number(r.url.split('/').pop());
  return {
    get items() {
      return items;
    },
    set items(v: CartLine[]) {
      items = v;
    },
    routes: {
      [CART]: body,
      'POST /api/v1/cart/items': (r: Req): Spec => {
        const { product_id, quantity } = r.body as {
          product_id: number;
          quantity: number;
        };
        const have = items.find((i) => i.product_id === product_id);
        if (have) have.quantity += quantity;
        else {
          const p = catalog.find((c) => c.id === product_id)!;
          items.push(line(p, quantity));
        }
        items = items.map((i) =>
          line(
            catalog.find((c) => c.id === i.product_id)!,
            i.quantity,
          ),
        );
        return body();
      },
      'PATCH /api/v1/cart/items/17': (r: Req): Spec => {
        items = items.map((i) =>
          i.product_id === 17
            ? line(P1, (r.body as { quantity: number }).quantity)
            : i,
        );
        return body();
      },
      'DELETE /api/v1/cart/items/17': (r: Req): Spec => {
        items = items.filter((i) => i.product_id !== idOf(r));
        return body();
      },
      'DELETE /api/v1/cart': (): Spec => {
        items = [];
        return body();
      },
    },
  };
}

/** Customer app with catalog, cart and any extra routes. */
export function setupApp(
  path: string,
  routes: Parameters<typeof mockApi>[0] = {},
  user = customer,
) {
  const api = mockApi({
    [ME]: meOk(user),
    [LIST]: { body: listBody([P1, P2, P3, P4]) },
    'GET /api/v1/products/filters': { body: filtersBody() },
    'GET /api/v1/categories': { body: CATS },
    [CART]: { body: cartOf([]) },
    ...routes,
  });
  return { api, ...renderApp(path) };
}
