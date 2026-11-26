import { useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext';
import { formatDate } from '../../lib/format';
import CatalogError from '../catalog/CatalogError';
import { SearchBox } from '../catalog/FilterSidebar';
import Pagination from '../catalog/Pagination';
import { useUrlQuery } from '../catalog/useCatalogQuery';
import { useQuotes } from './api';
import { priceOrAwaiting } from './money';
import StatusBadge from './StatusBadge';
import {
  ATTENTION,
  EMPTY_QUERY,
  SORT_OPTIONS,
  STATUSES,
  STATUS_LABELS,
  dateRangeError,
  hasFilters,
  parseQuery,
  toSearch,
  type QuoteQuery,
} from './urlState';

const th = 'px-3 py-2 text-left text-sm font-semibold';
const td = 'px-3 py-2 text-sm';
const input = 'rounded border border-slate-500 px-2 py-1';
const btn = 'rounded border border-slate-600 px-3 py-1';

export default function QuotesPage() {
  const { user } = useAuth();
  const { query, update } = useUrlQuery<QuoteQuery>(parseQuery, toSearch);
  const rangeError = dateRangeError(query);
  const quotes = useQuotes(query, !rangeError);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const isStaff = user?.role !== 'customer';
  const list = quotes.data;
  const clear = () => update(EMPTY_QUERY);

  // A page past the end (hand-edited URL) jumps to the last page.
  const overshoot =
    list &&
    !quotes.isPlaceholderData &&
    list.data.length === 0 &&
    list.meta.total > 0
      ? list.meta.last_page
      : null;
  useEffect(() => {
    if (overshoot && query.page > overshoot) update({ page: overshoot }, true);
  }, [overshoot, query.page, update]);

  let body;
  if (rangeError) {
    body = null; // the inline alert next to the date fields explains it
  } else if (quotes.isError && !list) {
    body = (
      <CatalogError
        error={quotes.error}
        onRetry={() => void quotes.refetch()}
      />
    );
  } else if (!list) {
    body = (
      <div role="status" aria-busy="true" aria-label="Loading quotes">
        {[0, 1, 2, 3].map((i) => (
          <div
            key={i}
            className="mb-2 h-8 animate-pulse rounded bg-slate-200"
          />
        ))}
      </div>
    );
  } else if (list.meta.total === 0) {
    body = hasFilters(query) ? (
      <div>
        <p>No quotes match your filters.</p>
        <button type="button" onClick={clear} className={`mt-2 ${btn}`}>
          Clear filters
        </button>
      </div>
    ) : isStaff ? (
      <p>No quotes.</p>
    ) : (
      <div>
        <p className="mb-2">You have no quote requests yet.</p>
        <Link
          to="/quotes/new"
          className="inline-block rounded bg-blue-700 px-3 py-1 text-white"
        >
          Request a quote
        </Link>
      </div>
    );
  } else {
    body = (
      <>
        <div
          className={quotes.isPlaceholderData ? 'opacity-60' : undefined}
          aria-busy={quotes.isPlaceholderData}
        >
          <table className="w-full border-collapse">
            <caption className="sr-only">Quotes</caption>
            <thead>
              <tr className="border-b border-slate-400 bg-slate-100">
                <th scope="col" className={th}>
                  Quote number
                </th>
                <th scope="col" className={th}>
                  PO reference
                </th>
                {isStaff && (
                  <th scope="col" className={th}>
                    Company
                  </th>
                )}
                <th scope="col" className={th}>
                  Date
                </th>
                <th scope="col" className={th}>
                  Status
                </th>
                <th scope="col" className={th}>
                  Lines
                </th>
                <th scope="col" className={th}>
                  Total
                </th>
                <th scope="col" className={th}>
                  Valid until
                </th>
              </tr>
            </thead>
            <tbody>
              {list.data.map((q) => (
                <tr key={q.number} className="border-b border-slate-300">
                  <td className={td}>
                    <Link
                      to={`/quotes/${q.number}`}
                      className="text-blue-800 underline"
                    >
                      {q.number}
                    </Link>
                  </td>
                  <td className={td}>{q.po_reference ?? '—'}</td>
                  {isStaff && <td className={td}>{q.company.name}</td>}
                  <td className={td}>{formatDate(q.created_at)}</td>
                  <td className={td}>
                    <StatusBadge status={q.status} />
                  </td>
                  <td className={td}>{q.line_count}</td>
                  <td className={td}>
                    {priceOrAwaiting(q.total_cents, q.currency)}
                  </td>
                  <td className={td}>
                    {q.status === 'expired'
                      ? 'Expired'
                      : (q.valid_until ?? '—')}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {list.meta.last_page > 1 && (
          <Pagination
            page={list.meta.current_page}
            lastPage={list.meta.last_page}
            onPage={(page) => {
              update({ page });
              headingRef.current?.focus();
            }}
          />
        )}
      </>
    );
  }

  const total = list?.meta.total;
  return (
    <section>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 ref={headingRef} tabIndex={-1} className="text-2xl font-semibold">
          {isStaff
            ? 'Quotes'
            : `Quotes for ${user?.company?.name ?? 'your company'}`}
        </h1>
        {!isStaff && (
          <Link
            to="/quotes/new"
            className="rounded bg-blue-700 px-3 py-1 text-white"
          >
            New quote request
          </Link>
        )}
      </div>
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <fieldset>
          <legend className="mb-1 text-sm font-semibold">Status</legend>
          <div className="flex flex-wrap gap-3">
            {STATUSES.map((s) => (
              <label key={s} className="flex items-center gap-1 text-sm">
                <input
                  type="checkbox"
                  checked={query.status.includes(s)}
                  onChange={(e) =>
                    update({
                      status: e.target.checked
                        ? [...query.status, s]
                        : query.status.filter((x) => x !== s),
                    })
                  }
                />
                {STATUS_LABELS[s]}
              </label>
            ))}
          </div>
        </fieldset>
        {(['from', 'to'] as const).map((k) => (
          <div key={k}>
            <label
              htmlFor={`quotes-${k}`}
              className="mb-1 block text-sm font-semibold"
            >
              {k === 'from' ? 'From date' : 'To date'}
            </label>
            <input
              id={`quotes-${k}`}
              type="date"
              value={query[k]}
              aria-invalid={rangeError ? true : undefined}
              aria-describedby={rangeError ? 'quotes-range-error' : undefined}
              onChange={(e) => update({ [k]: e.target.value }, true)}
              className={input}
            />
          </div>
        ))}
        <SearchBox
          id="quotes-q"
          label="Quote number or PO reference"
          hint="e.g. K7Q2 or PO-4711"
          maxLength={32}
          value={query.q}
          onCommit={(q) => update({ q }, true)}
        />
        <div className="mb-4">
          <label
            htmlFor="quotes-sort"
            className="mb-1 block text-sm font-semibold"
          >
            Sort by
          </label>
          <select
            id="quotes-sort"
            value={query.sort}
            onChange={(e) => update({ sort: e.target.value })}
            className={input}
          >
            {SORT_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </div>
        {isStaff && (
          <button
            type="button"
            onClick={() => update({ status: ATTENTION })}
            className={`mb-4 ${btn}`}
          >
            Needs attention
          </button>
        )}
        {hasFilters(query) && (
          <button type="button" onClick={clear} className={`mb-4 ${btn}`}>
            Clear filters
          </button>
        )}
      </div>
      {rangeError && (
        <p id="quotes-range-error" role="alert" className="mb-3 text-red-700">
          {rangeError}
        </p>
      )}
      <p role="status" aria-live="polite" className="mb-2 min-h-6 text-sm">
        {total !== undefined && !rangeError && !quotes.isError
          ? `${total} ${total === 1 ? 'quote' : 'quotes'} found`
          : ''}
      </p>
      {body}
    </section>
  );
}
