import { useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext';
import { formatMoney } from '../../lib/format';
import CatalogError from '../catalog/CatalogError';
import { SearchBox } from '../catalog/FilterSidebar';
import Pagination from '../catalog/Pagination';
import { useUrlQuery } from '../catalog/useCatalogQuery';
import { dateRangeError } from '../orders/urlState';
import { useInvoices } from './api';
import StatusBadge from './StatusBadge';
import {
  EMPTY_QUERY,
  SORT_OPTIONS,
  STATUSES,
  STATUS_LABELS,
  hasFilters,
  parseQuery,
  toSearch,
  type InvoiceQuery,
} from './urlState';

const th = 'px-3 py-2 text-left text-sm font-semibold';
const td = 'px-3 py-2 text-sm';
const input = 'rounded border border-slate-500 px-2 py-1';

export default function InvoicesPage() {
  const { user } = useAuth();
  const { query, update } = useUrlQuery<InvoiceQuery>(parseQuery, toSearch);
  const rangeError = dateRangeError(query);
  const invoices = useInvoices(query, !rangeError);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const isStaff = user?.role !== 'customer';
  const list = invoices.data;
  const clear = () => update(EMPTY_QUERY);

  // A page past the end (hand-edited URL) jumps to the last page.
  const overshoot =
    list &&
    !invoices.isPlaceholderData &&
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
  } else if (invoices.isError && !list) {
    body = (
      <CatalogError
        error={invoices.error}
        onRetry={() => void invoices.refetch()}
      />
    );
  } else if (!list) {
    body = (
      <div role="status" aria-busy="true" aria-label="Loading invoices">
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
        <p>No invoices match your filters.</p>
        <button
          type="button"
          onClick={clear}
          className="mt-2 rounded border border-slate-600 px-3 py-1"
        >
          Clear filters
        </button>
      </div>
    ) : (
      <p>{isStaff ? 'No invoices.' : 'You have no invoices yet.'}</p>
    );
  } else {
    body = (
      <>
        <div
          className={invoices.isPlaceholderData ? 'opacity-60' : undefined}
          aria-busy={invoices.isPlaceholderData}
        >
          <table className="w-full border-collapse">
            <caption className="sr-only">Invoices</caption>
            <thead>
              <tr className="border-b border-slate-400 bg-slate-100">
                {[
                  'Number',
                  'Order',
                  'PO reference',
                  ...(isStaff ? ['Company'] : []),
                  'Issued',
                  'Due',
                  'Status',
                  'Total',
                ].map((h) => (
                  <th key={h} scope="col" className={th}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {list.data.map((i) => {
                const overdue = i.status === 'overdue';
                return (
                  <tr
                    key={i.id}
                    data-status={i.status}
                    className={`border-b border-slate-300 ${
                      overdue
                        ? 'bg-red-50'
                        : i.status === 'void'
                          ? 'text-slate-600'
                          : ''
                    }`}
                  >
                    <td
                      className={`${td} ${overdue ? 'border-l-4 border-red-800' : ''}`}
                    >
                      <Link
                        to={`/invoices/${i.id}`}
                        className="text-blue-800 underline"
                      >
                        {i.number}
                      </Link>
                    </td>
                    <td className={td}>
                      <Link
                        to={`/orders/${i.order_number}`}
                        className="text-blue-800 underline"
                      >
                        {i.order_number}
                      </Link>
                    </td>
                    <td className={td}>{i.po_reference ?? '—'}</td>
                    {isStaff && <td className={td}>{i.company.name}</td>}
                    <td className={td}>{i.issue_date}</td>
                    <td className={td}>
                      {i.due_date}
                      {i.days_overdue > 0 && (
                        <span className="block font-semibold text-red-900">
                          {i.days_overdue}{' '}
                          {i.days_overdue === 1 ? 'day' : 'days'} overdue
                        </span>
                      )}
                    </td>
                    <td className={td}>
                      <StatusBadge status={i.status} />
                    </td>
                    <td className={td}>
                      {formatMoney(i.total_cents, i.currency)}
                    </td>
                  </tr>
                );
              })}
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
      <h1
        ref={headingRef}
        tabIndex={-1}
        className="mb-4 text-2xl font-semibold"
      >
        {isStaff
          ? 'Invoices'
          : `Invoices for ${user?.company?.name ?? 'your company'}`}
      </h1>
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
              htmlFor={`invoices-${k}`}
              className="mb-1 block text-sm font-semibold"
            >
              {k === 'from' ? 'From date' : 'To date'}
            </label>
            <input
              id={`invoices-${k}`}
              type="date"
              value={query[k]}
              aria-invalid={rangeError ? true : undefined}
              aria-describedby={rangeError ? 'invoices-range-error' : undefined}
              onChange={(e) => update({ [k]: e.target.value }, true)}
              className={input}
            />
          </div>
        ))}
        <SearchBox
          id="invoices-q"
          label="Invoice number, order number or PO reference"
          hint="e.g. INV-2026-1, K7Q2 or PO-4711"
          maxLength={32}
          value={query.q}
          onCommit={(q) => update({ q }, true)}
        />
        <div className="mb-4">
          <label
            htmlFor="invoices-sort"
            className="mb-1 block text-sm font-semibold"
          >
            Sort by
          </label>
          <select
            id="invoices-sort"
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
            onClick={() => update({ status: ['overdue'] })}
            className="mb-4 rounded border border-red-800 px-3 py-1 text-red-900"
          >
            Show overdue only
          </button>
        )}
        {hasFilters(query) && (
          <button
            type="button"
            onClick={clear}
            className="mb-4 rounded border border-slate-600 px-3 py-1"
          >
            Clear filters
          </button>
        )}
      </div>
      {rangeError && (
        <p id="invoices-range-error" role="alert" className="mb-3 text-red-700">
          {rangeError}
        </p>
      )}
      <p role="status" aria-live="polite" className="mb-2 min-h-6 text-sm">
        {total !== undefined && !rangeError && !invoices.isError
          ? `${total} ${total === 1 ? 'invoice' : 'invoices'} found`
          : ''}
      </p>
      {body}
    </section>
  );
}
