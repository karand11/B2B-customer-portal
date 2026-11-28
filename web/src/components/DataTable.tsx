import { useEffect, useRef, type ReactNode } from 'react';
import ScrollRegion from './ScrollRegion';
import CatalogError from '../features/catalog/CatalogError';
import { WINDOW_MESSAGE, atWindowEnd } from '../lib/pageWindow';

export interface Column<T> {
  key: string;
  header: string;
  /** API sort value; its presence makes the header a sort button. */
  sortKey?: string;
  render: (row: T) => ReactNode;
}

const th = 'px-3 py-2 text-left text-sm font-semibold';
const td = 'px-3 py-2 text-sm';
const btn =
  'rounded border border-slate-600 px-3 py-1 disabled:cursor-not-allowed disabled:opacity-50';

/**
 * Controlled, accessible table: sortable headers are buttons with aria-sort, paging is a
 * labelled nav, the result count is a polite live region and every list state is covered.
 * Sort cycle per column: ascending, descending, then back to `defaultSort`
 * (a column that is the default in one direction just flips).
 */
export default function DataTable<T>({
  caption,
  columns,
  rows,
  rowKey,
  sort,
  defaultSort,
  onSortChange,
  page,
  lastPage,
  perPage,
  total,
  onPageChange,
  isLoading,
  isPlaceholder = false,
  error,
  onRetry,
  emptyMessage,
  hasFilters = false,
  onClearFilters,
  noun = ['result', 'results'],
}: {
  caption: string;
  columns: Column<T>[];
  rows: T[] | undefined;
  rowKey: (row: T) => string | number;
  sort: string;
  defaultSort: string;
  onSortChange: (sort: string) => void;
  page: number;
  lastPage: number;
  /** From the response meta; enables the 10,000 result window clamp. */
  perPage?: number;
  total: number | undefined;
  onPageChange: (page: number) => void;
  isLoading: boolean;
  /** Showing the previous result while a new one loads. */
  isPlaceholder?: boolean;
  error?: unknown;
  onRetry: () => void;
  emptyMessage: string;
  hasFilters?: boolean;
  onClearFilters?: () => void;
  /** Singular and plural, for the result count. */
  noun?: [string, string];
}) {
  const tableRef = useRef<HTMLTableElement>(null);
  const refocus = useRef(false);

  // A page past the end (hand-edited URL) jumps to the last page.
  const overshoot =
    rows && !isPlaceholder && rows.length === 0 && (total ?? 0) > 0
      ? lastPage
      : null;
  useEffect(() => {
    if (overshoot && page > overshoot) onPageChange(overshoot);
  }, [overshoot, page, onPageChange]);

  // After paging, keyboard users land on the table instead of a button that moved or disabled.
  useEffect(() => {
    if (refocus.current && rows && !isPlaceholder) {
      refocus.current = false;
      tableRef.current?.focus();
    }
  }, [rows, isPlaceholder]);

  const capped = perPage !== undefined && atWindowEnd(page, perPage, lastPage);
  const go = (p: number) => {
    refocus.current = true;
    onPageChange(p);
  };

  // A column that is already the default sort in one direction flips instead of staying put.
  const nextSort = (key: string) =>
    sort === key
      ? `-${key}`
      : sort === `-${key}`
        ? defaultSort === sort
          ? key
          : defaultSort
        : key;
  const ariaSort = (key: string) =>
    sort === key ? 'ascending' : sort === `-${key}` ? 'descending' : 'none';

  let body: ReactNode;
  if (error && !rows) {
    body = <CatalogError error={error} onRetry={onRetry} />;
  } else if (isLoading || !rows) {
    body = (
      <div role="status" aria-busy="true" aria-label={`Loading ${caption}`}>
        {[0, 1, 2, 3].map((i) => (
          <div
            key={i}
            className="mb-2 h-8 animate-pulse motion-reduce:animate-none rounded bg-slate-200"
          />
        ))}
      </div>
    );
  } else if (rows.length === 0 && (total ?? 0) === 0) {
    body = (
      <div>
        <p>{hasFilters ? 'No results match your filters.' : emptyMessage}</p>
        {hasFilters && onClearFilters && (
          <button
            type="button"
            onClick={onClearFilters}
            className={`mt-2 ${btn}`}
          >
            Clear filters
          </button>
        )}
      </div>
    );
  } else {
    body = (
      <>
        <div
          className={isPlaceholder ? 'opacity-80' : ''}
          aria-busy={isPlaceholder}
        >
          <ScrollRegion label={`${caption} table`}>
            <table
              ref={tableRef}
              tabIndex={-1}
              className="w-full border-collapse outline-none"
            >
              <caption className="sr-only">{caption}</caption>
              <thead>
                <tr className="border-b border-slate-400 bg-slate-100">
                  {columns.map((c) => (
                    <th
                      key={c.key}
                      scope="col"
                      className={th}
                      aria-sort={c.sortKey ? ariaSort(c.sortKey) : undefined}
                    >
                      {c.sortKey ? (
                        <button
                          type="button"
                          className="font-semibold underline decoration-dotted"
                          onClick={() => onSortChange(nextSort(c.sortKey!))}
                        >
                          {c.header}
                          <span aria-hidden="true">
                            {sort === c.sortKey
                              ? ' ▲'
                              : sort === `-${c.sortKey}`
                                ? ' ▼'
                                : ''}
                          </span>
                        </button>
                      ) : (
                        c.header
                      )}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={rowKey(r)} className="border-b border-slate-300">
                    {columns.map((c) => (
                      <td key={c.key} className={td}>
                        {c.render(r)}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </ScrollRegion>
        </div>
        <nav
          aria-label="Pagination"
          className="mt-4 flex flex-wrap items-center gap-4"
        >
          <button
            type="button"
            className={btn}
            disabled={page <= 1}
            onClick={() => go(page - 1)}
          >
            Previous
          </button>
          <span>
            Page {page} of {lastPage}
          </span>
          <button
            type="button"
            className={btn}
            disabled={page >= lastPage || capped}
            aria-describedby={capped ? 'page-window-hint' : undefined}
            onClick={() => go(page + 1)}
          >
            Next
          </button>
          {capped && (
            <p id="page-window-hint" className="w-full text-sm">
              {WINDOW_MESSAGE}
            </p>
          )}
        </nav>
      </>
    );
  }

  return (
    <div>
      <p aria-live="polite" className="mb-2 min-h-6 text-sm">
        {total !== undefined && !error
          ? `${total} ${noun[total === 1 ? 0 : 1]} found`
          : ''}
      </p>
      {body}
    </div>
  );
}
