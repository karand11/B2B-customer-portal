import type { BrowserContext, ConsoleMessage } from '@playwright/test';

/**
 * Collects Content-Security-Policy violations of every page of a context: `securitypolicyviolation`
 * DOM events (all frames, through an exposed binding) and console messages that name the policy.
 * The dev server sends no CSP, so this only ever fires against `vite preview` (E2E_WEB=preview).
 * `helpers/fixtures.ts` fails the test when anything was recorded.
 */
export class CspGuard {
  readonly violations: string[] = [];
  /** Violations the test provoked on purpose (see allow()). */
  readonly expected: string[] = [];
  /** Uncaught page errors, failed requests and failed asset loads: attached to a failing test to explain it. */
  readonly log: string[] = [];
  private allowed: RegExp[] = [];
  /** A test that provokes a violation on purpose calls this BEFORE doing so; matching reports go to `expected`. */
  allow(pattern: RegExp) {
    this.allowed.push(pattern);
  }
  report(text: string) {
    if (this.allowed.some((p) => p.test(text))) this.expected.push(text);
    else this.violations.push(text);
  }
}

let current: CspGuard | null = null;
export const setCurrentGuard = (g: CspGuard | null) => {
  current = g;
};

const CONSOLE_CSP = /content security policy|refused to (apply|load|execute|connect|frame|create|evaluate)/i;

/** Attach to a context before its first page is created. A no-op outside a test that uses the fixtures. */
export async function watchCsp(context: BrowserContext): Promise<void> {
  const guard = current;
  if (!guard) return;
  await context.exposeBinding('__e2eCspViolation', (source, report: string) => {
    guard.report(`${report} (page ${source.page?.url() ?? '?'})`);
  });
  await context.addInitScript(() => {
    addEventListener(
      'securitypolicyviolation',
      (e) => {
        const w = window as unknown as { __e2eCspViolation: (r: string) => void };
        w.__e2eCspViolation(`${e.violatedDirective} blocked ${e.blockedURI || 'inline'} at ${e.sourceFile}:${e.lineNumber}`);
      },
      true,
    );
  });
  context.on('weberror', (e) => guard.log.push(`pageerror: ${e.error().message}`));
  context.on('requestfailed', (r) => guard.log.push(`requestfailed: ${r.method()} ${r.url()} ${r.failure()?.errorText ?? ''}`));
  context.on('response', (r) => {
    if (r.status() >= 400) guard.log.push(`HTTP ${r.status()}: ${r.request().method()} ${r.url()}`);
  });
  context.on('console', (m: ConsoleMessage) => {
    if (m.type() === 'error') guard.log.push(`console.error: ${m.text().slice(0, 300)}`);
    if (m.type() === 'error' || m.type() === 'warning') {
      // Evaluating a string is reported by the DOM event above (which knows the file): skip the duplicate.
      if (CONSOLE_CSP.test(m.text()) && !/unsafe-eval/.test(m.text())) guard.report(`console: ${m.text()}`);
    }
  });
}
