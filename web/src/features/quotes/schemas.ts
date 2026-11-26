import { z } from 'zod';
import { poReferenceSchema } from '../../lib/poReference';
import { QUANTITY_ERROR, parseQuantity } from '../cart/api';

// Client checks are UX only: the API re-checks everything.
export const MAX_LINES = 50;
export const MAX_NOTE = 2000;
export const MAX_DESCRIPTION = 255;
export const MAX_PRODUCT_PARAMS = 20;

const SINGLE_LINE_BAD = /[\p{Cc}\p{Cf}]/u;
// Notes may contain newline, CR and tab but no other control or format character.
const NOTE_BAD = /[^\P{Cc}\n\r\t]|\p{Cf}/u;

export const noteSchema = z
  .string()
  .trim()
  .max(MAX_NOTE, `Use at most ${MAX_NOTE} characters.`)
  .refine((v) => !NOTE_BAD.test(v), 'Remove special characters.');

const quantity = z.string().refine((v) => parseQuantity(v) !== null, {
  message: QUANTITY_ERROR,
});

// One flat shape keeps React Hook Form paths simple; `kind` decides what is used.
export const lineSchema = z
  .object({
    kind: z.enum(['catalog', 'custom']),
    product_id: z.number().int().positive().optional(),
    part_number: z.string().optional(),
    name: z.string().optional(),
    description: z.string(),
    quantity,
  })
  .superRefine((l, ctx) => {
    if (l.kind !== 'custom') return;
    const d = l.description.trim();
    const message =
      d === ''
        ? 'Enter a description.'
        : d.length > MAX_DESCRIPTION
          ? `Use at most ${MAX_DESCRIPTION} characters.`
          : SINGLE_LINE_BAD.test(d)
            ? 'Remove special characters.'
            : null;
    if (message)
      ctx.addIssue({ code: 'custom', path: ['description'], message });
  });

export const quoteFormSchema = z.object({
  po_reference: poReferenceSchema,
  customer_note: noteSchema,
  lines: z
    .array(lineSchema)
    .min(1, 'Add at least one item.')
    .max(MAX_LINES, `You can request at most ${MAX_LINES} items.`),
});
export type QuoteFormValues = z.infer<typeof quoteFormSchema>;
export type LineValues = QuoteFormValues['lines'][number];

// ---- attachments (client pre-check) ----

export const MAX_FILE_BYTES = 10 * 1024 * 1024;
export const MAX_FILES = 10;
export const MAX_TOTAL_BYTES = 25 * 1024 * 1024;
export const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'csv'];
export const ACCEPT_ATTR = ALLOWED_EXTENSIONS.map((e) => `.${e}`).join(',');

export interface FileCheck {
  accepted: File[];
  rejected: { name: string; reason: string }[];
}

/** `have` is what the quote already holds (files in the form: 0). */
export function checkFiles(
  files: File[],
  have = { count: 0, bytes: 0 },
): FileCheck {
  const accepted: File[] = [];
  const rejected: FileCheck['rejected'] = [];
  let count = have.count;
  let bytes = have.bytes;
  for (const f of files) {
    const ext = f.name.includes('.')
      ? (f.name.split('.').pop() ?? '').toLowerCase()
      : '';
    const reason = !ALLOWED_EXTENSIONS.includes(ext)
      ? 'Only PDF, PNG, JPG, TXT and CSV files are allowed.'
      : f.size === 0
        ? 'The file is empty.'
        : f.size > MAX_FILE_BYTES
          ? 'The file is larger than 10 MB.'
          : count >= MAX_FILES
            ? `At most ${MAX_FILES} files per quote.`
            : bytes + f.size > MAX_TOTAL_BYTES
              ? 'The files would exceed 25 MB in total.'
              : null;
    if (reason) rejected.push({ name: f.name, reason });
    else {
      accepted.push(f);
      count += 1;
      bytes += f.size;
    }
  }
  return { accepted, rejected };
}

export const formatBytes = (n: number) =>
  n < 1024
    ? `${n} B`
    : n < 1024 * 1024
      ? `${(n / 1024).toFixed(1)} KB`
      : `${(n / (1024 * 1024)).toFixed(1)} MB`;
