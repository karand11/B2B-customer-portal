import { admin, meOk, staff } from '../../../test/utils';
import { ME, setupApp } from '../../cart/__tests__/fixtures';
import type { Quote, QuoteDetail, QuoteItem } from '../api';

export { ME, setupApp };
export const NUM = 'QUO-K7Q2M-X9PA3';
export const DETAIL = `GET /api/v1/quotes/${NUM}`;
export const LIST = 'GET /api/v1/quotes';
export const CREATE = 'POST /api/v1/quotes';
export const ORDER = 'GET /api/v1/orders/ORD-K7Q2M-X9PA3';

/** YYYY-MM-DD, `days` from now in UTC (the API's rule). */
export const day = (days: number) =>
  new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);

export const catalogItem = (o: Partial<QuoteItem> = {}): QuoteItem => ({
  position: 1,
  kind: 'catalog',
  product_id: 17,
  part_number: '6205-2RS',
  name: 'Deep groove ball bearing 6205-2RS 25x52x15',
  brand: 'SKF',
  quantity: 10,
  unit_price_cents: null,
  line_total_cents: null,
  ...o,
});
export const customItem = (o: Partial<QuoteItem> = {}): QuoteItem => ({
  position: 2,
  kind: 'custom',
  product_id: null,
  part_number: null,
  name: 'Taper roller bearing 32208, SKF or equivalent',
  brand: null,
  quantity: 4,
  unit_price_cents: null,
  line_total_cents: null,
  ...o,
});

/** A customer's view of a freshly submitted quote (nothing priced, nothing hidden is present). */
export const quote = (o: Partial<QuoteDetail> = {}): QuoteDetail => ({
  number: NUM,
  status: 'submitted',
  currency: 'USD',
  line_count: 2,
  po_reference: null,
  subtotal_cents: null,
  tax_cents: 0,
  shipping_cents: 0,
  total_cents: null,
  valid_until: null,
  company: { id: 1, name: 'Northwind Machinery Ltd' },
  requested_by: { name: 'Alice Customer' },
  created_at: '2026-11-25T10:15:00Z',
  updated_at: '2026-11-25T10:15:00Z',
  customer_note: null,
  staff_note: null,
  revision: null,
  expires_at: null,
  items: [catalogItem(), customItem()],
  history: [
    {
      from_status: null,
      to_status: 'submitted',
      actor: { name: 'Alice Customer', role: 'customer' },
      note: null,
      created_at: '2026-11-25T10:15:00Z',
    },
  ],
  attachments: [],
  order: null,
  allowed_actions: ['decline', 'attach'],
  ...o,
});

/** A sent quote, fully priced: 10 x 12.50 + 4 x 38.75 = 280.00. */
export const quoted = (o: Partial<QuoteDetail> = {}): QuoteDetail =>
  quote({
    status: 'quoted',
    subtotal_cents: 28000,
    total_cents: 28000,
    valid_until: day(30),
    expires_at: `${day(31)}T00:00:00Z`,
    revision: 1,
    staff_note: 'Lead time 3 weeks, ex works.',
    items: [
      catalogItem({ unit_price_cents: 1250, line_total_cents: 12500 }),
      customItem({ unit_price_cents: 3875, line_total_cents: 15500 }),
    ],
    history: [
      quote().history[0],
      {
        from_status: 'submitted',
        to_status: 'in_review',
        actor: { name: null, role: 'staff' },
        note: null,
        created_at: '2026-11-25T11:00:00Z',
      },
      {
        from_status: 'in_review',
        to_status: 'quoted',
        actor: { name: null, role: 'staff' },
        note: null,
        created_at: '2026-11-25T12:00:00Z',
      },
    ],
    allowed_actions: ['accept', 'decline'],
    ...o,
  });

/** The staff view of the same quote, with the internal note. */
export const forStaff = (q: QuoteDetail, o: Partial<QuoteDetail> = {}) => ({
  ...q,
  internal_note: null,
  allowed_actions: ['note' as const],
  ...o,
});

export const detail = (q: QuoteDetail) => ({ body: { data: q } });

const summary = (q: QuoteDetail): Quote => ({
  number: q.number,
  status: q.status,
  currency: q.currency,
  line_count: q.line_count,
  po_reference: q.po_reference,
  subtotal_cents: q.subtotal_cents,
  tax_cents: q.tax_cents,
  shipping_cents: q.shipping_cents,
  total_cents: q.total_cents,
  valid_until: q.valid_until,
  company: q.company,
  requested_by: q.requested_by,
  created_at: q.created_at,
  updated_at: q.updated_at,
});

export const listOf = (
  quotes: QuoteDetail[],
  meta: Partial<{
    current_page: number;
    last_page: number;
    total: number;
  }> = {},
) => ({
  data: quotes.map(summary),
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: quotes.length,
    from: quotes.length ? 1 : null,
    to: quotes.length || null,
    ...meta,
  },
});

export const asStaff = { [ME]: meOk(staff) };
export const asAdmin = { [ME]: meOk(admin) };

export const unprocessable = (errors: Record<string, string[]>) => ({
  status: 422,
  body: { message: 'The given data was invalid.', errors },
});

export const pdf = (name = 'drawing.pdf', size = 1000) => {
  const f = new File(['x'], name, { type: 'application/pdf' });
  Object.defineProperty(f, 'size', { value: size });
  return f;
};
