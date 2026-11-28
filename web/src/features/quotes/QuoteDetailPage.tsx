import ScrollRegion from '../../components/ScrollRegion';
import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext';
import { ApiError } from '../../lib/api';
import { formatDate, formatDateTime } from '../../lib/format';
import CatalogError from '../catalog/CatalogError';
import {
  AcceptDialog,
  DeclineDialog,
  RejectDialog,
  SendDialog,
} from './ActionDialogs';
import {
  NUMBER_RE,
  quoteErrorText,
  useQuote,
  useReopen,
  useReview,
  useSaveInternalNote,
  type QuoteDetail,
  type QuoteHistoryEntry,
} from './api';
import AttachmentsPanel from './AttachmentsPanel';
import { priceOrAwaiting } from './money';
import PricingForm from './PricingForm';
import { noteSchema } from './schemas';
import StatusBadge from './StatusBadge';
import { STATUS_LABELS } from './urlState';
import type { UploadFailure } from './useUploads';

const th = 'px-3 py-2 text-left text-sm font-semibold';
const td = 'px-3 py-2 text-sm';
const btn = 'rounded border border-slate-600 px-3 py-1 disabled:opacity-60';

function QuoteNotFound({ basePath }: { basePath: string }) {
  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">Quote not found</h1>
      <p className="mb-2">This quote does not exist or you cannot view it.</p>
      <Link to={`${basePath}/quotes`} className="text-blue-800 underline">
        Back to quotes
      </Link>
    </section>
  );
}

function Timeline({ history }: { history: QuoteHistoryEntry[] }) {
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
                ? 'Request submitted'
                : STATUS_LABELS[h.to_status]}
            </span>{' '}
            by{' '}
            {h.actor.name ??
              (h.actor.role === 'system' ? 'the system' : 'Portal staff')}{' '}
            on {formatDateTime(h.created_at)}
            {h.note && <span className="block pl-4">Reason: {h.note}</span>}
          </li>
        ))}
      </ol>
    </section>
  );
}

function InternalNote({ quote }: { quote: QuoteDetail }) {
  const save = useSaveInternalNote(quote.number);
  const [text, setText] = useState(quote.internal_note ?? '');
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaved(false);
    const t = text.trim();
    if (!noteSchema.safeParse(t).success) {
      setError(
        t.length > 2000
          ? 'Use at most 2000 characters.'
          : 'Remove special characters.',
      );
      return;
    }
    setError(null);
    try {
      await save.mutateAsync(t === '' ? null : t);
      setSaved(true);
    } catch (err) {
      setError(quoteErrorText(err));
    }
  };
  return (
    <form
      onSubmit={(e) => void onSubmit(e)}
      noValidate
      className="mt-6 max-w-xl"
    >
      <label htmlFor="internal_note" className="mb-1 block font-semibold">
        Internal note (never shown to the customer)
      </label>
      <textarea
        id="internal_note"
        rows={3}
        value={text}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? 'internal_note-error' : undefined}
        onChange={(e) => setText(e.target.value)}
        className="w-full rounded border border-slate-500 px-3 py-2"
      />
      {error && (
        <p id="internal_note-error" role="alert" className="mt-1 text-red-700">
          {error}
        </p>
      )}
      <p role="status" aria-live="polite" className="my-1 min-h-5 text-sm">
        {saved ? 'Internal note saved.' : ''}
      </p>
      <button type="submit" disabled={save.isPending} className={btn}>
        Save internal note
      </button>
    </form>
  );
}

function Actions({ quote }: { quote: QuoteDetail }) {
  const review = useReview(quote.number);
  const reopen = useReopen(quote.number);
  const [dialog, setDialog] = useState<
    'accept' | 'decline' | 'send' | 'reject' | null
  >(null);
  const [error, setError] = useState<string | null>(null);
  const has = (a: QuoteDetail['allowed_actions'][number]) =>
    quote.allowed_actions.includes(a);
  const direct = (run: () => Promise<unknown>) => async () => {
    setError(null);
    try {
      await run();
    } catch (e) {
      setError(quoteErrorText(e));
    }
  };
  const close = () => setDialog(null);
  const busy = review.isPending || reopen.isPending;

  return (
    <div className="mt-6">
      <div className="flex flex-wrap gap-2">
        {has('accept') && (
          <button
            type="button"
            onClick={() => setDialog('accept')}
            className="rounded bg-blue-700 px-3 py-1 text-white"
          >
            Accept quote
          </button>
        )}
        {has('decline') && (
          <button
            type="button"
            onClick={() => setDialog('decline')}
            className={btn}
          >
            {quote.status === 'quoted' ? 'Decline quote' : 'Withdraw request'}
          </button>
        )}
        {has('review') && (
          <button
            type="button"
            disabled={busy}
            onClick={() => void direct(() => review.mutateAsync())()}
            className={btn}
          >
            Take into review
          </button>
        )}
        {has('send') && (
          <button
            type="button"
            onClick={() => setDialog('send')}
            className={btn}
          >
            Send quote
          </button>
        )}
        {has('reopen') && (
          <button
            type="button"
            disabled={busy}
            onClick={() => void direct(() => reopen.mutateAsync())()}
            className={btn}
          >
            Reopen for editing
          </button>
        )}
        {has('reject') && (
          <button
            type="button"
            onClick={() => setDialog('reject')}
            className={btn}
          >
            Reject request
          </button>
        )}
      </div>
      {error && (
        <p role="alert" className="mt-2 text-red-700">
          {error}
        </p>
      )}
      {dialog === 'accept' && <AcceptDialog quote={quote} onClose={close} />}
      {dialog === 'decline' && <DeclineDialog quote={quote} onClose={close} />}
      {dialog === 'send' && <SendDialog quote={quote} onClose={close} />}
      {dialog === 'reject' && <RejectDialog quote={quote} onClose={close} />}
    </div>
  );
}

export default function QuoteDetailPage({
  basePath = '',
}: {
  basePath?: string;
}) {
  const { number = '' } = useParams();
  const { user } = useAuth();
  const { state } = useLocation();
  const valid = NUMBER_RE.test(number);
  const q = useQuote(number, valid);
  const bannerRef = useRef<HTMLDivElement>(null);
  const nav = state as {
    created?: boolean;
    uploadFailures?: UploadFailure[];
  } | null;
  const created = nav?.created === true;
  const failures = nav?.uploadFailures ?? [];
  const loaded = !!q.data;
  useEffect(() => {
    if (created && loaded) bannerRef.current?.focus();
  }, [created, loaded]);

  if (!valid) return <QuoteNotFound basePath={basePath} />;
  if (q.error instanceof ApiError && q.error.status === 404)
    return <QuoteNotFound basePath={basePath} />;
  if (q.isError && !q.data)
    return <CatalogError error={q.error} onRetry={() => void q.refetch()} />;
  const o = q.data;
  if (!o)
    return (
      <div role="status" aria-busy="true" aria-label="Loading quote">
        <div className="mb-2 h-8 w-1/2 animate-pulse motion-reduce:animate-none rounded bg-slate-200" />
        <div className="h-32 animate-pulse motion-reduce:animate-none rounded bg-slate-200" />
      </div>
    );

  const isCustomer = user?.role === 'customer';
  return (
    <section>
      {created && (
        <div
          ref={bannerRef}
          tabIndex={-1}
          role="status"
          className="mb-4 rounded border border-green-700 bg-green-50 p-3 text-green-900"
        >
          Thank you. Quote request {o.number} was submitted. Our staff will
          price it and email you.
        </div>
      )}
      {failures.length > 0 && (
        <div
          role="alert"
          className="mb-4 rounded border border-red-700 bg-red-50 p-3 text-red-800"
        >
          <p>
            Some files could not be uploaded. Select them again under
            Attachments to retry:
          </p>
          <ul className="list-disc pl-5">
            {failures.map((f, i) => (
              <li key={`${f.name}-${i}`}>
                {f.name}: {f.message}
              </li>
            ))}
          </ul>
        </div>
      )}
      {o.status === 'expired' && (
        <div
          role="status"
          className="mb-4 rounded border border-slate-600 bg-slate-100 p-3"
        >
          This quote expired{o.valid_until ? ` on ${o.valid_until}` : ''}.{' '}
          {isCustomer && (
            <Link to="/quotes/new" className="text-blue-800 underline">
              Request a new quote
            </Link>
          )}
        </div>
      )}
      <Link to={`${basePath}/quotes`} className="text-blue-800 underline">
        Back to quotes
      </Link>
      <h1 className="mt-2 mb-1 text-2xl font-semibold">
        Quote {o.number} <StatusBadge status={o.status} />
      </h1>
      <p className="text-sm">
        Requested by {o.requested_by.name} on {formatDate(o.created_at)}
        {!isCustomer && (
          <>
            {' '}
            for{' '}
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
          </>
        )}
      </p>
      <p className="text-sm">PO reference: {o.po_reference ?? 'None'}</p>
      {o.order && (
        <p className="text-sm">
          Order{' '}
          <Link
            to={`${basePath}/orders/${o.order.number}`}
            className="text-blue-800 underline"
          >
            {o.order.number}
          </Link>
        </p>
      )}
      {o.customer_note && (
        <div className="mt-3">
          <h2 className="font-semibold">Customer note</h2>
          <p className="text-sm whitespace-pre-wrap">{o.customer_note}</p>
        </div>
      )}
      {o.staff_note && (
        <div className="mt-3">
          <h2 className="font-semibold">Note from our staff</h2>
          <p className="text-sm whitespace-pre-wrap">{o.staff_note}</p>
        </div>
      )}

      <ScrollRegion label="Quote lines table">
        <table className="mt-4 w-full border-collapse">
          <caption className="mb-1 text-left text-sm">Requested items</caption>
          <thead>
            <tr className="border-b border-slate-400 bg-slate-100">
              {[
                'Item',
                'Name',
                'Brand',
                'Quantity',
                'Unit price',
                'Line total',
              ].map((h) => (
                <th key={h} scope="col" className={th}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {o.items.map((i) => (
              <tr key={i.position} className="border-b border-slate-300">
                <td className={td}>
                  {i.kind === 'catalog' && i.product_id !== null ? (
                    <Link
                      to={`/catalog/${i.product_id}`}
                      className="text-blue-800 underline"
                    >
                      {i.part_number}
                    </Link>
                  ) : (
                    'Custom item'
                  )}
                </td>
                <td className={td}>{i.name}</td>
                <td className={td}>{i.brand ?? '—'}</td>
                <td className={td}>{i.quantity}</td>
                <td className={td}>
                  {priceOrAwaiting(i.unit_price_cents, o.currency)}
                </td>
                <td className={td}>
                  {priceOrAwaiting(i.line_total_cents, o.currency)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </ScrollRegion>
      <dl className="mt-4 ml-auto grid max-w-xs grid-cols-2 gap-1 text-right">
        <dt>Subtotal</dt>
        <dd>{priceOrAwaiting(o.subtotal_cents, o.currency)}</dd>
        {o.tax_cents > 0 && (
          <>
            <dt>Tax</dt>
            <dd>{priceOrAwaiting(o.tax_cents, o.currency)}</dd>
          </>
        )}
        {o.shipping_cents > 0 && (
          <>
            <dt>Shipping</dt>
            <dd>{priceOrAwaiting(o.shipping_cents, o.currency)}</dd>
          </>
        )}
        <dt className="font-semibold">Total</dt>
        <dd className="font-semibold">
          {priceOrAwaiting(o.total_cents, o.currency)}
        </dd>
      </dl>
      {o.valid_until && (
        <p className="mt-2 text-right text-sm">
          Valid through {o.valid_until} (end of day, UTC)
        </p>
      )}

      {o.allowed_actions.includes('price') && <PricingForm quote={o} />}
      <Actions quote={o} />
      {o.allowed_actions.includes('note') && <InternalNote quote={o} />}
      <AttachmentsPanel quote={o} />
      <Timeline history={o.history} />
    </section>
  );
}
