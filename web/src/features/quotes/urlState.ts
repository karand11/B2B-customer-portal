import { z } from 'zod';
import { isRealDate } from '../orders/urlState';
import type { QuoteStatus } from './api';

export { dateRangeError } from '../orders/urlState';

export const STATUSES: QuoteStatus[] = [
  'submitted',
  'in_review',
  'quoted',
  'accepted',
  'declined',
  'expired',
];
export const STATUS_LABELS: Record<QuoteStatus, string> = {
  submitted: 'Submitted',
  in_review: 'In review',
  quoted: 'Quoted',
  accepted: 'Accepted',
  declined: 'Declined',
  expired: 'Expired',
};
export const SORT_OPTIONS = [
  { value: '-created_at', label: 'Newest' },
  { value: 'created_at', label: 'Oldest' },
  { value: 'number', label: 'Number (A to Z)' },
  { value: '-number', label: 'Number (Z to A)' },
];
const DEFAULT_SORT = '-created_at';
export const ATTENTION: QuoteStatus[] = ['submitted', 'in_review'];

export interface QuoteQuery {
  status: QuoteStatus[];
  from: string;
  to: string;
  q: string;
  sort: string; // always set; the default is omitted from the URL
  page: number;
}

export const EMPTY_QUERY: QuoteQuery = {
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
export function parseQuery(sp: URLSearchParams): QuoteQuery {
  const status: QuoteStatus[] = [];
  for (const part of (sp.get('status') ?? '').split(',')) {
    const v = part.trim() as QuoteStatus;
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
export function toSearch(query: QuoteQuery): string {
  const pairs: [string, string][] = [];
  if (query.status.length) pairs.push(['status', query.status.join(',')]);
  if (query.from) pairs.push(['from', query.from]);
  if (query.to) pairs.push(['to', query.to]);
  if (query.q) pairs.push(['q', encodeURIComponent(query.q)]);
  if (query.sort !== DEFAULT_SORT) pairs.push(['sort', query.sort]);
  if (query.page > 1) pairs.push(['page', String(query.page)]);
  return pairs.length ? `?${pairs.map(([k, v]) => `${k}=${v}`).join('&')}` : '';
}

export function hasFilters(q: QuoteQuery): boolean {
  return toSearch({ ...q, sort: DEFAULT_SORT, page: 1 }) !== '';
}
