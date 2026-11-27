/** ISO timestamp to "2026-11-27 10:15 UTC" by slicing: no Date parsing, so no timezone shift. */
export const fmtUtc = (iso: string): string =>
  /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(iso)
    ? `${iso.slice(0, 10)} ${iso.slice(11, 16)} UTC`
    : iso;

export const fmtDay = (iso: string): string => iso.slice(0, 10);

/** Admin screens format money in USD (fixed en-US until the market is decided). */
export const CURRENCY = 'USD';
