import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext';
import { ApiError } from '../../lib/api';
import { formatDateTime, formatMoney } from '../../lib/format';
import CatalogError from '../catalog/CatalogError';
import {
  ID_RE,
  useInvoice,
  type InvoiceDetail,
  type InvoiceHistoryEntry,
} from './api';
import DownloadPdfButton from './DownloadPdfButton';
import { MarkPaidDialog, VoidDialog } from './InvoiceDialogs';
import StatusBadge from './StatusBadge';
import { STATUS_LABELS } from './urlState';

const th = 'px-3 py-2 text-left text-sm font-semibold';
const td = 'px-3 py-2 text-sm';
const btn = 'rounded border border-slate-600 px-3 py-1';

function InvoiceNotFound() {
  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">Invoice not found</h1>
      <p className="mb-2">This invoice does not exist or you cannot view it.</p>
      <Link to="/invoices" className="text-blue-800 underline">
        Back to invoices
      </Link>
    </section>
  );
}

/** Address block: every line is its own text node, never HTML. */
function Party({
  heading,
  name,
  lines,
  children,
}: {
  heading: string;
  name: string;
  lines: string[];
  children?: ReactNode;
}) {
  const id = `party-${heading.replace(/\W+/g, '-').toLowerCase()}`;
  return (
    <section aria-labelledby={id}>
      <h2 id={id} className="mb-1 text-lg font-semibold">
        {heading}
      </h2>
      <p className="font-semibold">{name}</p>
      {lines.map((l, i) => (
        <p key={i}>{l}</p>
      ))}
      {children}
    </section>
  );
}

function Timeline({
  history,
  showNotes,
}: {
  history: InvoiceHistoryEntry[];
  showNotes: boolean;
}) {
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
                ? 'Invoice issued'
                : STATUS_LABELS[h.to_status]}
            </span>{' '}
            by{' '}
            {h.actor.role === 'system'
              ? 'System'
              : (h.actor.name ?? 'Portal staff')}{' '}
            on {formatDateTime(h.created_at)}
            {showNotes && h.note && <> (Reason: {h.note})</>}
          </li>
        ))}
      </ol>
    </section>
  );
}

function Totals({ inv }: { inv: InvoiceDetail }) {
  const rate = inv.vat_rate_bp / 100;
  return (
    <dl className="mt-4 ml-auto grid max-w-xs grid-cols-2 gap-1 text-right">
      <dt>Subtotal</dt>
      <dd>{formatMoney(inv.subtotal_cents, inv.currency)}</dd>
      <dt>
        {inv.seller.vat_label || 'VAT'} {rate}%
      </dt>
      <dd>{formatMoney(inv.tax_cents, inv.currency)}</dd>
      <dt className="font-semibold">Total</dt>
      <dd className="font-semibold">
        {formatMoney(inv.total_cents, inv.currency)}
      </dd>
    </dl>
  );
}

function Detail({ inv }: { inv: InvoiceDetail }) {
  const { user } = useAuth();
  const [dialog, setDialog] = useState<'pay' | 'void' | null>(null);
  const [notice, setNotice] = useState('');
  const headingRef = useRef<HTMLHeadingElement>(null);
  const can = (a: string) => inv.allowed_actions.includes(a);
  const isStaff = user?.role !== 'customer';

  // The dialog's opener may disappear once the invoice is paid or void: move focus to the heading.
  useEffect(() => {
    if (notice) headingRef.current?.focus();
  }, [notice]);

  const { seller, buyer } = inv;
  const bank = seller.bank;
  return (
    <section>
      <Link to="/invoices" className="text-blue-800 underline">
        Back to invoices
      </Link>
      <h1
        ref={headingRef}
        tabIndex={-1}
        className="mt-2 mb-2 text-2xl font-semibold"
      >
        Invoice {inv.number} <StatusBadge status={inv.status} />
      </h1>
      {inv.days_overdue > 0 && (
        <div
          role="status"
          className="mb-4 flex items-center gap-2 rounded border border-red-800 bg-red-50 p-3 font-semibold text-red-900"
        >
          <svg
            aria-hidden="true"
            focusable="false"
            viewBox="0 0 20 20"
            className="h-4 w-4 shrink-0"
            fill="currentColor"
          >
            <path d="M10 2 1 18h18L10 2Zm-1 6h2v5H9V8Zm0 6h2v2H9v-2Z" />
          </svg>
          Payment is {inv.days_overdue}{' '}
          {inv.days_overdue === 1 ? 'day' : 'days'} overdue (due {inv.due_date})
        </div>
      )}
      <p role="status" aria-live="polite" className="min-h-6 text-sm">
        {notice}
      </p>

      <div className="mb-4 flex flex-wrap items-start gap-3">
        {can('download') && (
          <DownloadPdfButton id={inv.id} number={inv.number} />
        )}
        {can('mark_paid') && (
          <button
            type="button"
            className={btn}
            onClick={() => setDialog('pay')}
          >
            Mark as paid
          </button>
        )}
        {can('void') && (
          <button
            type="button"
            className={btn}
            onClick={() => setDialog('void')}
          >
            Void invoice
          </button>
        )}
      </div>

      <div className="mb-4 grid gap-6 sm:grid-cols-2">
        <Party heading="Seller" name={seller.name} lines={seller.address_lines}>
          {seller.tax_id && <p>Tax ID: {seller.tax_id}</p>}
          {seller.email && <p>{seller.email}</p>}
          {seller.phone && <p>{seller.phone}</p>}
        </Party>
        <Party
          heading="Billed to"
          name={buyer.name}
          lines={buyer.address_lines}
        >
          {buyer.tax_id && <p>Tax ID: {buyer.tax_id}</p>}
        </Party>
      </div>

      <dl className="mb-4 grid max-w-md grid-cols-2 gap-1 text-sm">
        <dt className="font-semibold">Issued</dt>
        <dd>{inv.issue_date}</dd>
        <dt className="font-semibold">Due</dt>
        <dd>{inv.due_date}</dd>
        {inv.paid_on && (
          <>
            <dt className="font-semibold">Paid on</dt>
            <dd>{inv.paid_on}</dd>
          </>
        )}
        <dt className="font-semibold">PO reference</dt>
        <dd>{inv.po_reference ?? 'none'}</dd>
        <dt className="font-semibold">Order</dt>
        <dd>
          <Link
            to={`/orders/${inv.order_number}`}
            className="text-blue-800 underline"
          >
            Order {inv.order_number}
          </Link>
        </dd>
      </dl>

      <table className="w-full border-collapse">
        <caption className="mb-1 text-left text-sm">Invoice lines</caption>
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
          {inv.items.map((i) => (
            <tr key={i.position} className="border-b border-slate-300">
              <td className={td}>{i.part_number ?? 'Custom item'}</td>
              <td className={td}>{i.name}</td>
              <td className={td}>{i.brand ?? '—'}</td>
              <td className={td}>
                {formatMoney(i.unit_price_cents, inv.currency)}
              </td>
              <td className={td}>{i.quantity}</td>
              <td className={td}>
                {formatMoney(i.line_total_cents, inv.currency)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <Totals inv={inv} />

      {(seller.payment_terms_text || bank.iban) && (
        <section aria-labelledby="payment-h" className="mt-6">
          <h2 id="payment-h" className="mb-1 text-lg font-semibold">
            Payment
          </h2>
          {seller.payment_terms_text && <p>{seller.payment_terms_text}</p>}
          <dl className="mt-1 grid max-w-md grid-cols-2 gap-1 text-sm">
            {(
              [
                ['Account holder', bank.account_holder],
                ['Bank', bank.bank_name],
                ['IBAN', bank.iban],
                ['BIC', bank.bic],
              ] as const
            ).map(
              ([label, value]) =>
                value && (
                  <div key={label} className="contents">
                    <dt className="font-semibold">{label}</dt>
                    <dd>{value}</dd>
                  </div>
                ),
            )}
          </dl>
        </section>
      )}

      <Timeline history={inv.history} showNotes={isStaff} />

      {dialog === 'pay' && (
        <MarkPaidDialog
          invoice={inv}
          onClose={() => setDialog(null)}
          onDone={() => setNotice('Invoice marked as paid.')}
        />
      )}
      {dialog === 'void' && (
        <VoidDialog
          invoice={inv}
          onClose={() => setDialog(null)}
          onDone={() => setNotice('Invoice voided.')}
        />
      )}
    </section>
  );
}

export default function InvoiceDetailPage() {
  const { id = '' } = useParams();
  const valid = ID_RE.test(id);
  const q = useInvoice(id, valid);

  if (!valid) return <InvoiceNotFound />;
  if (q.error instanceof ApiError && q.error.status === 404)
    return <InvoiceNotFound />;
  if (q.isError && !q.data)
    return <CatalogError error={q.error} onRetry={() => void q.refetch()} />;
  if (!q.data)
    return (
      <div role="status" aria-busy="true" aria-label="Loading invoice">
        <div className="mb-2 h-8 w-1/2 animate-pulse rounded bg-slate-200" />
        <div className="h-32 animate-pulse rounded bg-slate-200" />
      </div>
    );
  return <Detail inv={q.data} />;
}
