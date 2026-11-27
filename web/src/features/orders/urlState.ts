import { z } from 'zod';
import type { OrderStatus } from './api';

export const STATUSES: OrderStatus[] = [
  'pending',
  'confirmed',
  'shipped',
  'delivered',
  'cancelled',
];
export const STATUS_LABELS: Record<OrderStatus, string> = {
  pending: 'Pending',
  confirmed: 'Confirmed',
  shipped: 'Shipped',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
};
export const SORT_OPTIONS = [
  { value: '-created_at', label: 'Newest' },
  { value: 'created_at', label: 'Oldest' },
  { value: '-total', label: 'Highest total' },
  { value: 'total', label: 'Lowest total' },
];
const DEFAULT_SORT = '-created_at';

export interface OrderQuery {
  status: OrderStatus[];
  from: string;
  to: string;
  q: string;
  /** Back office only (staff and admin); the API ignores it for customers. */
  company_id: string;
  sort: string; // always set; the default is omitted from the URL
  page: number;
}

export const EMPTY_QUERY: OrderQuery = {
  status: [],
  from: '',
  to: '',
  q: '',
  company_id: '',
  sort: DEFAULT_SORT,
  page: 1,
};

/** A real calendar date in YYYY-MM-DD (rejects 2026-13-01 and 2026-02-30). */
export function isRealDate(v: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return false;
  const d = new Date(`${v}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === v;
}

/** `from` after `to` is kept in the URL state but never sent (inline error). */
export const dateRangeError = (q: Pick<OrderQuery, 'from' | 'to'>) =>
  q.from && q.to && q.from > q.to
    ? '"From date" must not be after "To date".'
    : null;

const sortSchema = z.enum(
  SORT_OPTIONS.map((o) => o.value) as [string, ...string[]],
);
const pageSchema = z.coerce.number().int().min(1).max(10_000);

/** Anything invalid is dropped, never sent to the API. */
export function parseQuery(sp: URLSearchParams): OrderQuery {
  const status: OrderStatus[] = [];
  for (const part of (sp.get('status') ?? '').split(',')) {
    const v = part.trim() as OrderStatus;
    if (STATUSES.includes(v) && !status.includes(v)) status.push(v);
  }
  const from = sp.get('from')?.trim() ?? '';
  const to = sp.get('to')?.trim() ?? '';
  const q = (sp.get('q') ?? '').trim();
  const company = sp.get('company_id')?.trim() ?? '';
  const sort = sortSchema.safeParse(sp.get('sort'));
  const page = pageSchema.safeParse(sp.get('page') ?? undefined);
  return {
    status,
    from: isRealDate(from) ? from : '',
    to: isRealDate(to) ? to : '',
    q: q.length <= 32 ? q : '',
    company_id: /^[1-9]\d{0,17}$/.test(company) ? company : '',
    sort: sort.success ? sort.data : DEFAULT_SORT,
    page: page.success ? page.data : 1,
  };
}

/** Canonical query string (fixed key order, defaults omitted). */
export function toSearch(query: OrderQuery): string {
  const pairs: [string, string][] = [];
  if (query.status.length) pairs.push(['status', query.status.join(',')]);
  if (query.from) pairs.push(['from', query.from]);
  if (query.to) pairs.push(['to', query.to]);
  if (query.q) pairs.push(['q', encodeURIComponent(query.q)]);
  if (query.company_id) pairs.push(['company_id', query.company_id]);
  if (query.sort !== DEFAULT_SORT) pairs.push(['sort', query.sort]);
  if (query.page > 1) pairs.push(['page', String(query.page)]);
  return pairs.length ? `?${pairs.map(([k, v]) => `${k}=${v}`).join('&')}` : '';
}

export function hasFilters(q: OrderQuery): boolean {
  return toSearch({ ...q, sort: DEFAULT_SORT, page: 1 }) !== '';
}
