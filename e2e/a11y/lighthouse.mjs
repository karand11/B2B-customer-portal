#!/usr/bin/env node
/**
 * Lighthouse accessibility gate (docs/PLAN.md Phase 7: "Lighthouse accessibility 90+ on login, catalog, order history").
 *
 *   npm run lighthouse                 starts the isolated e2e stack (API 8100, `vite build` + `vite preview` on 5273, database
 *                                      portal_e2e, reseeded) through Playwright, then runs the audit below against it
 *   LH_BASE_URL=http://localhost:5273 node a11y/lighthouse.mjs --inner
 *                                      only the audit, against a stack that is already running (the CI step after Playwright
 *                                      could also use this; the demo accounts must exist)
 *
 * The audit: category "accessibility" only, desktop and mobile emulation, for /login (guest), /catalog and /orders (customer
 * alice@northwind.test, signed in through the real login API; the session cookies are put into the browser before the first
 * signed-in page and Lighthouse runs with disableStorageReset so it keeps them). Every page and form factor must score >= 0.90 (LH_MIN_SCORE); the JSON reports go
 * to e2e/a11y-reports/lighthouse/; the exit code is non-zero when any score is lower or a run failed.
 *
 * Chrome: LH_CHROME (path) > Playwright's Chromium > an installed Chrome or Edge. Port for the DevTools protocol: LH_PORT (9223).
 */
import { spawn, spawnSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const E2E_DIR = path.resolve(HERE, '..');
const REPORT_DIR = path.join(E2E_DIR, 'a11y-reports', 'lighthouse');
const MIN_SCORE = Number(process.env.LH_MIN_SCORE ?? '0.9');
const PORT = Number(process.env.LH_PORT ?? '9223');
const BASE = (process.env.LH_BASE_URL ?? 'http://localhost:5273').replace(/\/$/, '');
const EMAIL = 'alice@northwind.test';
const PASSWORD = 'Demo-Passw0rd!'; // published demo credential of the local/testing seeders

// The script logs in with a published demo password: it must never be pointed at a real system by accident.
if (!['localhost', '127.0.0.1', '::1', '[::1]'].includes(new URL(BASE).hostname) && process.env.LH_ALLOW_REMOTE !== '1') {
  console.error(`Refusing to run Lighthouse against ${BASE}: not a local address (it signs in with the published demo password). Set LH_ALLOW_REMOTE=1 only for a disposable environment you own.`);
  process.exit(2);
}

const PAGES = [
  { name: 'login', path: '/login', auth: false },
  { name: 'catalog', path: '/catalog', auth: true },
  { name: 'orders', path: '/orders', auth: true },
];
const FORM_FACTORS = ['desktop', 'mobile'];

// ---------------------------------------------------------------------------------------------------------------- driver
if (!process.argv.includes('--inner')) {
  // No flags needed on the user's side: the Playwright config starts the servers and reseeds, the spec calls this file with --inner.
  const result = spawnSync('npx playwright test a11y/lighthouse.spec.ts --reporter=list', {
    cwd: E2E_DIR,
    stdio: 'inherit',
    shell: true,
    env: { ...process.env, E2E_WEB: 'preview', LH_DRIVER: '1' },
  });
  process.exit(result.status ?? 1);
}

// ---------------------------------------------------------------------------------------------------------------- audit
const { default: lighthouse } = await import('lighthouse');
const { default: desktopConfig } = await import('lighthouse/core/config/desktop-config.js');

async function chromePath() {
  const candidates = [];
  if (process.env.LH_CHROME) candidates.push(process.env.LH_CHROME);
  try {
    const { chromium } = await import('@playwright/test');
    candidates.push(chromium.executablePath());
  } catch {
    /* Playwright not installed: fall through to the system browsers */
  }
  candidates.push(
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
  );
  const found = candidates.find((c) => c && fs.existsSync(c));
  if (!found) throw new Error(`no Chrome found; tried:\n${candidates.join('\n')}`);
  return found;
}

/** Real SPA login over the API; returns the session cookies (name -> raw value) for the browser. */
async function sessionCookie() {
  const jar = new Map();
  const absorb = (res) => {
    for (const line of res.headers.getSetCookie()) {
      const [pair] = line.split(';');
      const i = pair.indexOf('=');
      jar.set(pair.slice(0, i), pair.slice(i + 1));
    }
  };
  const cookie = () => [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
  const csrf = await fetch(`${BASE}/sanctum/csrf-cookie`, { headers: { Origin: BASE } });
  absorb(csrf);
  const res = await fetch(`${BASE}/api/v1/auth/login`, {
    method: 'POST',
    headers: {
      Origin: BASE,
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': decodeURIComponent(jar.get('XSRF-TOKEN') ?? ''),
      Cookie: cookie(),
    },
    body: JSON.stringify({ email: EMAIL, password: PASSWORD }),
  });
  if (res.status !== 200) throw new Error(`login as ${EMAIL} failed: ${res.status} ${await res.text()}`);
  absorb(res);
  return jar;
}

/** Puts the cookies into Chrome's default browser context, the one Lighthouse audits in. */
async function putCookiesInBrowser(jar) {
  const { chromium } = await import('@playwright/test');
  const browser = await chromium.connectOverCDP(`http://127.0.0.1:${PORT}`);
  try {
    await browser.contexts()[0].addCookies([...jar].map(([name, value]) => ({ name, value, url: BASE })));
  } finally {
    await browser.close(); // disconnects; the Chrome process keeps running
  }
}

async function waitForDevtools(port) {
  for (let i = 0; i < 100; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${port}/json/version`);
      if (r.ok) return;
    } catch {
      /* not up yet */
    }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`Chrome did not open the DevTools port ${port}`);
}

const killTree = (child) => {
  if (!child?.pid) return;
  if (process.platform === 'win32') spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
  else child.kill('SIGKILL');
};

fs.rmSync(REPORT_DIR, { recursive: true, force: true });
fs.mkdirSync(REPORT_DIR, { recursive: true });

const exe = await chromePath();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'lh-profile-'));
console.log(`Lighthouse ${(await import('lighthouse/package.json', { with: { type: 'json' } })).default.version} against ${BASE}`);
console.log(`Chrome: ${exe}`);
const chrome = spawn(
  exe,
  [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${profile}`,
    '--headless=new',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-gpu',
    '--disable-extensions',
    // Playwright launches Chromium without its sandbox by default; Ubuntu 24.04 runners need that too (AppArmor limits user namespaces).
    ...(process.env.CI || process.env.LH_NO_SANDBOX ? ['--no-sandbox'] : []),
    'about:blank',
  ],
  { stdio: 'ignore' },
);

const rows = [];
let failed = false;
try {
  await waitForDevtools(PORT);
  const jar = await sessionCookie();
  let signedIn = false;
  for (const page of PAGES) {
    // The login page is audited first, as a guest: a signed-in browser would be redirected away from it.
    if (page.auth && !signedIn) {
      await putCookiesInBrowser(jar);
      signedIn = true;
    }
    for (const formFactor of FORM_FACTORS) {
      const url = `${BASE}${page.path}`;
      const flags = {
        port: PORT,
        output: 'json',
        logLevel: 'error',
        onlyCategories: ['accessibility'],
        formFactor,
        screenEmulation:
          formFactor === 'mobile'
            ? { mobile: true, width: 412, height: 823, deviceScaleFactor: 1.75, disabled: false }
            : { mobile: false, width: 1350, height: 940, deviceScaleFactor: 1, disabled: false },
        throttlingMethod: 'provided', // accessibility needs no network or CPU simulation
        pauseAfterLoadMs: 2000, // the SPA fetches its data after load
        disableStorageReset: true, // keep the session cookies
      };
      try {
        const run = await lighthouse(url, flags, formFactor === 'desktop' ? desktopConfig : undefined);
        const lhr = run.lhr;
        if (lhr.runtimeError) throw new Error(`${lhr.runtimeError.code}: ${lhr.runtimeError.message}`);
        const score = lhr.categories.accessibility.score;
        fs.writeFileSync(path.join(REPORT_DIR, `${page.name}-${formFactor}.json`), JSON.stringify(lhr, null, 2));
        const failing = Object.values(lhr.audits)
          .filter((a) => a.score !== null && a.score < 1 && a.scoreDisplayMode === 'binary')
          .map((a) => `${a.id}${a.details?.items ? ` x${a.details.items.length}` : ''}`);
        // A signed-in page that redirected to /login would be audited as the login page: catch it.
        const finalUrl = new URL(lhr.finalDisplayedUrl ?? lhr.finalUrl);
        const wrongPage = finalUrl.pathname !== page.path;
        if (wrongPage) failed = true;
        if (score === null || score < MIN_SCORE) failed = true;
        rows.push({ page: page.name, formFactor, score, failing: wrongPage ? [`redirected to ${finalUrl.pathname}`, ...failing] : failing });
      } catch (e) {
        failed = true;
        rows.push({ page: page.name, formFactor, score: null, failing: [`run failed: ${e.message}`] });
      }
    }
  }
} finally {
  killTree(chrome);
  fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
}

console.log(`\nLighthouse accessibility (minimum ${MIN_SCORE})`);
console.log('page      form factor  score  result  failing audits');
for (const r of rows) {
  const ok = r.score !== null && r.score >= MIN_SCORE;
  console.log(`${r.page.padEnd(9)} ${r.formFactor.padEnd(11)}  ${(r.score === null ? 'n/a' : r.score.toFixed(2)).padEnd(5)}  ${ok ? 'PASS  ' : 'FAIL  '}  ${r.failing.join(', ') || '-'}`);
}
fs.writeFileSync(path.join(REPORT_DIR, 'summary.json'), JSON.stringify({ generated: new Date().toISOString(), minScore: MIN_SCORE, base: BASE, chrome: exe, rows }, null, 2));
process.exit(failed ? 1 : 0);
