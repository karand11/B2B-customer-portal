import { useCallback, useEffect, useMemo, useRef } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { parseQuery, toSearch, type CatalogQuery } from './urlState';

/**
 * The URL query string is the single source of truth for list state.
 * Generic so the catalog and the order history share one implementation.
 * `parse` and `serialize` must be module-level functions (stable identity).
 */
export function useUrlQuery<Q extends { page: number }>(
  parse: (sp: URLSearchParams) => Q,
  serialize: (q: Q) => string,
) {
  const [sp] = useSearchParams();
  const navigate = useNavigate();
  const spString = sp.toString();
  const query = useMemo(
    () => parse(new URLSearchParams(spString)),
    [parse, spString],
  );
  // Debounced callbacks run later; they must patch the latest URL state, not a stale render.
  const latest = useRef(query);
  useEffect(() => {
    latest.current = query;
  });

  /** Any change resets paging unless the patch sets `page`. replace=true for typing. */
  const update = useCallback(
    (patch: Partial<Q>, replace = false) =>
      navigate(
        { search: serialize({ ...latest.current, page: 1, ...patch }) },
        { replace },
      ),
    [navigate, serialize],
  );
  return { query, raw: sp, update };
}

export const useCatalogQuery = () =>
  useUrlQuery<CatalogQuery>(parseQuery, toSearch);
