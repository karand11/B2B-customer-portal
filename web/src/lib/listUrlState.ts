import { useCallback, useEffect, useMemo, useRef } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { PER_PAGE, clampPage } from './pageWindow';

/**
 * One URL parameter: `parse` returns undefined for an invalid value (it is dropped),
 * `format` is the canonical text written to the URL. Defaults are never written.
 */
export interface ParamSpec<T> {
  parse: (raw: string | null) => T | undefined;
  format: (v: T) => string;
  default: T;
}
export type ListSpec<V> = { [K in keyof V]: ParamSpec<V[K]> };

export const textParam = (max = 64): ParamSpec<string> => ({
  parse: (raw) => {
    const t = (raw ?? '').trim();
    return t.length <= max ? t : undefined;
  },
  format: (v) => v,
  default: '',
});

export const intParam: ParamSpec<string> = {
  parse: (raw) =>
    raw !== null && /^[1-9]\d{0,17}$/.test(raw) ? raw : undefined,
  format: (v) => v,
  default: '',
};

export const pageParam: ParamSpec<number> = {
  parse: (raw) => {
    if (raw === null || !/^[1-9]\d{0,4}$/.test(raw)) return undefined;
    const n = Number(raw);
    return n <= 10_000 ? clampPage(n, PER_PAGE.admin) : undefined;
  },
  format: String,
  default: 1,
};

export const sortParam = (
  allowed: readonly string[],
  dflt: string,
): ParamSpec<string> => ({
  parse: (raw) => (raw !== null && allowed.includes(raw) ? raw : undefined),
  format: (v) => v,
  default: dflt,
});

/** One value out of a fixed set; '' means "not filtered". */
export const enumParam = <T extends string>(
  allowed: readonly T[],
): ParamSpec<T | ''> => ({
  parse: (raw) =>
    raw !== null && (allowed as readonly string[]).includes(raw)
      ? (raw as T)
      : undefined,
  format: (v) => v,
  default: '',
});

/** Comma separated subset of a fixed set, in the canonical (allowed) order. */
export const csvParam = <T extends string>(
  allowed: readonly T[],
  dflt: T[] = [],
): ParamSpec<T[]> => ({
  parse: (raw) => {
    if (raw === null) return undefined;
    const parts = raw.split(',').map((p) => p.trim());
    return allowed.filter((a) => parts.includes(a));
  },
  format: (v) => allowed.filter((a) => v.includes(a)).join(','),
  default: dflt,
});

/** A real calendar date, YYYY-MM-DD. */
export const dateParam: ParamSpec<string> = {
  parse: (raw) => {
    if (raw === null || !/^\d{4}-\d{2}-\d{2}$/.test(raw)) return undefined;
    const d = new Date(`${raw}T00:00:00Z`);
    return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === raw
      ? raw
      : undefined;
  },
  format: (v) => v,
  default: '',
};

export function parseList<V>(spec: ListSpec<V>, sp: URLSearchParams): V {
  const out = {} as V;
  for (const key of Object.keys(spec) as (keyof V & string)[]) {
    const p = spec[key];
    out[key] = p.parse(sp.get(key)) ?? p.default;
  }
  return out;
}

/** Canonical query string: fixed key order, defaults omitted, never `per_page`. */
export function toListSearch<V>(spec: ListSpec<V>, values: V): string {
  const pairs: string[] = [];
  for (const key of Object.keys(spec) as (keyof V & string)[]) {
    const p = spec[key];
    const text = p.format(values[key]);
    if (text !== p.format(p.default))
      pairs.push(`${key}=${encodeURIComponent(text).replace(/%2C/g, ',')}`);
  }
  return pairs.length ? `?${pairs.join('&')}` : '';
}

/**
 * List state that lives in the URL. `spec` must be a module-level constant.
 * `set` resets the page unless the patch sets it; pass replace=true for typing.
 * The spec's `page` and `sort` keys are special only in that they do not count as filters.
 */
export function useListUrlState<V extends { page: number; sort: string }>(
  spec: ListSpec<V>,
) {
  const [sp] = useSearchParams();
  const navigate = useNavigate();
  const spString = sp.toString();
  const values = useMemo(
    () => parseList(spec, new URLSearchParams(spString)),
    [spec, spString],
  );
  // Debounced callers must patch the latest URL state, not a stale render.
  const latest = useRef(values);
  useEffect(() => {
    latest.current = values;
  });

  const set = useCallback(
    (patch: Partial<V>, replace = false) => {
      const next = { ...latest.current, page: 1, ...patch } as V;
      // Two updates in one tick (debounced typing plus a click) must compose, not overwrite.
      latest.current = next;
      // A path-less location keeps the current pathname and writes commas literally.
      navigate({ search: toListSearch(spec, next) }, { replace });
    },
    [spec, navigate],
  );

  const search = useMemo(() => toListSearch(spec, values), [spec, values]);
  const filtersOnly = { ...values, page: 1, sort: spec.sort.default } as V;
  const hasFilters = toListSearch(spec, filtersOnly) !== '';
  const clear = useCallback(
    () =>
      set({
        ...parseList(spec, new URLSearchParams()),
        sort: latest.current.sort,
      } as V),
    [set, spec],
  );
  return { values, search, set, hasFilters, clear };
}
