import { useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext';
import { formatDate, formatMoney } from '../../lib/format';
import CatalogError from '../catalog/CatalogError';
import { SearchBox } from '../catalog/FilterSidebar';
import Pagination from '../catalog/Pagination';
import { useUrlQuery } from '../catalog/useCatalogQuery';
import { useOrders } from './api';
import { CompanyFilter, useAdminDefault } from '../admin/ListFilters';
import StatusBadge from './StatusBadge';
import {
  EMPTY_QUERY,
  SORT_OPTIONS,
  STATUSES,
  STATUS_LABELS,
  dateRangeError,
  hasFilters,
  parseQuery,
  toSearch,
  type OrderQuery,
} from './urlState';

const th = 'px-3 py-2 text-left text-sm font-semibold';
const td = 'px-3 py-2 text-sm';
const input = 'rounded border border-slate-500 px-2 py-1';

/** `basePath` is '/admin' when mounted in the back office, so links stay inside it. */
export default function OrdersPage({ basePath = '' }: { basePath?: string }) {
  const { user } = useAuth();
  const { query, update } = useUrlQuery<OrderQuery>(parseQuery, toSearch);
  const rangeError = dateRangeError(query);
  const redirecting = useAdminDefault(
    basePath,
    '?status=pending&sort=created_at',
  );
  const orders = useOrders(query, !rangeError && !redirecting);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const isStaff = user?.role !== 'customer';
  const list = orders.data;
  const clear = () => update(EMPTY_QUERY);

  // A page past the end (hand-edited URL) jumps to the last page.
  const overshoot =
    list &&
    !orders.isPlaceholderData &&
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
  } else if (orders.isError && !list) {
    body = (
      <CatalogError
        error={orders.error}
        onRetry={() => void orders.refetch()}
      />
    );
  } else if (!list) {
    body = (
      <div role="status" aria-busy="true" aria-label="Loading orders">
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
        <p>No orders match your filters.</p>
        <button
          type="button"
          onClick={clear}
          className="mt-2 rounded border border-slate-600 px-3 py-1"
        >
          Clear filters
        </button>
      </div>
    ) : isStaff ? (
      <p>No orders yet.</p>
    ) : (
      <p>
        You have no orders yet.{' '}
        <Link to="/catalog" className="text-blue-800 underline">
          Browse the catalog
        </Link>
      </p>
    );
  } else {
    body = (
      <>
        <div
          className={orders.isPlaceholderData ? 'opacity-60' : undefined}
          aria-busy={orders.isPlaceholderData}
        >
          <table className="w-full border-collapse">
            <caption className="sr-only">Orders</caption>
            <thead>
              <tr className="border-b border-slate-400 bg-slate-100">
                <th scope="col" className={th}>
                  Order number
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
                  Items
                </th>
                <th scope="col" className={th}>
                  Total
                </th>
              </tr>
            </thead>
            <tbody>
              {list.data.map((o) => (
                <tr key={o.number} className="border-b border-slate-300">
                  <td className={td}>
                    <Link
                      to={`${basePath}/orders/${o.number}`}
                      className="text-blue-800 underline"
                    >
                      {o.number}
                    </Link>
                  </td>
                  <td className={td}>{o.po_reference ?? '-'}</td>
                  {isStaff && (
                    <td className={td}>
                      {basePath ? (
                        <Link
                          to={`${basePath}/companies/${o.company.id}`}
                          className="text-blue-800 underline"
                        >
                          {o.company.name}
                        </Link>
                      ) : (
                        o.company.name
                      )}
                    </td>
                  )}
                  <td className={td}>{formatDate(o.created_at)}</td>
                  <td className={td}>
                    <StatusBadge status={o.status} />
                  </td>
                  <td className={td}>{o.line_count}</td>
                  <td className={td}>
                    {formatMoney(o.total_cents, o.currency)}
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
      <h1
        ref={headingRef}
        tabIndex={-1}
        className="mb-4 text-2xl font-semibold"
      >
        {isStaff
          ? 'Orders'
          : `Orders for ${user?.company?.name ?? 'your company'}`}
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
              htmlFor={`orders-${k}`}
              className="mb-1 block text-sm font-semibold"
            >
              {k === 'from' ? 'From date' : 'To date'}
            </label>
            <input
              id={`orders-${k}`}
              type="date"
              value={query[k]}
              aria-invalid={rangeError ? true : undefined}
              aria-describedby={rangeError ? 'orders-range-error' : undefined}
              onChange={(e) => update({ [k]: e.target.value }, true)}
              className={input}
            />
          </div>
        ))}
        <SearchBox
          id="orders-q"
          label="Order number or PO reference"
          hint="e.g. K7Q2 or PO-4711"
          maxLength={32}
          value={query.q}
          onCommit={(q) => update({ q }, true)}
        />
        {basePath && (
          <CompanyFilter
            value={query.company_id}
            onChange={(company_id) => update({ company_id })}
          />
        )}
        <div className="mb-4">
          <label
            htmlFor="orders-sort"
            className="mb-1 block text-sm font-semibold"
          >
            Sort by
          </label>
          <select
            id="orders-sort"
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
        <p id="orders-range-error" role="alert" className="mb-3 text-red-700">
          {rangeError}
        </p>
      )}
      <p role="status" aria-live="polite" className="mb-2 min-h-6 text-sm">
        {total !== undefined && !rangeError && !orders.isError
          ? `${total} ${total === 1 ? 'order' : 'orders'} found`
          : ''}
      </p>
      {body}
    </section>
  );
}
