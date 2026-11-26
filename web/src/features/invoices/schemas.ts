import { z } from 'zod';
import { isRealDate } from '../orders/urlState';
import { noteSchema } from '../quotes/schemas';

// Client checks are UX only: the API re-checks everything (dates are plain strings).
export const MAX_DUE_DAYS = 120;
export const MAX_REASON = 500;

/** Optional due date: empty means the default payment terms. */
export const createInvoiceSchema = (today: string, latest: string) =>
  z.object({
    due_date: z
      .string()
      .refine((v) => v === '' || isRealDate(v), 'Enter a valid date.')
      .refine(
        (v) => v === '' || (v >= today && v <= latest),
        `Choose a date from ${today} to ${latest}.`,
      ),
  });
export type CreateInvoiceValues = z.infer<
  ReturnType<typeof createInvoiceSchema>
>;

export const markPaidSchema = (issueDate: string, today: string) =>
  z.object({
    paid_on: z
      .string()
      .refine(isRealDate, 'Enter a valid date.')
      .refine((v) => v <= today, 'The payment date cannot be in the future.')
      .refine(
        (v) => v >= issueDate,
        `The payment date cannot be before the issue date (${issueDate}).`,
      ),
  });
export type MarkPaidValues = z.infer<ReturnType<typeof markPaidSchema>>;

export const voidSchema = z.object({
  reason: z
    .string()
    .trim()
    .min(1, 'Enter a reason.')
    .max(MAX_REASON, `Use at most ${MAX_REASON} characters.`)
    .pipe(noteSchema), // same control/format character rule as other notes
});
export type VoidValues = z.infer<typeof voidSchema>;
