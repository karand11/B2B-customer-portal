import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

type AxeResults = Awaited<ReturnType<AxeBuilder['analyze']>>;
export type Result = AxeResults['violations'][number];

/** WCAG 2.0/2.1/2.2 A and AA plus axe's best-practice rules (docs/phase-7-design.md 7.1). No waivers: every serious or critical finding fails. */
export const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];

export const isBlocking = (v: Result) => v.impact === 'serious' || v.impact === 'critical';

export async function scan(page: Page, include?: string): Promise<AxeResults> {
  const builder = new AxeBuilder({ page }).withTags(AXE_TAGS);
  if (include) builder.include(include);
  return builder.analyze();
}

/** One line per violation, enough to find the element: rule, impact, count and the first target. */
export const describeViolations = (violations: Result[]) =>
  violations
    .map((v) => `${v.id} (${v.impact}) x${v.nodes.length}: ${v.help} -> ${v.nodes[0]?.target.join(' ')}`)
    .join('\n');

/** Journey checkpoint: no serious or critical violation on the page as it is right now. */
export async function axeCheckpoint(page: Page, label: string) {
  const path = new URL(page.url()).pathname;
  const results = await scan(page);
  const blocking = results.violations.filter(isBlocking);
  expect(blocking, `axe at "${label}" (${path}):\n${describeViolations(blocking)}`).toEqual([]);
}
