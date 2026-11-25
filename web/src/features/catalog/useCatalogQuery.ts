import { useCallback, useEffect, useMemo, useRef } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { parseQuery, toSearch, type CatalogQuery } from './urlState';

/** The URL query string is the single source of truth for catalog state. */
export function useCatalogQuery() {
  const [sp] = useSearchParams();
  const navigate = useNavigate();
  const spString = sp.toString();
  const query = useMemo(
    () => parseQuery(new URLSearchParams(spString)),
    [spString],
  );
  // Debounced callbacks run later; they must patch the latest URL state, not a stale render.
  const latest = useRef(query);
  useEffect(() => {
    latest.current = query;
  });

  /** Any change resets paging unless the patch sets `page`. replace=true for typing. */
  const update = useCallback(
    (patch: Partial<CatalogQuery>, replace = false) =>
      navigate(
        { search: toSearch({ ...latest.current, page: 1, ...patch }) },
        { replace },
      ),
    [navigate],
  );
  return { query, raw: sp, update };
}
