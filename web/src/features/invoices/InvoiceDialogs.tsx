import { useMemo, useRef, useState, type ReactNode } from 'react';
import {
  useForm,
  type FieldValues,
  type Path,
  type UseFormReturn,
} from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useNavigate } from 'react-router-dom';
import ConfirmDialog from '../../components/ConfirmDialog';
import { TextField } from '../../components/FormParts';
import { ApiError, describeError } from '../../lib/api';
import { formatMoney } from '../../lib/format';
import type { OrderDetail } from '../orders/api';
import {
  useCreateInvoice,
  useMarkPaid,
  useVoidInvoice,
  type InvoiceDetail,
} from './api';
import { plusDaysUtc, todayUtc } from './dates';
import {
  MAX_DUE_DAYS,
  createInvoiceSchema,
  markPaidSchema,
  voidSchema,
  type CreateInvoiceValues,
  type MarkPaidValues,
  type VoidValues,
} from './schemas';

/** 422 messages for known fields go on the field, everything else into one dialog-level text. */
function splitError(e: unknown, fields: readonly string[]) {
  const field: Record<string, string> = {};
  if (!(e instanceof ApiError) || e.status !== 422)
    return { field, form: describeError(e) };
  const rest: string[] = [];
  for (const [k, msgs] of Object.entries(e.errors)) {
    if (fields.includes(k)) field[k] = msgs[0];
    else rest.push(...msgs);
  }
  const form = rest.length
    ? [...new Set(rest)].join(' ')
    : Object.keys(field).length
      ? null
      : e.message;
  return { field, form };
}

/**
 * Confirm dialog around a React Hook Form form. A ref guard makes a double
 * click send once; the dialog stays open on an error so the message can be read.
 */
function FormDialog<V extends FieldValues>({
  title,
  confirmLabel,
  cancelLabel = 'Cancel',
  tone = 'primary',
  form,
  fields,
  submit,
  onClose,
  children,
}: {
  title: string;
  confirmLabel: string;
  cancelLabel?: string;
  tone?: 'danger' | 'primary';
  form: UseFormReturn<V>;
  fields: readonly Path<V>[];
  submit: (values: V) => Promise<unknown>;
  onClose: () => void;
  children: ReactNode;
}) {
  const [pending, setPending] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const guard = useRef(false);

  const run = () =>
    void form.handleSubmit(async (values) => {
      if (guard.current) return;
      guard.current = true;
      setPending(true);
      setFormError(null);
      try {
        await submit(values);
        onClose();
      } catch (e) {
        const s = splitError(e, fields);
        for (const [k, message] of Object.entries(s.field))
          form.setError(k as Path<V>, { message });
        setFormError(s.form);
      } finally {
        guard.current = false;
        setPending(false);
      }
    })();

  return (
    <ConfirmDialog
      title={title}
      confirmLabel={confirmLabel}
      cancelLabel={cancelLabel}
      tone={tone}
      pending={pending}
      onCancel={onClose}
      onConfirm={run}
    >
      <form
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          run();
        }}
      >
        {children}
      </form>
      {formError && (
        <p role="alert" className="mt-2 text-red-700">
          {formError}
        </p>
      )}
    </ConfirmDialog>
  );
}

export function MarkPaidDialog({
  invoice,
  onClose,
  onDone,
}: {
  invoice: InvoiceDetail;
  onClose: () => void;
  onDone: () => void;
}) {
  const mark = useMarkPaid(invoice.id);
  const today = todayUtc();
  const schema = useMemo(
    () => markPaidSchema(invoice.issue_date, today),
    [invoice.issue_date, today],
  );
  const form = useForm<MarkPaidValues>({
    resolver: zodResolver(schema),
    defaultValues: { paid_on: today },
  });
  return (
    <FormDialog
      title="Mark this invoice as paid?"
      confirmLabel="Mark as paid"
      form={form}
      fields={['paid_on']}
      onClose={onClose}
      submit={async (v) => {
        await mark.mutateAsync({ paid_on: v.paid_on });
        onDone();
      }}
    >
      <p className="mb-3 text-sm">
        Records full payment of{' '}
        {formatMoney(invoice.total_cents, invoice.currency)} for{' '}
        {invoice.number}. This cannot be undone in the portal.
      </p>
      <TextField
        id="paid-on"
        type="date"
        label="Payment received on"
        max={today}
        min={invoice.issue_date}
        error={form.formState.errors.paid_on?.message}
        {...form.register('paid_on')}
      />
    </FormDialog>
  );
}

export function VoidDialog({
  invoice,
  onClose,
  onDone,
}: {
  invoice: InvoiceDetail;
  onClose: () => void;
  onDone: () => void;
}) {
  const voidInv = useVoidInvoice(invoice.id);
  const form = useForm<VoidValues>({
    resolver: zodResolver(voidSchema),
    defaultValues: { reason: '' },
  });
  const error = form.formState.errors.reason?.message;
  return (
    <FormDialog
      title="Void this invoice?"
      confirmLabel="Void invoice"
      cancelLabel="Keep invoice"
      tone="danger"
      form={form}
      fields={['reason']}
      onClose={onClose}
      submit={async (v) => {
        await voidInv.mutateAsync({ reason: v.reason });
        onDone();
      }}
    >
      <p className="mb-3 text-sm">
        The invoice number stays reserved and this cannot be undone. The
        customer will not see the reason.
      </p>
      <label htmlFor="void-reason" className="mb-1 block text-sm font-medium">
        Reason
      </label>
      <textarea
        id="void-reason"
        rows={3}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? 'void-reason-error' : undefined}
        className="w-full rounded border border-slate-500 px-2 py-1"
        {...form.register('reason')}
      />
      {error && (
        <p
          id="void-reason-error"
          role="alert"
          className="mt-1 text-sm text-red-700"
        >
          {error}
        </p>
      )}
    </FormDialog>
  );
}

function CreateInvoiceDialog({
  order,
  onClose,
}: {
  order: OrderDetail;
  onClose: () => void;
}) {
  const create = useCreateInvoice(order.number);
  const navigate = useNavigate();
  const today = todayUtc();
  const latest = plusDaysUtc(MAX_DUE_DAYS);
  const schema = useMemo(
    () => createInvoiceSchema(today, latest),
    [today, latest],
  );
  const form = useForm<CreateInvoiceValues>({
    resolver: zodResolver(schema),
    defaultValues: { due_date: '' },
  });
  return (
    <FormDialog
      title="Create invoice?"
      confirmLabel="Create invoice"
      form={form}
      fields={['due_date']}
      onClose={onClose}
      submit={async (v) => {
        const res = await create.mutateAsync(
          v.due_date ? { due_date: v.due_date } : {},
        );
        navigate(`/invoices/${res.data.id}`);
      }}
    >
      <p className="mb-3 text-sm">
        This issues an invoice for order {order.number} (
        {formatMoney(order.subtotal_cents, order.currency)} before VAT). The
        invoice number is assigned now and is never reused.
      </p>
      <TextField
        id="due-date"
        type="date"
        label="Due date (optional)"
        min={today}
        max={latest}
        error={form.formState.errors.due_date?.message}
        {...form.register('due_date')}
      />
      <p className="-mt-2 text-sm">
        Leave empty to use the default payment terms.
      </p>
    </FormDialog>
  );
}

/** Staff button on the order page. The server decides `can_invoice`; hiding is only UX. */
export function CreateInvoiceButton({ order }: { order: OrderDetail }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="mt-4">
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="rounded bg-blue-700 px-3 py-1 text-white"
      >
        Create invoice
      </button>
      {open && (
        <CreateInvoiceDialog order={order} onClose={() => setOpen(false)} />
      )}
    </div>
  );
}
