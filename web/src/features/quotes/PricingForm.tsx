import { useState } from 'react';
import { useFieldArray, useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { ApiError } from '../../lib/api';
import { formatMoney } from '../../lib/format';
import { isRealDate } from '../orders/urlState';
import { quoteErrorText, useSavePricing, type QuoteDetail } from './api';
import {
  PRICE_ERROR,
  centsToInput,
  parsePrice,
  priceOrAwaiting,
} from './money';

const DAY = 86_400_000;
const utcDay = (offsetDays: number) =>
  new Date(Date.now() + offsetDays * DAY).toISOString().slice(0, 10);

/** Tomorrow to +365 days, UTC, like the API. The server stays the authority. */
export const validityRange = () => ({ min: utcDay(1), max: utcDay(365) });

function validityError(v: string): string | null {
  if (v === '') return null;
  const { min, max } = validityRange();
  return isRealDate(v) && v >= min && v <= max
    ? null
    : `Choose a date between ${min} and ${max}.`;
}

const schema = z.object({
  prices: z.array(
    z.object({
      value: z.string().refine((v) => parsePrice(v) !== undefined, PRICE_ERROR),
    }),
  ),
  valid_until: z.string().refine((v) => validityError(v) === null, {
    message: 'Choose a valid date within the next 365 days, starting tomorrow.',
  }),
});
type Values = z.infer<typeof schema>;

const cell = 'px-3 py-2 text-sm';

export default function PricingForm({ quote }: { quote: QuoteDetail }) {
  const save = useSavePricing(quote.number);
  const [message, setMessage] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      prices: quote.items.map((i) => ({
        value: centsToInput(i.unit_price_cents),
      })),
      valid_until: quote.valid_until ?? '',
    },
  });
  const { fields } = useFieldArray({ control, name: 'prices' });
  const prices = useWatch({ control, name: 'prices' });
  const { min, max } = validityRange();

  const submit = async (v: Values) => {
    setMessage(null);
    setSaved(false);
    try {
      await save.mutateAsync({
        lines: quote.items.map((it, i) => ({
          position: it.position,
          unit_price_cents: parsePrice(v.prices[i]?.value ?? '') ?? null,
        })),
        // Absent = unchanged; null clears a date that was set before.
        ...(v.valid_until
          ? { valid_until: v.valid_until }
          : quote.valid_until
            ? { valid_until: null }
            : {}),
      });
      setSaved(true);
    } catch (e) {
      const rest: string[] = [];
      if (e instanceof ApiError && e.status === 422) {
        for (const [key, msgs] of Object.entries(e.errors)) {
          const m = msgs[0] ?? e.message;
          const line = /^lines\.(\d+)\./.exec(key);
          if (key === 'valid_until') setError('valid_until', { message: m });
          else if (line)
            setError(`prices.${Number(line[1])}.value`, { message: m });
          else rest.push(m);
        }
      } else rest.push(quoteErrorText(e));
      setMessage(rest.length ? rest.join(' ') : null);
    }
  };

  return (
    <section aria-labelledby="pricing-h" className="mt-6">
      <h2 id="pricing-h" className="mb-2 text-lg font-semibold">
        Pricing
      </h2>
      <form onSubmit={(e) => void handleSubmit(submit)(e)} noValidate>
        <table className="w-full border-collapse">
          <caption className="sr-only">Unit prices</caption>
          <thead>
            <tr className="border-b border-slate-400 bg-slate-100">
              {['Item', 'Quantity', 'Unit price', 'Line total (preview)'].map(
                (h) => (
                  <th
                    key={h}
                    scope="col"
                    className={`${cell} text-left font-semibold`}
                  >
                    {h}
                  </th>
                ),
              )}
            </tr>
          </thead>
          <tbody>
            {fields.map((f, i) => {
              const it = quote.items[i];
              const err = errors.prices?.[i]?.value?.message;
              const cents = parsePrice(prices?.[i]?.value ?? '');
              return (
                <tr key={f.id} className="border-b border-slate-300 align-top">
                  <td className={cell}>
                    {it.part_number ?? 'Custom item'}: {it.name}
                  </td>
                  <td className={cell}>{it.quantity}</td>
                  <td className={cell}>
                    <input
                      inputMode="decimal"
                      autoComplete="off"
                      aria-label={`Unit price for item ${it.position}`}
                      aria-invalid={err ? true : undefined}
                      aria-describedby={err ? `price-${f.id}-error` : undefined}
                      className="w-32 rounded border border-slate-500 px-2 py-1"
                      {...register(`prices.${i}.value`)}
                    />
                    {err && (
                      <p
                        id={`price-${f.id}-error`}
                        role="alert"
                        className="mt-1 text-sm text-red-700"
                      >
                        {err}
                      </p>
                    )}
                  </td>
                  <td className={cell}>
                    {typeof cents === 'number'
                      ? formatMoney(cents * it.quantity, quote.currency)
                      : '—'}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        <p className="mt-2 text-sm">
          Saved total: {priceOrAwaiting(quote.total_cents, quote.currency)} (the
          server calculates it when you save).
        </p>
        <div className="my-3 max-w-xs">
          <label
            htmlFor="valid_until"
            className="mb-1 block text-sm font-medium"
          >
            Valid until (last day it can be accepted, UTC)
          </label>
          <input
            id="valid_until"
            type="date"
            min={min}
            max={max}
            aria-invalid={errors.valid_until ? true : undefined}
            aria-describedby={
              errors.valid_until ? 'valid_until-error' : undefined
            }
            className="rounded border border-slate-500 px-2 py-1"
            {...register('valid_until')}
          />
          {errors.valid_until && (
            <p
              id="valid_until-error"
              role="alert"
              className="mt-1 text-sm text-red-700"
            >
              {errors.valid_until.message}
            </p>
          )}
        </div>
        {message && (
          <p role="alert" className="mb-2 text-red-700">
            {message}
          </p>
        )}
        <p role="status" aria-live="polite" className="mb-2 min-h-5 text-sm">
          {saved ? 'Prices saved.' : ''}
        </p>
        <button
          type="submit"
          disabled={save.isPending}
          className="rounded bg-blue-700 px-4 py-2 text-white disabled:opacity-60"
        >
          {save.isPending ? 'Saving...' : 'Save prices'}
        </button>
      </form>
    </section>
  );
}
