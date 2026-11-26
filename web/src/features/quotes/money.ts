import { formatMoney } from '../../lib/format';

const PRICE_RE = /^\d{1,7}(\.\d{1,2})?$/;
export const PRICE_ERROR =
  'Enter a price like 12.50 (at most 7 digits and 2 decimals, no signs).';

/** Strict price text to integer cents. '' is "unpriced" (null); anything else invalid is undefined. */
export function parsePrice(text: string): number | null | undefined {
  const t = text.trim();
  if (t === '') return null;
  if (!PRICE_RE.test(t)) return undefined;
  const [whole, frac = ''] = t.split('.');
  return Number(whole) * 100 + Number(frac.padEnd(2, '0'));
}

export const centsToInput = (cents: number | null): string =>
  cents === null ? '' : (cents / 100).toFixed(2);

export const AWAITING = 'Awaiting pricing';

/** Money or "Awaiting pricing" while the price is hidden (customer draft) or not set. */
export const priceOrAwaiting = (cents: number | null, currency: string) =>
  cents === null ? AWAITING : formatMoney(cents, currency);
