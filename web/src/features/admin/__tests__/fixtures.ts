import { screen } from '@testing-library/react';
import {
  admin,
  meOk,
  mockApi,
  renderApp,
  staff,
  type Spec,
} from '../../../test/utils';
import type {
  AdminProduct,
  AuditEntry,
  CompanyDetail,
  Dashboard,
  ImportPreview,
  Meta,
  UserAdmin,
} from '../api';

export const ME = 'GET /api/v1/me';
export const DASH_URL = 'GET /api/v1/admin/dashboard';
export const PRODUCTS = 'GET /api/v1/admin/products';
export const COMPANIES = 'GET /api/v1/admin/companies';
export const USERS = 'GET /api/v1/admin/users';
export const AUDIT = 'GET /api/v1/admin/audit-log';
export const CATEGORIES = 'GET /api/v1/categories';

export const DASH: Dashboard = {
  generated_at: '2026-11-27T10:15:00Z',
  week_start: '2026-11-23',
  currency: 'USD',
  quotes: { open: 4, awaiting_customer: 2, expiring_soon: 1 },
  orders: { this_week: 12, pending: 3 },
  invoices: {
    overdue_count: 2,
    overdue_total_cents: 254848,
    due_soon_count: 3,
    due_soon_total_cents: 120000,
  },
  products: { inactive: 3 },
};
export const dashOk: Spec = { body: { data: DASH } };

export const meta = (total: number, page = 1, last = 1): Meta => ({
  current_page: page,
  last_page: last,
  per_page: 25,
  total,
  from: total ? 1 : null,
  to: total ? total : null,
});
export const paged = <T>(data: T[], m?: Partial<Meta>): Spec => ({
  body: { data, meta: { ...meta(data.length), ...m } },
});

export const CATS = {
  body: {
    data: [
      {
        id: 1,
        slug: 'deep-groove-ball',
        name: 'Deep groove ball bearings',
        product_count: 10,
      },
      {
        id: 2,
        slug: 'tapered-roller',
        name: 'Tapered roller',
        product_count: 4,
      },
    ],
  },
};

export const product = (o: Partial<AdminProduct> = {}): AdminProduct => ({
  id: 17,
  part_number: '6205-2RS',
  name: 'Deep groove ball bearing 6205-2RS 25x52x15',
  description: null,
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
  price_cents: 1250,
  price_on_request: false,
  stock_status: 'in_stock',
  active: true,
  version: 3,
  created_at: '2026-11-26T10:00:00Z',
  updated_at: '2026-11-27T09:00:00Z',
  ...o,
});

export const company = (o: Partial<CompanyDetail> = {}): CompanyDetail => ({
  id: 1,
  name: 'Northwind Machinery Ltd',
  tax_id: 'NW-TAX-0001',
  has_billing_address: true,
  version: 2,
  users_count: 2,
  open_orders_count: 3,
  open_quotes_count: 1,
  unpaid_invoices_count: 2,
  created_at: '2026-11-25T10:00:00Z',
  billing_address: '1 Main St\nSpringfield',
  users: [
    {
      id: 7,
      name: 'Dave Dealer',
      email: 'dave@northwind.test',
      role: 'customer',
      status: 'active',
    },
  ],
  recent: {
    orders: [
      {
        number: 'ORD-K7Q2M-X9PA3',
        status: 'pending',
        total_cents: 250100,
        currency: 'USD',
        created_at: '2026-11-26T10:00:00Z',
      },
    ],
    quotes: [
      {
        number: 'QUO-K7Q2M-X9PA3',
        status: 'quoted',
        created_at: '2026-11-26T10:00:00Z',
      },
    ],
    invoices: [
      {
        id: '3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90',
        number: 'INV-2026-000001',
        status: 'open',
        total_cents: 120000,
        currency: 'USD',
        due_date: '2026-12-10',
      },
    ],
  },
  ...o,
});

export const user = (o: Partial<UserAdmin> = {}): UserAdmin => ({
  id: 7,
  name: 'Dave Dealer',
  email: 'dave@northwind.test',
  role: 'customer',
  company: { id: 1, name: 'Northwind Machinery Ltd' },
  status: 'active',
  invited_at: null,
  invitation_accepted_at: null,
  created_at: '2026-11-25T10:00:00Z',
  allowed_actions: ['edit', 'assign', 'deactivate', 'reset_password'],
  ...o,
});

export const entry = (o: Partial<AuditEntry> = {}): AuditEntry => ({
  id: 981,
  occurred_at: '2026-11-27T10:15:00Z',
  action: 'order.status_changed',
  actor: { id: 2, name: 'Sam Staff', role: 'staff' },
  subject: {
    type: 'order',
    id: 'ORD-K7Q2M-X9PA3',
    label: 'ORD-K7Q2M-X9PA3',
  },
  company: { id: 1, name: 'Northwind Machinery Ltd' },
  changes: { before: { status: 'pending' }, after: { status: 'confirmed' } },
  metadata: {},
  ...o,
});

export const preview = (o: Partial<ImportPreview> = {}): ImportPreview => ({
  id: '3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90',
  file_name: 'catalog.csv',
  size_bytes: 123456,
  status: 'previewed',
  expires_at: '2026-11-27T11:15:00Z',
  rows_read: 200,
  would_create: 150,
  would_update: 47,
  bad_rows: 0,
  errors: [],
  errors_truncated: false,
  warnings: [],
  ...o,
});

/** Mounts the whole app at `path` as `who` with the given API routes. */
export function setupAdmin(
  path: string,
  routes: Parameters<typeof mockApi>[0] = {},
  who = staff,
) {
  const api = mockApi({
    [ME]: meOk(who),
    [CATEGORIES]: CATS,
    ...routes,
  });
  return { api, ...renderApp(path) };
}
export const asAdmin = admin;

export const loc = () => screen.getByTestId('loc');
/** The last request whose "METHOD /path" starts with `prefix`. */
export const lastCall = (
  api: ReturnType<typeof mockApi>,
  key: string,
): { url: string; body: Record<string, unknown> | null } | undefined =>
  api.calls.filter((c) => `${c.method} ${c.url}`.startsWith(key)).at(-1);
