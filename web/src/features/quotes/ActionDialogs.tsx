import { useRef, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import ConfirmDialog from '../../components/ConfirmDialog';
import { poReferenceSchema } from '../../lib/poReference';
import {
  quoteErrorText,
  useAccept,
  useDecline,
  useReject,
  useSend,
  type QuoteDetail,
} from './api';
import { priceOrAwaiting } from './money';
import { noteSchema } from './schemas';

const ERROR_ID = 'action-dialog-error';
const fieldCls = 'w-full rounded border border-slate-500 px-2 py-1';

/**
 * Confirm dialog that owns its pending/error state. A ref guard (not just the
 * disabled button) makes a double click send once. The dialog stays open on an
 * error so the message can be read; the caller's mutation refetches stale data.
 */
export function ActionDialog({
  title,
  confirmLabel,
  cancelLabel = 'Cancel',
  tone = 'primary',
  validate,
  submit,
  onClose,
  children,
}: {
  title: string;
  confirmLabel: string;
  cancelLabel?: string;
  tone?: 'danger' | 'primary';
  validate?: () => string | null;
  submit: () => Promise<unknown>;
  onClose: () => void;
  children?: ReactNode;
}) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const guard = useRef(false);

  const confirm = async () => {
    if (guard.current) return;
    const bad = validate?.() ?? null;
    setError(bad);
    if (bad) return;
    guard.current = true;
    setPending(true);
    try {
      await submit();
      onClose();
    } catch (e) {
      setError(quoteErrorText(e));
    } finally {
      guard.current = false;
      setPending(false);
    }
  };

  return (
    <ConfirmDialog
      title={title}
      confirmLabel={confirmLabel}
      cancelLabel={cancelLabel}
      tone={tone}
      pending={pending}
      onCancel={onClose}
      onConfirm={() => void confirm()}
    >
      {children}
      {error && (
        <p id={ERROR_ID} role="alert" className="mt-2 text-red-700">
          {error}
        </p>
      )}
    </ConfirmDialog>
  );
}

const reasonError = (v: string, required: boolean) => {
  const t = v.trim();
  if (required && t === '') return 'Enter a reason.';
  if (t.length > 500) return 'Use at most 500 characters.';
  return noteSchema.safeParse(t).success ? null : 'Remove special characters.';
};

function Reason({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <div className="mt-2">
      <label htmlFor="dialog-reason" className="mb-1 block text-sm font-medium">
        {label}
      </label>
      <textarea
        id="dialog-reason"
        rows={3}
        value={value}
        aria-describedby={ERROR_ID}
        onChange={(e) => onChange(e.target.value)}
        className={fieldCls}
      />
    </div>
  );
}

export function AcceptDialog({
  quote,
  onClose,
}: {
  quote: QuoteDetail;
  onClose: () => void;
}) {
  const accept = useAccept(quote.number);
  const navigate = useNavigate();
  const [po, setPo] = useState('');
  const parsed = poReferenceSchema.safeParse(po);
  return (
    <ActionDialog
      title="Accept this quote?"
      confirmLabel="Accept quote and create order"
      tone="primary"
      onClose={onClose}
      validate={() =>
        parsed.success ? null : (parsed.error.issues[0]?.message ?? 'Invalid.')
      }
      submit={async () => {
        const res = await accept.mutateAsync({
          revision: quote.revision ?? 0,
          ...(parsed.success && parsed.data
            ? { po_reference: parsed.data }
            : {}),
        });
        if (res.data.order)
          navigate(`/orders/${res.data.order.number}`, {
            state: { quoteAccepted: true },
          });
      }}
    >
      <ul className="mb-2 text-sm">
        {quote.items.map((i) => (
          <li key={i.position}>
            {i.quantity} x {i.name}:{' '}
            {priceOrAwaiting(i.line_total_cents, quote.currency)}
          </li>
        ))}
      </ul>
      <p className="font-semibold">
        Total: {priceOrAwaiting(quote.total_cents, quote.currency)}
      </p>
      {quote.valid_until && (
        <p className="mb-2 text-sm">
          Valid through {quote.valid_until} (end of day, UTC)
        </p>
      )}
      <label htmlFor="dialog-po" className="mb-1 block text-sm font-medium">
        PO reference (optional)
      </label>
      <input
        id="dialog-po"
        autoComplete="off"
        value={po}
        aria-describedby={ERROR_ID}
        onChange={(e) => setPo(e.target.value)}
        className={fieldCls}
      />
    </ActionDialog>
  );
}

export function DeclineDialog({
  quote,
  onClose,
}: {
  quote: QuoteDetail;
  onClose: () => void;
}) {
  const decline = useDecline(quote.number);
  const [reason, setReason] = useState('');
  const withdraw = quote.status !== 'quoted';
  return (
    <ActionDialog
      title={withdraw ? 'Withdraw this quote request?' : 'Decline this quote?'}
      confirmLabel={withdraw ? 'Yes, withdraw' : 'Yes, decline'}
      cancelLabel="Keep quote"
      tone="danger"
      onClose={onClose}
      validate={() => reasonError(reason, false)}
      submit={() => decline.mutateAsync(reason.trim() || undefined)}
    >
      <p>This cannot be undone. You would need to request a new quote.</p>
      <Reason label="Reason (optional)" value={reason} onChange={setReason} />
    </ActionDialog>
  );
}

export function SendDialog({
  quote,
  onClose,
}: {
  quote: QuoteDetail;
  onClose: () => void;
}) {
  const send = useSend(quote.number);
  const [note, setNote] = useState(quote.staff_note ?? '');
  return (
    <ActionDialog
      title="Send this quote to the customer?"
      confirmLabel="Send quote"
      onClose={onClose}
      validate={() => {
        const t = note.trim();
        return noteSchema.safeParse(t).success
          ? null
          : t.length > 2000
            ? 'Use at most 2000 characters.'
            : 'Remove special characters.';
      }}
      submit={() => send.mutateAsync(note.trim() || undefined)}
    >
      <p className="mb-1">
        Total: {priceOrAwaiting(quote.total_cents, quote.currency)}
        {quote.valid_until && `, valid through ${quote.valid_until}`}
      </p>
      <label htmlFor="dialog-note" className="mb-1 block text-sm font-medium">
        Note for the customer (optional)
      </label>
      <textarea
        id="dialog-note"
        rows={3}
        value={note}
        aria-describedby={ERROR_ID}
        onChange={(e) => setNote(e.target.value)}
        className={fieldCls}
      />
    </ActionDialog>
  );
}

export function RejectDialog({
  quote,
  onClose,
}: {
  quote: QuoteDetail;
  onClose: () => void;
}) {
  const reject = useReject(quote.number);
  const [reason, setReason] = useState('');
  return (
    <ActionDialog
      title="Reject this quote request?"
      confirmLabel="Reject request"
      cancelLabel="Keep request"
      tone="danger"
      onClose={onClose}
      validate={() => reasonError(reason, true)}
      submit={() => reject.mutateAsync(reason.trim())}
    >
      <p>The customer will see the reason.</p>
      <Reason label="Reason" value={reason} onChange={setReason} />
    </ActionDialog>
  );
}
