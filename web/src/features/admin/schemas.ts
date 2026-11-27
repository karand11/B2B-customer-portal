import { z } from 'zod';
import { parsePrice } from '../quotes/money';
import type { ProductInput } from './api';

// Client checks are UX only: the API re-checks every rule and stays the authority.
const SINGLE_LINE_BAD = /[\p{Cc}\p{Cf}]/u;
const MULTI_LINE_BAD = /[^\P{Cc}\n\r\t]|\p{Cf}/u;
const FORMULA_PREFIX = /^[=+\-@\t\r]/;
const PART_NUMBER = /^[A-Za-z0-9][A-Za-z0-9 ._/-]*$/;
const TEXT = /^[\p{L}\p{N}][\p{L}\p{N} .&/+-]*$/u;
const TAX_ID = /^[A-Za-z0-9][A-Za-z0-9 ./-]*$/;

export const SEAL_TYPES = ['open', '2rs', 'zz'] as const;
export const STOCK_STATUSES = [
  'in_stock',
  'low_stock',
  'out_of_stock',
  'on_order',
] as const;

const line = (label: string, max: number) =>
  z
    .string()
    .trim()
    .min(1, `Enter ${label}.`)
    .max(max, `Use at most ${max} characters.`)
    .refine((v) => !SINGLE_LINE_BAD.test(v), 'Remove special characters.');

const mm = z
  .string()
  .trim()
  .refine(
    (v) =>
      /^\d{1,4}(\.\d{1,2})?$/.test(v) && Number(v) > 0 && Number(v) <= 5000,
    'Enter a size above 0 and at most 5000 mm, with up to 2 decimals.',
  );

export const productSchema = z
  .object({
    category_id: z.string().min(1, 'Choose a category.'),
    part_number: z
      .string()
      .trim()
      .min(1, 'Enter a part number.')
      .max(64, 'Use at most 64 characters.')
      .regex(
        PART_NUMBER,
        'Use letters, digits, spaces and . _ / - (start with a letter or digit).',
      ),
    name: line('a name', 255).refine(
      (v) => !FORMULA_PREFIX.test(v),
      'The name must not start with = + - @.',
    ),
    description: z
      .string()
      .max(5000, 'Use at most 5000 characters.')
      .refine((v) => !MULTI_LINE_BAD.test(v), 'Remove special characters.')
      .refine(
        (v) => !FORMULA_PREFIX.test(v.trim()),
        'The description must not start with = + - @.',
      ),
    brand: line('a brand', 64).regex(
      TEXT,
      'Use letters, digits and . & / + - only.',
    ),
    material: line('a material', 64).regex(
      TEXT,
      'Use letters, digits and . & / + - only.',
    ),
    inner_diameter_mm: mm,
    outer_diameter_mm: mm,
    width_mm: mm,
    seal_type: z.enum(SEAL_TYPES),
    stock_status: z.enum(STOCK_STATUSES),
    price_on_request: z.boolean(),
    price: z.string().optional(),
  })
  .superRefine((v, ctx) => {
    if (
      Number(v.outer_diameter_mm) <= Number(v.inner_diameter_mm) &&
      !Number.isNaN(Number(v.outer_diameter_mm))
    )
      ctx.addIssue({
        code: 'custom',
        path: ['outer_diameter_mm'],
        message: 'The outer diameter must be greater than the inner diameter.',
      });
    if (v.price_on_request) return;
    const cents = parsePrice(v.price ?? '');
    if (cents === null || cents === undefined)
      ctx.addIssue({
        code: 'custom',
        path: ['price'],
        message:
          'Enter a price like 12.50 (at most 7 digits and 2 decimals), or tick Price on request.',
      });
  });
export type ProductValues = z.infer<typeof productSchema>;

/** Form text to the documented request body. Prices become integer cents. */
export function toProductInput(v: ProductValues): ProductInput {
  const description = v.description.replace(/\r\n/g, '\n').trim();
  return {
    category_id: Number(v.category_id),
    part_number: v.part_number.trim().toUpperCase(),
    name: v.name.trim(),
    description: description === '' ? null : description,
    brand: v.brand.trim(),
    material: v.material.trim(),
    inner_diameter_mm: Number(v.inner_diameter_mm),
    outer_diameter_mm: Number(v.outer_diameter_mm),
    width_mm: Number(v.width_mm),
    seal_type: v.seal_type,
    stock_status: v.stock_status,
    price_on_request: v.price_on_request,
    price_cents: v.price_on_request
      ? null
      : (parsePrice(v.price ?? '') ?? null),
  };
}

// ---- companies
export const MAX_BILLING_LINES = 6;

/** CRLF to LF, trimmed lines, blanks dropped: the same normalisation the API applies. */
export const billingLines = (text: string): string[] =>
  text
    .replace(/\r\n/g, '\n')
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l !== '');

export const companySchema = z.object({
  name: z
    .string()
    .trim()
    .min(1, 'Enter a name.')
    .max(255, 'Use at most 255 characters.'),
  billing_address: z.string().superRefine((v, ctx) => {
    const lines = billingLines(v);
    const message =
      lines.length > MAX_BILLING_LINES
        ? `Use at most ${MAX_BILLING_LINES} lines.`
        : lines.some((l) => l.length > 80)
          ? 'Each line may have at most 80 characters.'
          : lines.join('\n').length > 500
            ? 'Use at most 500 characters in total.'
            : MULTI_LINE_BAD.test(v)
              ? 'Remove special characters.'
              : null;
    if (message) ctx.addIssue({ code: 'custom', message });
  }),
  tax_id: z
    .string()
    .trim()
    .max(32, 'Use at most 32 characters.')
    .refine(
      (v) => v === '' || TAX_ID.test(v),
      'Use letters, digits, spaces and . / - only.',
    ),
});
export type CompanyValues = z.infer<typeof companySchema>;

export const companyBody = (v: CompanyValues) => ({
  name: v.name.trim(),
  billing_address: billingLines(v.billing_address).join('\n') || null,
  tax_id: v.tax_id.trim() || null,
});

// ---- users
const inviteBase = z.object({
  name: line('a name', 255),
  email: z
    .string()
    .trim()
    .min(1, 'Enter an email address.')
    .max(255, 'Use at most 255 characters.')
    .email('Enter a valid email address.'),
  role: z.enum(['customer', 'staff', 'admin']),
  company_id: z.string(),
});
// Customers need a company; staff and admin never have one (the API enforces both).
const needsCompany = (
  v: { role: string; company_id: string },
  ctx: z.RefinementCtx,
) => {
  if (v.role === 'customer' && v.company_id === '')
    ctx.addIssue({
      code: 'custom',
      path: ['company_id'],
      message: 'Choose the customer company.',
    });
};
export const inviteSchema = inviteBase.superRefine(needsCompany);
export type InviteValues = z.infer<typeof inviteSchema>;

export const assignSchema = inviteBase
  .pick({ role: true, company_id: true })
  .superRefine(needsCompany);
export type AssignValues = z.infer<typeof assignSchema>;
