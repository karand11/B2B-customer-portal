import { z } from 'zod';
import { PER_PAGE, clampPage } from '../../lib/pageWindow';

export const SORTS = [
  'part_number',
  'brand',
  'inner_diameter',
  'outer_diameter',
  'width',
  'price',
].flatMap((s) => [s, `-${s}`]);
const SEALS = ['open', '2rs', 'zz'] as const;
const STOCKS = ['in_stock', 'low_stock', 'out_of_stock', 'on_order'] as const;

export type SealType = (typeof SEALS)[number];
export type StockStatus = (typeof STOCKS)[number];

export const RANGES = [
  { base: 'inner_diameter', label: 'Inner diameter' },
  { base: 'outer_diameter', label: 'Outer diameter' },
  { base: 'width', label: 'Width' },
] as const;
export type RangeBase = (typeof RANGES)[number]['base'];
export type RangeKey = `${RangeBase}_${'min' | 'max'}`;

export interface CatalogQuery {
  q: string;
  category: string[];
  brand: string[];
  material: string[];
  seal_type: SealType[];
  stock_status: StockStatus[];
  ranges: Partial<Record<RangeKey, string>>;
  sort: string | null;
  page: number;
}

export const EMPTY_QUERY: CatalogQuery = {
  q: '',
  category: [],
  brand: [],
  material: [],
  seal_type: [],
  stock_status: [],
  ranges: {},
  sort: null,
  page: 1,
};

export type ListKey =
  'category' | 'brand' | 'material' | 'seal_type' | 'stock_status';

const slug = z.string().regex(/^[a-z0-9-]{1,64}$/);
const text = z
  .string()
  .max(64)
  .regex(/^[\p{L}\p{N} .&/+-]+$/u);
const LISTS: Record<ListKey, { item: z.ZodType<string>; max: number }> = {
  category: { item: slug, max: 10 },
  brand: { item: text, max: 20 },
  material: { item: text, max: 20 },
  seal_type: { item: z.enum(SEALS), max: 3 },
  stock_status: { item: z.enum(STOCKS), max: 4 },
};
const sortSchema = z.enum(SORTS as [string, ...string[]]);
const pageSchema = z.coerce.number().int().min(1).max(1_000_000);

/** One decimal like the API accepts: up to 4 digits, 2 decimals, 0.01 to 5000. */
export function isValidDecimal(v: string): boolean {
  return /^\d{1,4}(\.\d{1,2})?$/.test(v) && +v >= 0.01 && +v <= 5000;
}

/** Error text for a min/max pair of raw strings, or null when sendable (blank is fine). */
export function rangeError(min: string, max: string): string | null {
  if ((min && !isValidDecimal(min)) || (max && !isValidDecimal(max)))
    return 'Enter a number between 0.01 and 5000, with at most 2 decimals.';
  if (min && max && +min > +max) return 'Minimum must not exceed maximum.';
  return null;
}

function parseList<K extends ListKey>(raw: string | null, key: K) {
  const { item, max } = LISTS[key];
  const out: string[] = [];
  for (const part of (raw ?? '').split(',')) {
    const v = part.trim();
    if (item.safeParse(v).success && !out.includes(v)) out.push(v);
  }
  return out.slice(0, max);
}

/** Anything invalid is dropped, never sent to the API. */
export function parseQuery(sp: URLSearchParams): CatalogQuery {
  const q = (sp.get('q') ?? '').trim();
  const ranges: CatalogQuery['ranges'] = {};
  for (const { base } of RANGES) {
    const min = sp.get(`${base}_min`)?.trim() ?? '';
    const max = sp.get(`${base}_max`)?.trim() ?? '';
    if (rangeError(min, max)) continue; // drops the whole pair
    if (min) ranges[`${base}_min`] = min;
    if (max) ranges[`${base}_max`] = max;
  }
  const sort = sortSchema.safeParse(sp.get('sort'));
  const page = pageSchema.safeParse(sp.get('page') ?? undefined);
  return {
    q: q.length <= 64 ? q : '',
    category: parseList(sp.get('category'), 'category'),
    brand: parseList(sp.get('brand'), 'brand'),
    material: parseList(sp.get('material'), 'material'),
    seal_type: parseList(sp.get('seal_type'), 'seal_type') as SealType[],
    stock_status: parseList(
      sp.get('stock_status'),
      'stock_status',
    ) as StockStatus[],
    ranges,
    sort: sort.success ? sort.data : null,
    page: page.success ? clampPage(page.data, PER_PAGE.catalog) : 1,
  };
}

/** Canonical query string (fixed key order, defaults omitted, commas kept readable). */
export function toSearch(query: CatalogQuery): string {
  const pairs: [string, string][] = [];
  if (query.q) pairs.push(['q', query.q]);
  for (const k of Object.keys(LISTS) as ListKey[]) {
    if (query[k].length)
      pairs.push([k, query[k].map(encodeURIComponent).join(',')]);
  }
  for (const { base } of RANGES) {
    for (const end of ['min', 'max'] as const) {
      const v = query.ranges[`${base}_${end}`];
      if (v) pairs.push([`${base}_${end}`, v]);
    }
  }
  if (query.sort) pairs.push(['sort', query.sort]);
  if (query.page > 1) pairs.push(['page', String(query.page)]);
  const enc = (k: string, v: string) =>
    `${k}=${k === 'q' ? encodeURIComponent(v) : v}`;
  return pairs.length ? `?${pairs.map(([k, v]) => enc(k, v)).join('&')}` : '';
}

export function hasFilters(q: CatalogQuery): boolean {
  return toSearch({ ...q, sort: null, page: 1 }) !== '';
}
