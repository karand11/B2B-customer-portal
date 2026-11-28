import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const css = readFileSync(resolve(__dirname, '../index.css'), 'utf8');

describe('base styles (accessibility)', () => {
  it('draws a visible focus ring on every control', () => {
    expect(css).toMatch(/:focus-visible\s*{[^}]*outline:\s*2px solid/);
  });

  it('never removes the outline of a control globally', () => {
    expect(css).not.toMatch(/outline:\s*(none|0)\b/);
  });

  it('gives buttons, selects, checkboxes and radios a target of at least 24px', () => {
    expect(css).toMatch(/button[^{]*\{[^}]*min-height:\s*1\.5rem/);
    expect(css).toMatch(
      /checkbox[^{]*\{[^}]*width:\s*1\.5rem;[^}]*height:\s*1\.5rem/,
    );
  });

  it('respects prefers-reduced-motion', () => {
    expect(css).toMatch(/@media \(prefers-reduced-motion: reduce\)/);
    expect(css).toMatch(/animation-duration:\s*0\.01ms/);
    expect(css).toMatch(/transition-duration:\s*0\.01ms/);
  });
});

describe('animations respect reduced motion', () => {
  it('every animate-* class has motion-reduce:animate-none in the same class string', async () => {
    const { readdirSync, statSync } = await import('node:fs');
    const { join } = await import('node:path');
    const walk = (dir: string): string[] =>
      readdirSync(dir).flatMap((n) => {
        const p = join(dir, n);
        if (statSync(p).isDirectory()) return walk(p);
        return /\.tsx$/.test(n) && !/\.test\.tsx$/.test(n) ? [p] : [];
      });
    let found = 0;
    for (const file of walk(resolve(__dirname, '..'))) {
      const text = readFileSync(file, 'utf8');
      for (const m of text.matchAll(/(["'`])((?:\\.|(?!\1)[^\\])*)\1/g)) {
        if (!/(^|\s)animate-(?!none)/.test(m[2])) continue;
        found++;
        expect(m[2], file).toMatch(/(^|\s)motion-reduce:animate-none(\s|$)/);
      }
    }
    expect(found).toBeGreaterThan(10);
  });
});
