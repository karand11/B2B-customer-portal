import { afterEach, vi } from 'vitest';

afterEach(() => vi.restoreAllMocks());

describe('zod runs without eval (strict CSP)', () => {
  it('applies jitless and never calls Function while building and parsing form schemas', async () => {
    vi.resetModules();
    const probe = vi.spyOn(globalThis, 'Function');
    await import('./zodConfig');
    const { z } = await import('zod');
    expect(z.config().jitless).toBe(true);
    // Representative form schemas, created AFTER the config like the app does.
    const { poReferenceSchema } = await import('./lib/poReference');
    const schema = z.object({
      po_reference: poReferenceSchema,
      email: z.email(),
      lines: z.array(z.object({ qty: z.coerce.number().int().min(1) })),
    });
    expect(
      schema.safeParse({
        po_reference: 'PO-1',
        email: 'a@b.test',
        lines: [{ qty: '2' }],
      }).success,
    ).toBe(true);
    expect(
      schema.safeParse({ po_reference: '', email: 'x', lines: [] }).success,
    ).toBe(false);
    expect(probe).not.toHaveBeenCalled();
  });

  it('main.tsx loads the config before anything else', async () => {
    const { readFileSync } = await import('node:fs');
    const src = readFileSync(`${__dirname}/main.tsx`, 'utf8');
    const imports = [...src.matchAll(/^import .*$/gm)].map((m) => m[0]);
    expect(imports[0]).toBe("import './zodConfig';");
  });
});
