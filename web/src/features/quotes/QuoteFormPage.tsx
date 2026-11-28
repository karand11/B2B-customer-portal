import { useEffect, useMemo, useRef, useState } from 'react';
import { useQueries } from '@tanstack/react-query';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import {
  useFieldArray,
  useForm,
  useWatch,
  type FieldErrors,
} from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { ApiError, api } from '../../lib/api';
import { parseQuantity } from '../cart/api';
import type { Product, ProductDetail } from '../catalog/api';
import { useCreateQuote, quoteErrorText, type CreateQuoteBody } from './api';
import ProductPicker from './ProductPicker';
import {
  ACCEPT_ATTR,
  MAX_LINES,
  MAX_NOTE,
  MAX_PRODUCT_PARAMS,
  checkFiles,
  formatBytes,
  quoteFormSchema,
  type LineValues,
  type QuoteFormValues,
} from './schemas';
import { UPLOAD_LABELS, useUploads } from './useUploads';

const field = 'w-full rounded border border-slate-500 px-3 py-2';
const btn = 'rounded border border-slate-600 px-3 py-1 disabled:opacity-60';

const catalogLine = (
  p: Pick<Product, 'id' | 'part_number' | 'name'>,
): LineValues => ({
  kind: 'catalog',
  product_id: p.id,
  part_number: p.part_number,
  name: p.name,
  description: '',
  quantity: '1',
});

/** Ids from ?product=: only integers, unique, at most 20; the rest counts as dropped. */
function parseProductParams(all: string[]) {
  const unique = [...new Set(all.map((v) => v.trim()))];
  const valid = unique.filter((v) => /^\d{1,18}$/.test(v));
  const ids = valid.slice(0, MAX_PRODUCT_PARAMS);
  return { ids, dropped: unique.length - ids.length };
}

function Err({ id, message }: { id: string; message?: string }) {
  if (!message) return null;
  return (
    <p id={id} role="alert" className="mt-1 text-sm text-red-700">
      {message}
    </p>
  );
}

/** Flat list of every current problem, for the summary that takes focus on a failed submit. */
function summarize(
  errors: FieldErrors<QuoteFormValues>,
  count: number,
  extra: string | null,
): string[] {
  const out: string[] = [];
  if (errors.po_reference?.message)
    out.push(`PO reference: ${errors.po_reference.message}`);
  if (errors.customer_note?.message)
    out.push(`Note: ${errors.customer_note.message}`);
  const lines = errors.lines;
  if (lines?.root?.message) out.push(lines.root.message);
  else if (lines?.message) out.push(lines.message);
  for (let i = 0; i < count; i += 1) {
    const e = lines?.[i];
    for (const k of ['product_id', 'description', 'quantity'] as const) {
      const m = e?.[k]?.message;
      if (m) out.push(`Item ${i + 1}: ${m}`);
    }
  }
  if (extra) out.push(extra);
  return out;
}

export default function QuoteFormPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const create = useCreateQuote();
  const uploads = useUploads();
  const inFlight = useRef(false); // sync guard: a double click beats any re-render
  const [picker, setPicker] = useState(false);
  const [files, setFiles] = useState<File[]>([]);
  const [rejected, setRejected] = useState<{ name: string; reason: string }[]>(
    [],
  );
  const [serverMessage, setServerMessage] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const summaryRef = useRef<HTMLDivElement>(null);
  const focusId = useRef<string | null>(null);

  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<QuoteFormValues>({
    resolver: zodResolver(quoteFormSchema),
    defaultValues: { po_reference: '', customer_note: '', lines: [] },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'lines' });
  const lines = useWatch({ control, name: 'lines' });
  const note = useWatch({ control, name: 'customer_note' });
  const full = fields.length >= MAX_LINES;

  // ---- prefill from the catalog ("Request quote"): only ids come from the URL ----
  const { ids, dropped } = useMemo(
    () => parseProductParams(params.getAll('product')),
    [params],
  );
  const prefill = useQueries({
    queries: ids.map((id) => ({
      queryKey: ['catalog', 'product', id],
      queryFn: async () =>
        (await api.get<{ data: ProductDetail }>(`/api/v1/products/${id}`)).data,
      retry: false,
    })),
    combine: (rs) => ({
      settled: rs.every((r) => r.isSuccess || r.isError),
      products: rs.flatMap((r) => (r.data ? [r.data] : [])),
      failed: rs.filter((r) => r.isError).length,
    }),
  });
  const seeded = useRef(false);
  useEffect(() => {
    if (!prefill.settled || seeded.current) return;
    seeded.current = true;
    append(prefill.products.map(catalogLine));
  }, [prefill.settled, prefill.products, append]);
  const prefillDropped =
    ids.length > 0 && !prefill.settled ? 0 : dropped + prefill.failed;

  // ---- focus management ----
  useEffect(() => {
    const target = focusId.current;
    focusId.current = null;
    if (!target) return;
    const el =
      target === 'last'
        ? [...document.querySelectorAll<HTMLElement>('[data-line-first]')].at(
            -1,
          )
        : document.getElementById(target);
    el?.focus();
  }, [fields.length]);
  useEffect(() => {
    if (attempt > 0) summaryRef.current?.focus();
  }, [attempt]);

  const addCustom = () => {
    if (full) return;
    append({
      kind: 'custom',
      description: '',
      quantity: '1',
    });
    focusId.current = 'last';
  };
  const removeLine = (i: number) => {
    const next = fields[i + 1];
    focusId.current = next ? `line-${next.id}-first` : 'add-custom-item';
    remove(i);
  };

  const onFiles = (list: FileList | null) => {
    const check = checkFiles([...(list ?? [])], {
      count: files.length,
      bytes: files.reduce((n, f) => n + f.size, 0),
    });
    setFiles((cur) => [...cur, ...check.accepted]);
    setRejected(check.rejected);
  };

  const mapServerErrors = (e: unknown) => {
    const rest: string[] = [];
    if (e instanceof ApiError && e.status === 422) {
      for (const [key, msgs] of Object.entries(e.errors)) {
        const m = msgs[0] ?? e.message;
        const line = /^lines\.(\d+)\.(product_id|description|quantity)$/.exec(
          key,
        );
        if (key === 'po_reference' || key === 'customer_note')
          setError(key, { message: m });
        else if (line)
          setError(`lines.${Number(line[1])}.${line[2] as 'quantity'}`, {
            message: m,
          });
        else rest.push(m);
      }
      if (Object.keys(e.errors).length === 0) rest.push(quoteErrorText(e));
    } else rest.push(quoteErrorText(e));
    setServerMessage(rest.length ? rest.join(' ') : null);
    setAttempt((n) => n + 1);
  };

  const submit = async (v: QuoteFormValues) => {
    if (inFlight.current) return;
    inFlight.current = true;
    setServerMessage(null);
    const body: CreateQuoteBody = {
      ...(v.po_reference ? { po_reference: v.po_reference } : {}),
      ...(v.customer_note ? { customer_note: v.customer_note } : {}),
      lines: v.lines.flatMap((l): CreateQuoteBody['lines'] => {
        const quantity = parseQuantity(l.quantity);
        if (quantity === null) return [];
        if (l.kind === 'custom')
          return [{ description: l.description.trim(), quantity }];
        return l.product_id === undefined
          ? []
          : [{ product_id: l.product_id, quantity }];
      }),
    };
    try {
      const res = await create.mutateAsync(body);
      const number = res.data.number;
      // The quote exists now: it is never re-created, whatever happens to the files.
      const failures = files.length ? await uploads.run(number, files) : [];
      navigate(`/quotes/${number}`, {
        state: { created: true, uploadFailures: failures },
      });
    } catch (e) {
      mapServerErrors(e);
    } finally {
      inFlight.current = false;
    }
  };

  const summary =
    attempt > 0 ? summarize(errors, fields.length, serverMessage) : [];
  const pending = create.isPending || uploads.busy;
  const added = new Set(
    (lines ?? []).flatMap((l) => (l.product_id ? [l.product_id] : [])),
  );
  const rootError = errors.lines?.root?.message ?? errors.lines?.message;

  return (
    <section>
      <h1 className="mb-4 text-2xl font-semibold">Request a quote</h1>
      <p className="mb-4 text-sm">
        Tell us what you need. Our staff will price it and send you a quote.{' '}
        <Link to="/quotes" className="text-blue-800 underline">
          Back to quotes
        </Link>
      </p>
      {prefillDropped > 0 && (
        <p
          role="status"
          className="mb-4 rounded border border-amber-700 bg-amber-50 p-3"
        >
          {prefillDropped} {prefillDropped === 1 ? 'item' : 'items'} could not
          be added.
        </p>
      )}
      {summary.length > 0 && (
        <div
          ref={summaryRef}
          tabIndex={-1}
          role="group"
          aria-labelledby="summary-h"
          className="mb-4 rounded border border-red-700 bg-red-50 p-3 text-red-800"
        >
          <h2 id="summary-h" className="font-semibold">
            Please fix the following
          </h2>
          <ul className="list-disc pl-5">
            {summary.map((m) => (
              <li key={m}>{m}</li>
            ))}
          </ul>
        </div>
      )}
      <form
        onSubmit={(e) =>
          void handleSubmit(submit, () => setAttempt((n) => n + 1))(e)
        }
        noValidate
        className="max-w-3xl"
      >
        <div className="mb-4 max-w-md">
          <label
            htmlFor="po_reference"
            className="mb-1 block text-sm font-medium"
          >
            PO reference (optional)
          </label>
          <input
            id="po_reference"
            autoComplete="off"
            maxLength={80}
            aria-invalid={errors.po_reference ? true : undefined}
            aria-describedby={
              errors.po_reference ? 'po_reference-error' : undefined
            }
            className={field}
            {...register('po_reference')}
          />
          <Err id="po_reference-error" message={errors.po_reference?.message} />
        </div>

        <div className="mb-4">
          <label
            htmlFor="customer_note"
            className="mb-1 block text-sm font-medium"
          >
            Note for our team (optional)
          </label>
          <textarea
            id="customer_note"
            rows={3}
            aria-invalid={errors.customer_note ? true : undefined}
            aria-describedby={
              errors.customer_note
                ? 'customer_note-error'
                : 'customer_note-count'
            }
            className={field}
            {...register('customer_note')}
          />
          <p id="customer_note-count" className="mt-1 text-xs text-slate-700">
            {(note ?? '').length} / {MAX_NOTE}
          </p>
          <Err
            id="customer_note-error"
            message={errors.customer_note?.message}
          />
        </div>

        <h2 className="mb-2 text-lg font-semibold">Items</h2>
        {fields.length === 0 && (
          <p className="mb-2 text-sm">No items yet. Add at least one item.</p>
        )}
        {fields.map((f, i) => {
          const e = errors.lines?.[i];
          const first = `line-${f.id}-first`;
          const qtyId = f.kind === 'catalog' ? first : `line-${f.id}-qty`;
          return (
            <fieldset
              key={f.id}
              className="mb-3 rounded border border-slate-400 p-3"
            >
              <legend className="px-1 text-sm font-semibold">
                Item {i + 1}
              </legend>
              {f.kind === 'catalog' ? (
                <>
                  <p className="mb-2 text-sm">
                    <span className="font-semibold">{f.part_number}</span>{' '}
                    {f.name}
                  </p>
                  <Err
                    id={`line-${f.id}-product-error`}
                    message={e?.product_id?.message}
                  />
                </>
              ) : (
                <div className="mb-2">
                  <label
                    htmlFor={first}
                    className="mb-1 block text-sm font-medium"
                  >
                    Description
                  </label>
                  <input
                    id={first}
                    data-line-first=""
                    autoComplete="off"
                    aria-invalid={e?.description ? true : undefined}
                    aria-describedby={
                      e?.description ? `line-${f.id}-desc-error` : undefined
                    }
                    className={field}
                    {...register(`lines.${i}.description`)}
                  />
                  <Err
                    id={`line-${f.id}-desc-error`}
                    message={e?.description?.message}
                  />
                </div>
              )}
              <div className="flex flex-wrap items-end gap-3">
                <div>
                  <label
                    htmlFor={qtyId}
                    className="mb-1 block text-sm font-medium"
                  >
                    Quantity
                  </label>
                  <input
                    id={qtyId}
                    data-line-first={f.kind === 'catalog' ? '' : undefined}
                    inputMode="numeric"
                    autoComplete="off"
                    aria-invalid={e?.quantity ? true : undefined}
                    aria-describedby={
                      e?.quantity ? `line-${f.id}-qty-error` : undefined
                    }
                    className="w-24 rounded border border-slate-500 px-3 py-2"
                    {...register(`lines.${i}.quantity`)}
                  />
                </div>
                <button
                  type="button"
                  onClick={() => removeLine(i)}
                  className={btn}
                >
                  Remove line <span className="sr-only">{i + 1}</span>
                </button>
              </div>
              <Err
                id={`line-${f.id}-qty-error`}
                message={e?.quantity?.message}
              />
            </fieldset>
          );
        })}
        {rootError && (
          <p role="alert" className="mb-2 text-red-700">
            {rootError}
          </p>
        )}
        {full && (
          <p className="mb-2 text-sm">
            You can request at most {MAX_LINES} items.
          </p>
        )}
        <div className="mb-4 flex flex-wrap gap-2">
          <button
            type="button"
            disabled={full}
            aria-expanded={picker}
            onClick={() => setPicker(true)}
            className={btn}
          >
            Add catalog item
          </button>
          <button
            type="button"
            id="add-custom-item"
            disabled={full}
            onClick={addCustom}
            className={btn}
          >
            Add custom item
          </button>
        </div>
        {picker && (
          <ProductPicker
            added={added}
            full={full}
            onAdd={(p) => append(catalogLine(p))}
            onClose={() => setPicker(false)}
          />
        )}

        <h2 className="mb-2 text-lg font-semibold">Attachments (optional)</h2>
        <label htmlFor="quote-files" className="mb-1 block text-sm font-medium">
          Add files
        </label>
        <input
          id="quote-files"
          className="block w-full max-w-full"
          type="file"
          multiple
          accept={ACCEPT_ATTR}
          aria-describedby="quote-files-hint"
          onChange={(e) => {
            onFiles(e.target.files);
            e.target.value = '';
          }}
        />
        <p id="quote-files-hint" className="mt-1 mb-2 text-xs text-slate-700">
          PDF, PNG, JPG, TXT or CSV. Up to 10 files, 10 MB each, 25 MB in total.
        </p>
        {rejected.length > 0 && (
          <ul role="alert" className="mb-2 list-disc pl-5 text-sm text-red-700">
            {rejected.map((r, i) => (
              <li key={`${r.name}-${i}`}>
                {r.name}: {r.reason}
              </li>
            ))}
          </ul>
        )}
        {files.length > 0 && (
          <ul aria-label="Files to upload" className="mb-2 text-sm">
            {files.map((f, i) => (
              <li key={`${f.name}-${i}`} className="flex items-center gap-2">
                {f.name} ({formatBytes(f.size)})
                <button
                  type="button"
                  aria-label={`Remove file ${f.name}`}
                  disabled={pending}
                  onClick={() =>
                    setFiles((cur) => cur.filter((_, k) => k !== i))
                  }
                  className={btn}
                >
                  Remove
                </button>
              </li>
            ))}
          </ul>
        )}
        <div aria-live="polite" className="mb-2 text-sm">
          {uploads.items.map((u) => (
            <p key={u.name}>
              {u.name}: {UPLOAD_LABELS[u.state]}
              {u.message ? ` (${u.message})` : ''}
            </p>
          ))}
        </div>

        <button
          type="submit"
          disabled={pending}
          className="rounded bg-blue-700 px-4 py-2 text-white hover:bg-blue-800 disabled:opacity-60"
        >
          {pending ? 'Submitting...' : 'Submit quote request'}
        </button>
      </form>
    </section>
  );
}
