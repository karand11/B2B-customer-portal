import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext';
import ConfirmDialog from '../../components/ConfirmDialog';
import { ApiError, describeError } from '../../lib/api';
import { formatDate, formatDateTime, formatMoney } from '../../lib/format';
import { REASON_LABELS } from '../cart/labels';
import CatalogError from '../catalog/CatalogError';
import {
  NUMBER_RE,
  useCancelOrder,
  useOrder,
  useReorder,
  type HistoryEntry,
  type OrderDetail,
} from './api';
import type { InvoiceStatus } from '../invoices/api';
import { CreateInvoiceButton } from '../invoices/InvoiceDialogs';
import { STATUS_LABELS as INVOICE_STATUS_LABELS } from '../invoices/urlState';
import StatusBadge from './StatusBadge';
import { STATUS_LABELS } from './urlState';

const th = 'px-3 py-2 text-left text-sm font-semibold';
const td = 'px-3 py-2 text-sm';
const btn = 'rounded border border-slate-600 px-3 py-1';

function OrderNotFound() {
  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">Order not found</h1>
      <p className="mb-2">This order does not exist or you cannot view it.</p>
      <Link to="/orders" className="text-blue-800 underline">
        Back to orders
      </Link>
    </section>
  );
}

function Timeline({ history }: { history: HistoryEntry[] }) {
  return (
    <section aria-labelledby="timeline-h" className="mt-6">
      <h2 id="timeline-h" className="mb-2 text-lg font-semibold">
        Status history
      </h2>
      <ol className="space-y-1">
        {history.map((h, i) => (
          <li key={i} className="text-sm">
            <span className="font-semibold">
              {h.from_status === null
                ? 'Order placed'
                : STATUS_LABELS[h.to_status]}
            </span>{' '}
            by {h.actor.name ?? 'Portal staff'} on{' '}
            {formatDateTime(h.created_at)}
          </li>
        ))}
      </ol>
    </section>
  );
}

function Actions({ order }: { order: OrderDetail }) {
  const cancel = useCancelOrder(order.number);
  const reorder = useReorder(order.number);
  const [confirming, setConfirming] = useState(false);
  const [notice, setNotice] = useState('');
  const canCancel = order.allowed_transitions.includes('cancelled');
  const result = reorder.data?.data;
  const cancelError =
    cancel.error instanceof ApiError && cancel.error.status === 422
      ? (cancel.error.errors.status?.[0] ?? cancel.error.message)
      : cancel.error
        ? describeError(cancel.error)
        : null;

  return (
    <div className="mt-4">
      <div className="flex gap-2">
        {canCancel && (
          <button
            type="button"
            className={btn}
            onClick={() => setConfirming(true)}
          >
            Cancel order
          </button>
        )}
        <button
          type="button"
          className={btn}
          disabled={reorder.isPending}
          onClick={() => reorder.mutate()}
        >
          Re-order
        </button>
      </div>
      <p role="status" aria-live="polite" className="mt-2 text-sm">
        {notice}
      </p>
      {cancelError && (
        <p role="alert" className="mt-2 text-red-700">
          {cancelError}
        </p>
      )}
      {reorder.isError && (
        <p role="alert" className="mt-2 text-red-700">
          {describeError(reorder.error)}
        </p>
      )}
      <div aria-live="polite" className="mt-2">
        {result && (
          <div className="rounded border border-slate-400 p-3">
            <p>
              {result.added.length}{' '}
              {result.added.length === 1 ? 'item' : 'items'} added to your cart.{' '}
              <Link to="/cart" className="text-blue-800 underline">
                View cart
              </Link>
            </p>
            {result.skipped.length > 0 && (
              <>
                <p className="mt-2 font-semibold">Not added:</p>
                <ul className="list-disc pl-5 text-sm">
                  {result.skipped.map((s, i) => (
                    <li key={s.product_id ?? `custom-${i}`}>
                      {s.part_number ? `${s.part_number} (${s.name})` : s.name}:{' '}
                      {REASON_LABELS[s.reason] ?? s.reason}
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        )}
      </div>
      {confirming && (
        <ConfirmDialog
          title="Cancel this order?"
          confirmLabel="Yes, cancel order"
          cancelLabel="Keep order"
          pending={cancel.isPending}
          onCancel={() => setConfirming(false)}
          onConfirm={() =>
            cancel.mutate(undefined, {
              onSuccess: () => setNotice('Order cancelled.'),
              onSettled: () => setConfirming(false),
            })
          }
        >
          <p>This cannot be undone.</p>
        </ConfirmDialog>
      )}
    </div>
  );
}

export default function OrderDetailPage() {
  const { number = '' } = useParams();
  const { user } = useAuth();
  const { state } = useLocation();
  const valid = NUMBER_RE.test(number);
  const q = useOrder(number, valid);
  const bannerRef = useRef<HTMLDivElement>(null);
  const nav = state as { placed?: unknown; quoteAccepted?: unknown } | null;
  const placed = nav?.placed === true;
  const fromQuote = nav?.quoteAccepted === true;
  const loaded = !!q.data;
  useEffect(() => {
    if ((placed || fromQuote) && loaded) bannerRef.current?.focus();
  }, [placed, fromQuote, loaded]);

  if (!valid) return <OrderNotFound />;
  if (q.error instanceof ApiError && q.error.status === 404)
    return <OrderNotFound />;
  if (q.isError && !q.data)
    return <CatalogError error={q.error} onRetry={() => void q.refetch()} />;
  const o = q.data;
  if (!o)
    return (
      <div role="status" aria-busy="true" aria-label="Loading order">
        <div className="mb-2 h-8 w-1/2 animate-pulse rounded bg-slate-200" />
        <div className="h-32 animate-pulse rounded bg-slate-200" />
      </div>
    );

  const isCustomer = user?.role === 'customer';
  return (
    <section>
      {(placed || fromQuote) && (
        <div
          ref={bannerRef}
          tabIndex={-1}
          role="status"
          className="mb-4 rounded border border-green-700 bg-green-50 p-3 text-green-900"
        >
          {fromQuote
            ? `Quote accepted. Order ${o.number} was created and is pending confirmation.`
            : `Thank you. Order ${o.number} was received and is pending confirmation.`}
        </div>
      )}
      <Link to="/orders" className="text-blue-800 underline">
        Back to orders
      </Link>
      <h1 className="mt-2 mb-1 text-2xl font-semibold">
        Order {o.number} <StatusBadge status={o.status} />
      </h1>
      <p className="text-sm">
        Placed by {o.placed_by.name} on {formatDate(o.created_at)}
        {!isCustomer && <> for {o.company.name}</>}
      </p>
      <div className="mb-4 text-sm">
        <p>PO reference: {o.po_reference ?? 'none'}</p>
        {o.invoice && (
          <p>
            <Link
              to={`/invoices/${o.invoice.id}`}
              className="text-blue-800 underline"
            >
              Invoice {o.invoice.number}
            </Link>{' '}
            (
            {INVOICE_STATUS_LABELS[o.invoice.status as InvoiceStatus] ??
              o.invoice.status}
            )
          </p>
        )}
        {o.quote_number && (
          <p>
            From quote{' '}
            <Link
              to={`/quotes/${o.quote_number}`}
              className="text-blue-800 underline"
            >
              {o.quote_number}
            </Link>
          </p>
        )}
      </div>

      <table className="w-full border-collapse">
        <caption className="mb-1 text-left text-sm">Prices as ordered</caption>
        <thead>
          <tr className="border-b border-slate-400 bg-slate-100">
            {[
              'Part number',
              'Name',
              'Brand',
              'Unit price',
              'Quantity',
              'Line total',
            ].map((h) => (
              <th key={h} scope="col" className={th}>
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {o.items.map((i, n) => (
            <tr
              key={i.product_id ?? `custom-${n}`}
              className="border-b border-slate-300"
            >
              <td className={td}>
                {i.product_id === null ? (
                  'Custom item'
                ) : (
                  <Link
                    to={`/catalog/${i.product_id}`}
                    className="text-blue-800 underline"
                  >
                    {i.part_number}
                  </Link>
                )}
              </td>
              <td className={td}>{i.name}</td>
              <td className={td}>{i.brand ?? '—'}</td>
              <td className={td}>
                {formatMoney(i.unit_price_cents, o.currency)}
              </td>
              <td className={td}>{i.quantity}</td>
              <td className={td}>
                {formatMoney(i.line_total_cents, o.currency)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <dl className="mt-4 ml-auto grid max-w-xs grid-cols-2 gap-1 text-right">
        <dt>Subtotal</dt>
        <dd>{formatMoney(o.subtotal_cents, o.currency)}</dd>
        {o.tax_cents > 0 && (
          <>
            <dt>Tax</dt>
            <dd>{formatMoney(o.tax_cents, o.currency)}</dd>
          </>
        )}
        {o.shipping_cents > 0 && (
          <>
            <dt>Shipping</dt>
            <dd>{formatMoney(o.shipping_cents, o.currency)}</dd>
          </>
        )}
        <dt className="font-semibold">Total</dt>
        <dd className="font-semibold">
          {formatMoney(o.total_cents, o.currency)}
        </dd>
      </dl>

      <Timeline history={o.history} />
      {isCustomer && <Actions order={o} />}
      {!isCustomer && o.can_invoice && <CreateInvoiceButton order={o} />}
    </section>
  );
}
