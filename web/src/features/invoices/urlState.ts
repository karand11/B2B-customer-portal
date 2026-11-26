import { z } from 'zod';
import { isRealDate } from '../orders/urlState';
import type { InvoiceStatus } from './api';

export const STATUSES: InvoiceStatus[] = ['open', 'overdue', 'paid', 'void'];
export const STATUS_LABELS: Record<InvoiceStatus, string> = {
  open: 'Open',
  overdue: 'Overdue',
  paid: 'Paid',
  void: 'Void',
};
// All eight sort values of the contract; the default is omitted from the URL.
export const SORT_OPTIONS = [
  { value: '-issue_date', label: 'Newest' },
  { value: 'issue_date', label: 'Oldest' },
  { value: 'due_date', label: 'Due date (soonest)' },
  { value: '-due_date', label: 'Due date (latest)' },
  { value: 'number', label: 'Number (lowest)' },
  { value: '-number', label: 'Number (highest)' },
  { value: 'total', label: 'Total (lowest)' },
  { value: '-total', label: 'Total (highest)' },
];
const DEFAULT_SORT = '-issue_date';

export interface InvoiceQuery {
  status: InvoiceStatus[];
  from: string;
  to: string;
  q: string;
  sort: string;
  page: number;
}

export const EMPTY_QUERY: InvoiceQuery = {
  status: [],
  from: '',
  to: '',
  q: '',
  sort: DEFAULT_SORT,
  page: 1,
};

const sortSchema = z.enum(
  SORT_OPTIONS.map((o) => o.value) as [string, ...string[]],
);
const pageSchema = z.coerce.number().int().min(1).max(10_000);

/** Anything invalid is dropped, never sent to the API. */
export function parseQuery(sp: URLSearchParams): InvoiceQuery {
  const status: InvoiceStatus[] = [];
  for (const part of (sp.get('status') ?? '').split(',')) {
    const v = part.trim() as InvoiceStatus;
    if (STATUSES.includes(v) && !status.includes(v)) status.push(v);
  }
  const from = sp.get('from')?.trim() ?? '';
  const to = sp.get('to')?.trim() ?? '';
  const q = (sp.get('q') ?? '').trim();
  const sort = sortSchema.safeParse(sp.get('sort'));
  const page = pageSchema.safeParse(sp.get('page') ?? undefined);
  return {
    status,
    from: isRealDate(from) ? from : '',
    to: isRealDate(to) ? to : '',
    q: q.length <= 32 ? q : '',
    sort: sort.success ? sort.data : DEFAULT_SORT,
    page: page.success ? page.data : 1,
  };
}

/** Canonical query string (fixed key order, defaults omitted). */
export function toSearch(query: InvoiceQuery): string {
  const pairs: [string, string][] = [];
  if (query.status.length) pairs.push(['status', query.status.join(',')]);
  if (query.from) pairs.push(['from', query.from]);
  if (query.to) pairs.push(['to', query.to]);
  if (query.q) pairs.push(['q', encodeURIComponent(query.q)]);
  if (query.sort !== DEFAULT_SORT) pairs.push(['sort', query.sort]);
  if (query.page > 1) pairs.push(['page', String(query.page)]);
  return pairs.length ? `?${pairs.map(([k, v]) => `${k}=${v}`).join('&')}` : '';
}

export const hasFilters = (q: InvoiceQuery): boolean =>
  toSearch({ ...q, sort: DEFAULT_SORT, page: 1 }) !== '';
