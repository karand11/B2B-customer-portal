import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

/**
 * WCAG AA contrast (4.5:1 for normal text) over the Tailwind palette tokens the app
 * actually uses. Colours come from Tailwind's own theme.css, so a palette change is caught.
 * Limit: text and background are matched when they appear in the same class string;
 * anything else is assumed to sit on white.
 */
const root = resolve(__dirname, '../..');
const theme = readFileSync(
  resolve(root, 'node_modules/tailwindcss/theme.css'),
  'utf8',
);

type RGB = [number, number, number]; // sRGB 0..1

function oklchToSrgb(l: number, c: number, hDeg: number): RGB {
  const h = (hDeg * Math.PI) / 180;
  const a = c * Math.cos(h);
  const b = c * Math.sin(h);
  const l_ = (l + 0.3963377774 * a + 0.2158037573 * b) ** 3;
  const m_ = (l - 0.1055613458 * a - 0.0638541728 * b) ** 3;
  const s_ = (l - 0.0894841775 * a - 1.291485548 * b) ** 3;
  const lin: RGB = [
    4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_,
    -1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_,
    -0.0041960863 * l_ - 0.7034186147 * m_ + 1.707614701 * s_,
  ];
  return lin.map((v) => {
    const x = Math.min(1, Math.max(0, v));
    return x <= 0.0031308 ? 12.92 * x : 1.055 * x ** (1 / 2.4) - 0.055;
  }) as RGB;
}

function token(name: string): RGB {
  if (name === 'white') return [1, 1, 1];
  if (name === 'black') return [0, 0, 0];
  const m = theme.match(new RegExp(`--color-${name}: ([^;]+);`));
  if (!m) throw new Error(`unknown colour ${name}`);
  const ok = m[1].match(/oklch\(([\d.]+)% ([\d.]+) ([\d.]+)\)/);
  if (!ok) throw new Error(`unsupported colour format ${m[1]}`);
  return oklchToSrgb(+ok[1] / 100, +ok[2], +ok[3]);
}

const lum = ([r, g, b]: RGB) => {
  const f = (v: number) =>
    v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
};
export function contrast(a: RGB, b: RGB): number {
  const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}
const blend = (fg: RGB, bg: RGB, alpha: number): RGB =>
  fg.map((v, i) => v * alpha + bg[i] * (1 - alpha)) as RGB;

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((n) => {
    const p = join(dir, n);
    if (statSync(p).isDirectory())
      return n === '__tests__' ? [] : sourceFiles(p);
    return /\.tsx$/.test(n) && !/\.test\.tsx$/.test(n) ? [p] : [];
  });
}

const COLOUR =
  '(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\\d{2,3}|white|black';
const TEXT = new RegExp(`^text-(${COLOUR})$`);
const BG = new RegExp(`^bg-(${COLOUR})$`);

interface Pair {
  fg: string;
  bg: string;
  where: string;
}
const pairs: Pair[] = [];
const opacities = new Set<number>();
for (const file of sourceFiles(resolve(root, 'src'))) {
  const text = readFileSync(file, 'utf8');
  for (const m of text.matchAll(/(["'`])((?:\\.|(?!\1)[^\\])*)\1/g)) {
    const tokens = m[2].split(/\s+/);
    const fgs = tokens
      .map((t) => t.match(TEXT)?.[1])
      .filter(Boolean) as string[];
    if (fgs.length === 0) continue;
    const bg = tokens.map((t) => t.match(BG)?.[1]).find(Boolean) ?? 'white';
    // White text sits on a button colour set in another string (see the test below).
    for (const fg of fgs)
      if (fg !== 'white' || bg !== 'white')
        pairs.push({ fg, bg, where: file.replace(root, '') });
  }
  // Unprefixed opacity classes dim text (variants like disabled: are inactive controls: exempt).
  for (const m of text.matchAll(/(?<![:\w-])opacity-(\d+)(?![\w-])/g))
    opacities.add(+m[1]);
}
const unique = [...new Map(pairs.map((p) => [`${p.fg}|${p.bg}`, p])).values()];

describe('colour contrast (WCAG AA, 4.5:1)', () => {
  it('measures sensibly (known values)', () => {
    expect(contrast(token('black'), token('white'))).toBeCloseTo(21, 0);
    expect(contrast(token('slate-500'), token('white'))).toBeGreaterThan(4.5);
    expect(contrast(token('slate-300'), token('white'))).toBeLessThan(3);
    expect(unique.length).toBeGreaterThan(15); // the scan found the app's colours
  });

  it.each(unique.map((p) => [`${p.fg} on ${p.bg}`, p] as const))(
    '%s',
    (_name, p) => {
      const ratio = contrast(token(p.fg), token(p.bg));
      expect(ratio, `${p.fg} on ${p.bg} in ${p.where}`).toBeGreaterThanOrEqual(
        4.5,
      );
    },
  );

  it.each(['blue-700', 'blue-800', 'red-700'])(
    'white button text on %s',
    (bg) => {
      expect(contrast(token('white'), token(bg))).toBeGreaterThanOrEqual(4.5);
    },
  );

  it('the loading dim (opacity on busy lists) keeps every text colour above AA', () => {
    expect(opacities.size).toBeGreaterThan(0);
    const worst = Math.min(...opacities) / 100;
    const all = [
      ...unique,
      { fg: 'black', bg: 'white', where: 'default text' },
    ];
    for (const p of all) {
      const bg = token(p.bg);
      const ratio = contrast(blend(token(p.fg), bg, worst), bg);
      expect(
        ratio,
        `${p.fg} on ${p.bg} at ${worst * 100}% opacity (${p.where})`,
      ).toBeGreaterThanOrEqual(4.5);
    }
  });
});
