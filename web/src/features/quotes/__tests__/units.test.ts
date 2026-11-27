import { describe, expect, it } from 'vitest';
import { centsToInput, parsePrice } from '../money';
import { checkFiles, lineSchema, quoteFormSchema } from '../schemas';
import { parseQuery, toSearch } from '../urlState';

describe('parsePrice', () => {
  it.each([
    ['12.5', 1250],
    ['12.50', 1250],
    ['12', 1200],
    ['0', 0],
    ['0.05', 5],
    [' 9999999.99 ', 999999999],
    ['', null],
    ['   ', null],
  ])('%j gives %j', (text, cents) => {
    expect(parsePrice(text)).toBe(cents);
  });

  it.each([
    '-1',
    '+1',
    '1e3',
    '10000000',
    '10000000.01',
    'abc',
    '1.234',
    '12,5',
    '.5',
    '1.',
    '1 2',
  ])('%j is invalid', (text) => {
    expect(parsePrice(text)).toBeUndefined();
  });

  it('round-trips cents to input text', () => {
    expect(centsToInput(1250)).toBe('12.50');
    expect(centsToInput(null)).toBe('');
  });
});

describe('checkFiles', () => {
  const file = (name: string, size = 10) => {
    const f = new File(['x'], name);
    Object.defineProperty(f, 'size', { value: size });
    return f;
  };

  it('accepts the allowed extensions case-insensitively', () => {
    const r = checkFiles(
      ['a.PDF', 'b.png', 'c.jpg', 'd.jpeg', 'e.txt', 'f.csv'].map((n) =>
        file(n),
      ),
    );
    expect(r.rejected).toEqual([]);
    expect(r.accepted).toHaveLength(6);
  });

  it.each([
    'a.exe',
    'a.html',
    'a.svg',
    'a.zip',
    'a.docx',
    'noext',
    'a.pdf.exe',
  ])('rejects %s by extension', (name) => {
    expect(checkFiles([file(name)]).rejected[0].reason).toMatch(/Only PDF/);
  });

  it('enforces 10 MiB per file exactly, no empty file, 10 files and 25 MiB in total', () => {
    const MiB = 1024 * 1024;
    expect(checkFiles([file('a.pdf', 10 * MiB)]).accepted).toHaveLength(1);
    expect(checkFiles([file('a.pdf', 10 * MiB + 1)]).rejected).toHaveLength(1);
    expect(checkFiles([file('a.pdf', 0)]).rejected).toHaveLength(1);
    const eleven = Array.from({ length: 11 }, (_, i) => file(`f${i}.pdf`));
    const r = checkFiles(eleven);
    expect(r.accepted).toHaveLength(10);
    expect(r.rejected[0].reason).toMatch(/At most 10 files/);
    const big = checkFiles([
      file('a.pdf', 10 * MiB),
      file('b.pdf', 10 * MiB),
      file('c.pdf', 6 * MiB),
    ]);
    expect(big.accepted).toHaveLength(2);
    expect(big.rejected[0].reason).toMatch(/25 MB/);
    // Files already on the quote count too.
    expect(
      checkFiles([file('a.pdf')], { count: 10, bytes: 0 }).rejected,
    ).toHaveLength(1);
  });
});

describe('form schema', () => {
  const custom = (description: string, quantity = '1') => ({
    kind: 'custom' as const,
    description,
    quantity,
  });
  const ok = (l: ReturnType<typeof custom>) => lineSchema.safeParse(l).success;

  it('validates custom lines and quantities', () => {
    expect(ok(custom('Bearing'))).toBe(true);
    expect(ok(custom('  '))).toBe(false);
    expect(ok(custom('x'.repeat(255)))).toBe(true);
    expect(ok(custom('x'.repeat(256)))).toBe(false);
    expect(ok(custom('a\nb'))).toBe(false);
    expect(ok(custom('a\u0000b'))).toBe(false);
    for (const q of ['0', '10000', '1.5', '', 'x'])
      expect(ok(custom('Bearing', q))).toBe(false);
    for (const q of ['1', '9999', ' 25 '])
      expect(ok(custom('Bearing', q))).toBe(true);
  });

  it('requires 1 to 50 lines and allows newlines in the note but no other control characters', () => {
    const base = { po_reference: '', customer_note: '' };
    expect(quoteFormSchema.safeParse({ ...base, lines: [] }).success).toBe(
      false,
    );
    expect(
      quoteFormSchema.safeParse({ ...base, lines: Array(51).fill(custom('x')) })
        .success,
    ).toBe(false);
    expect(
      quoteFormSchema.safeParse({ ...base, lines: Array(50).fill(custom('x')) })
        .success,
    ).toBe(true);
    const note = (customer_note: string) =>
      quoteFormSchema.safeParse({
        ...base,
        customer_note,
        lines: [custom('x')],
      }).success;
    expect(note('a\nb\r\nc\td')).toBe(true);
    expect(note('a\u001bb')).toBe(false);
    expect(note('a​b')).toBe(false);
  });
});

describe('quote list url state', () => {
  it('drops invalid values and omits defaults', () => {
    const q = parseQuery(
      new URLSearchParams(
        'status=quoted,bogus,quoted&from=2026-02-30&sort=total&page=0',
      ),
    );
    expect(q).toEqual({
      status: ['quoted'],
      from: '',
      to: '',
      q: '',
      company_id: '',
      expiring_within_days: '',
      sort: '-created_at',
      page: 1,
    });
    expect(toSearch(q)).toBe('?status=quoted');
  });
});
