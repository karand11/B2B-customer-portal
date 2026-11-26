import { z } from 'zod';

/**
 * PO reference rules shared by checkout and quotes (same as the API, which stays
 * the authority). `.trim()` is String.prototype.trim, exactly the server's trim
 * set; control and format characters fail the character-class check.
 */
export const poReferenceSchema = z
  .string()
  .trim()
  .max(64, 'Use at most 64 characters.')
  .refine((v) => v === '' || /^[A-Za-z0-9]/.test(v), {
    message: 'Must start with a letter or digit.',
  })
  .refine((v) => /^[A-Za-z0-9 ._/#-]*$/.test(v), {
    message:
      'Only letters, digits, spaces and the characters . - _ / # are allowed.',
  });
