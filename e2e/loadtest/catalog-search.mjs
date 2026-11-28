#!/usr/bin/env node
// Catalog-search load check (docs/phase-7-design.md section 6.3). Node 24 built-in fetch, no dependencies.
//   node e2e/loadtest/catalog-search.mjs [--pdf-users=3] [--baseline=<pure results.json>]
//   node e2e/loadtest/catalog-search.mjs --self-test      (stub server, no rig needed)
// Env: LOADTEST_BASE_URL (http://localhost:8200), LOADTEST_USERS (50), LOADTEST_PASSWORD, LOADTEST_DURATION (120 s),
//      LOADTEST_P95_MS (500). Exit code 1 when the p95 of the catalog-search group is >= LOADTEST_P95_MS, any request
//      failed, any unintended 429 happened, or the burst probe did not see the first 429 at request 41.
// Never weakens a limiter: users pace themselves (think time 1.5 to 2.5 s, about 30 requests a minute each).
// shortcut: Math.random (not seeded), so runs are not byte-reproducible; add a seeded PRNG if run-to-run mix noise matters.
import assert from 'node:assert/strict';
import http from 'node:http';
import { mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import os from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const FRAGMENTS = [
  '6205', '6205-2RS', '62', '6', '2rs', '32208', 'NU', '16005', '6305', '6305-ZZ',
  '63', '30', '7', '22', 'NJ', '6004', '6204-2RS', '51105', '1', 'zz',
  '32', 'NA', '6001', '22210', '3', '6206', 'HK', 'K', '29', '60',
];

// ---------- statistics and acceptance (unit tested by --self-test) ----------

/** Nearest-rank percentile of an ascending array. */
export function percentile(sorted, p) {
  if (sorted.length === 0) return NaN;
  return sorted[Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1))];
}

export function summarize(samples, seconds) {
  const ms = samples.map((s) => s.ms).sort((a, b) => a - b);
  return {
    n: ms.length,
    rps: seconds > 0 ? ms.length / seconds : 0,
    p50: percentile(ms, 50), p90: percentile(ms, 90), p95: percentile(ms, 95), p99: percentile(ms, 99),
    max: ms.length ? ms[ms.length - 1] : NaN,
  };
}

export function evaluate({ search, errors, unintended429, probe }, { p95Ms }) {
  const reasons = [];
  if (!(search.n > 0)) reasons.push('no catalog-search samples');
  else if (!(search.p95 < p95Ms)) reasons.push(`catalog-search p95 ${search.p95.toFixed(0)} ms >= ${p95Ms} ms`);
  if (errors > 0) reasons.push(`${errors} failed request(s)`);
  if (unintended429 > 0) reasons.push(`${unintended429} unintended 429`);
  if (!probe?.ok) reasons.push(`burst probe: ${probe?.detail ?? 'not run'}`);
  return { pass: reasons.length === 0, reasons };
}

// ---------- virtual users ----------

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const rnd = (a, b) => a + Math.random() * (b - a);
const int = (a, b) => Math.floor(rnd(a, b + 1));
const pick = (list) => list[Math.floor(Math.random() * list.length)];

class User {
  constructor(cfg, n, email) {
    this.cfg = cfg;
    this.email = email;
    this.ip = `203.0.113.${n}`;
    this.jar = new Map();
  }

  async call(method, path, body) {
    const headers = { Accept: 'application/json', Origin: this.cfg.origin, 'X-Forwarded-For': this.ip };
    if (this.jar.size) headers.Cookie = [...this.jar].map(([k, v]) => `${k}=${v}`).join('; ');
    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      const xsrf = this.jar.get('XSRF-TOKEN');
      if (xsrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf);
    }
    const t0 = performance.now();
    const res = await fetch(this.cfg.baseUrl + path, { method, headers, body: body && JSON.stringify(body), redirect: 'manual' });
    const buf = Buffer.from(await res.arrayBuffer());
    const ms = performance.now() - t0;
    for (const c of res.headers.getSetCookie()) {
      const [pair, ...attrs] = c.split(';');
      const eq = pair.indexOf('=');
      const name = pair.slice(0, eq).trim();
      const value = pair.slice(eq + 1).trim();
      if (value === '' || attrs.some((a) => /^\s*max-age=0\s*$/i.test(a))) this.jar.delete(name);
      else this.jar.set(name, value);
    }
    return { status: res.status, ms, buf, headers: res.headers, t0 };
  }

  async login() {
    const csrf = await this.call('GET', '/sanctum/csrf-cookie');
    if (csrf.status !== 204 && csrf.status !== 200) throw new Error(`csrf-cookie ${csrf.status}`);
    const r = await this.call('POST', '/api/v1/auth/login', { email: this.email, password: this.cfg.password });
    if (r.status !== 200) throw new Error(`login ${this.email} -> ${r.status} ${r.buf.toString().slice(0, 120)}`);
  }
}

/** Returns the JSON body when it is a valid list/object response, otherwise throws. */
function checkBody(r, kind) {
  if (r.status !== 200) throw new Error(`HTTP ${r.status}`);
  let json;
  try { json = JSON.parse(r.buf.toString()); } catch { throw new Error('body is not JSON'); }
  if (kind === 'list') {
    if (!Array.isArray(json.data)) throw new Error('data is not an array');
    if (!Number.isInteger(json.meta?.total)) throw new Error('meta.total is not an integer');
  } else if (kind === 'array') {
    if (!Array.isArray(json.data)) throw new Error('data is not an array');
  } else if (json.data === null || typeof json.data !== 'object') throw new Error('data missing');
  return json;
}

function buildMix(ctx) {
  const list = (params) => `/api/v1/products?${new URLSearchParams({ ...params, per_page: '24' })}`;
  return [
    [0.35, 'search q', () => ({ path: list({ q: pick(FRAGMENTS) }), kind: 'list', group: 'search' })],
    [0.25, 'search facets', () => ({
      path: list({ category: pick(ctx.categories), brand: pick(ctx.brands), seal_type: pick(ctx.seals), sort: '-price', page: String(int(1, 5)) }),
      kind: 'list', group: 'search' })],
    [0.15, 'search ranges', () => {
      const r = ctx.ranges;
      const lo = int(r.inner_diameter_mm.min, Math.floor((r.inner_diameter_mm.min + r.inner_diameter_mm.max) / 2));
      return { path: list({
        inner_diameter_min: String(lo), inner_diameter_max: String(lo + int(10, 40)),
        outer_diameter_max: String(int(Math.ceil((r.outer_diameter_mm.min + r.outer_diameter_mm.max) / 2), r.outer_diameter_mm.max)),
        page: String(int(1, 10)) }), kind: 'list', group: 'search' };
    }],
    [0.10, 'search q page', () => ({ path: list({ q: pick(FRAGMENTS), page: String(int(2, 8)) }), kind: 'list', group: 'search' })],
    [0.05, 'filters', () => ({ path: '/api/v1/products/filters', kind: 'object', group: 'other' })],
    [0.05, 'detail', () => ({ path: `/api/v1/products/${pick(ctx.productIds)}`, kind: 'object', group: 'other' })],
    [0.05, 'categories', () => ({ path: '/api/v1/categories', kind: 'array', group: 'other' })],
  ];
}

function nextRequest(mix) {
  let x = Math.random();
  for (const [w, , make] of mix) { if ((x -= w) < 0) return make(); }
  return mix[0][2]();
}

// ---------- the run ----------

export async function run(cfg, log = console.log) {
  const email = cfg.email ?? ((i) => `load${String(i).padStart(3, '0')}@load.test`);
  const samples = []; // {group, name, ms, t, ok}
  const failures = []; // {name, detail, measured}
  let unintended429 = 0;
  const record = (group, name, r, measured, err) => {
    if (err) failures.push({ name, detail: err, measured });
    if (r.status === 429) unintended429++;
    if (!err && measured) samples.push({ group, name, ms: r.ms });
  };

  // Shared discovery (categories, brands, ranges) through user 1.
  const probeUser = new User(cfg, 200, email(cfg.probeIndex ?? cfg.users + 1));
  const first = new User(cfg, 1, email(1));
  await first.login();
  const cat = checkBody(await first.call('GET', '/api/v1/categories'), 'array');
  const fil = checkBody(await first.call('GET', '/api/v1/products/filters'), 'object');
  const ctx = {
    categories: cat.data.map((c) => c.slug),
    brands: fil.data.brands,
    seals: fil.data.seal_types,
    ranges: fil.data.ranges,
    productIds: [1],
  };
  const firstList = checkBody(await first.call('GET', '/api/v1/products?per_page=24'), 'array'); // ids only: validation of meta.total is part of the measured mix
  ctx.productIds = firstList.data.map((p) => p.id);
  const mix = buildMix(ctx);

  const start = performance.now();
  const measureStart = start + (cfg.ramp + cfg.warmup) * 1000;
  const measureEnd = measureStart + cfg.duration * 1000;

  async function virtualUser(i) {
    const u = i === 1 ? first : new User(cfg, i, email(i));
    await sleep((i - 1) * (cfg.ramp * 1000) / cfg.users);
    if (i !== 1) await u.login();
    const pdf = i > 1 && i <= 1 + cfg.pdfUsers ? { next: 0, id: null } : null; // load002.. own invoices in the seeded data
    while (performance.now() < measureEnd) {
      if (pdf && performance.now() >= pdf.next) {
        pdf.next = performance.now() + (cfg.pdfEveryMs ?? 30_000);
        await oneRequest(u, pdf);
      } else {
        await oneRequest(u, null);
      }
      await sleep(rnd(cfg.thinkMin, cfg.thinkMax));
    }
  }

  async function oneRequest(u, pdf) {
    let spec;
    if (pdf) {
      if (!pdf.id) {
        const l = await u.call('GET', '/api/v1/invoices?per_page=5');
        try { pdf.id = checkBody(l, 'list').data[0].id; } catch (e) { failures.push({ name: 'invoice list', detail: e.message, measured: true }); return; }
      }
      spec = { path: `/api/v1/invoices/${pdf.id}/pdf`, kind: 'pdf', group: 'pdf', name: 'invoice pdf' };
    } else {
      spec = nextRequest(mix);
    }
    const name = spec.name ?? spec.path.replace(/\?.*$/, '').replace(/\/\d+/, '/{id}');
    let r;
    try { r = await u.call('GET', spec.path); } catch (e) {
      failures.push({ name, detail: `network: ${e.message}`, measured: r?.t0 >= measureStart });
      return;
    }
    const measured = r.t0 >= measureStart && r.t0 < measureEnd;
    let err = null;
    try {
      if (spec.kind === 'pdf') {
        if (r.status !== 200 || r.buf.subarray(0, 4).toString() !== '%PDF') throw new Error(`HTTP ${r.status}, not a PDF`);
      } else {
        const j = checkBody(r, spec.kind);
        if (spec.kind === 'list' && spec.group === 'search') for (const p of j.data) if (ctx.productIds.length < 200) ctx.productIds.push(p.id);
      }
    } catch (e) { err = e.message; }
    record(spec.group, name, r, measured, err);
  }

  log(`Ramp ${cfg.ramp}s, warm-up ${cfg.warmup}s (discarded), measuring ${cfg.duration}s; ${cfg.users} users` + (cfg.pdfUsers ? `, ${cfg.pdfUsers} PDF users` : ''));
  const results = await Promise.allSettled(Array.from({ length: cfg.users }, (_, k) => virtualUser(k + 1)));
  for (const r of results) if (r.status === 'rejected') failures.push({ name: 'virtual user', detail: String(r.reason?.message ?? r.reason), measured: true });

  const seconds = cfg.duration;
  const search = summarize(samples.filter((s) => s.group === 'search'), seconds);
  const mixedAll = summarize(samples.filter((s) => s.group !== 'pdf'), seconds);
  const pdfStats = summarize(samples.filter((s) => s.group === 'pdf'), seconds);
  const byName = {};
  for (const s of samples) (byName[s.name.replace(/\?.*$/, '')] ??= []).push(s);
  const probe = await burst(probeUser, cfg);

  const verdict = evaluate({ search, errors: failures.length, unintended429, probe }, { p95Ms: cfg.p95Ms });
  const out = {
    finishedAt: new Date().toISOString(),
    host: { platform: process.platform, cpus: os.cpus().length, node: process.version },
    config: { baseUrl: cfg.baseUrl, users: cfg.users, pdfUsers: cfg.pdfUsers, ramp: cfg.ramp, warmup: cfg.warmup, duration: cfg.duration, p95Ms: cfg.p95Ms, thinkMs: [cfg.thinkMin, cfg.thinkMax] },
    catalogSearch: search, allRequests: mixedAll, pdf: pdfStats,
    perEndpoint: Object.fromEntries(Object.entries(byName).map(([k, v]) => [k, summarize(v, seconds)])),
    failures: failures.slice(0, 50), failureCount: failures.length, unintended429, probe, ...verdict,
  };
  return out;
}

/** One extra user, `burstRequests` GETs spread over `probeSeconds`; the first 429 must be request 41 and carry Retry-After. */
async function burst(u, cfg) {
  try {
    await u.login();
    let firstThrottled = null;
    let retryAfter = null;
    const gap = (cfg.probeSeconds * 1000) / 60;
    for (let n = 1; n <= 60; n++) {
      const t = performance.now();
      const r = await u.call('GET', `/api/v1/products?q=${pick(FRAGMENTS)}&per_page=24`);
      if (r.status === 429 && firstThrottled === null) { firstThrottled = n; retryAfter = r.headers.get('retry-after'); }
      else if (r.status !== 200 && r.status !== 429) return { ok: false, detail: `probe request ${n} -> ${r.status}` };
      else if (r.status === 200 && firstThrottled !== null) return { ok: false, detail: `200 after 429 at request ${n}` };
      await sleep(Math.max(0, gap - (performance.now() - t)));
    }
    const ok = firstThrottled === 41 && /^\d+$/.test(retryAfter ?? '');
    return { ok, firstThrottled, retryAfter, detail: ok ? 'first 429 at request 41 with Retry-After' : `first 429 at request ${firstThrottled}, Retry-After ${retryAfter}` };
  } catch (e) {
    return { ok: false, detail: `probe error: ${e.message}` };
  }
}

// ---------- reporting ----------

const f = (x) => (Number.isFinite(x) ? x.toFixed(0) : '-');
export function table(rows) {
  const head = ['group', 'n', 'rps', 'p50', 'p90', 'p95', 'p99', 'max (ms)'];
  const lines = rows.map(([name, s]) => [name, s.n, s.rps.toFixed(1), f(s.p50), f(s.p90), f(s.p95), f(s.p99), f(s.max)].map(String));
  const w = head.map((h, i) => Math.max(h.length, ...lines.map((l) => l[i].length)));
  const fmt = (l) => l.map((c, i) => c.padEnd(w[i])).join('  ');
  return [fmt(head), ...lines.map(fmt)].join('\n');
}

function report(out, log = console.log) {
  const rows = [['catalog search', out.catalogSearch], ['all (excl. pdf)', out.allRequests]];
  if (out.pdf.n) rows.push(['invoice pdf', out.pdf]);
  for (const [k, v] of Object.entries(out.perEndpoint)) rows.push([`  ${k}`, v]);
  log('\n' + table(rows));
  log(`\nFailed requests: ${out.failureCount}; unintended 429: ${out.unintended429}; burst probe: ${out.probe.detail}`);
  for (const x of out.failures.slice(0, 10)) log(`  - ${x.name}: ${x.detail}`);
  log(`\nVerdict: ${out.pass ? 'PASS' : 'FAIL'}${out.reasons.length ? ' (' + out.reasons.join('; ') + ')' : ''}`);
}

// ---------- self test ----------

/** Tiny in-process stand-in for the API. Options: delayMs, limit (catalog-search per session), badTotal. */
function startStub({ delayMs = 0, limit = 40, badTotal = false } = {}) {
  const hits = new Map();
  const token = 'tok==abc';
  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://x');
    const cookies = Object.fromEntries((req.headers.cookie ?? '').split('; ').filter(Boolean).map((c) => [c.slice(0, c.indexOf('=')), c.slice(c.indexOf('=') + 1)]));
    const json = (status, body, extra = {}) => { res.writeHead(status, { 'Content-Type': 'application/json', ...extra }); res.end(JSON.stringify(body)); };
    let body = '';
    for await (const chunk of req) body += chunk;
    if (delayMs) await sleep(delayMs);
    if (url.pathname === '/sanctum/csrf-cookie') {
      res.writeHead(204, { 'Set-Cookie': [`XSRF-TOKEN=${encodeURIComponent(token)}; Path=/; SameSite=Lax`, 'portal_session=guest; Path=/; HttpOnly'] });
      return res.end();
    }
    if (url.pathname === '/api/v1/auth/login') {
      const ok = req.headers['x-xsrf-token'] === token && decodeURIComponent(cookies['XSRF-TOKEN'] ?? '') === token
        && req.headers.origin === 'http://localhost:8200' && /^203\.0\.113\.\d+$/.test(req.headers['x-forwarded-for'] ?? '');
      if (!ok) return json(419, { message: 'CSRF token mismatch.' });
      const email = JSON.parse(body).email;
      return json(200, { data: { email } }, { 'Set-Cookie': [`portal_session=${email}; Path=/; HttpOnly`] });
    }
    const sid = cookies.portal_session;
    if (!sid || sid === 'guest') return json(401, { message: 'Unauthenticated.' });
    if (url.pathname === '/api/v1/categories') return json(200, { data: [{ slug: 'a' }, { slug: 'b' }] });
    if (url.pathname === '/api/v1/products/filters') return json(200, { data: { brands: ['B1', 'B2'], seal_types: ['open', '2rs'], ranges: { inner_diameter_mm: { min: 5, max: 100 }, outer_diameter_mm: { min: 15, max: 200 } } } });
    if (url.pathname === '/api/v1/products') {
      const n = (hits.get(sid) ?? 0) + 1;
      hits.set(sid, n);
      if (n > limit) return json(429, { message: 'Too many attempts.' }, { 'Retry-After': '42' });
      return json(200, { data: [{ id: 7 }, { id: 8 }], meta: { total: badTotal ? '2' : 2 } });
    }
    if (/^\/api\/v1\/products\/\d+$/.test(url.pathname)) return json(200, { data: { id: 7 } });
    if (url.pathname === '/api/v1/invoices') return json(200, { data: [{ id: 'uuid-1' }], meta: { total: 1 } });
    if (/^\/api\/v1\/invoices\/[\w-]+\/pdf$/.test(url.pathname)) { res.writeHead(200, { 'Content-Type': 'application/pdf' }); return res.end('%PDF-1.4 stub'); }
    return json(404, { message: 'Not found' });
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ server, url: `http://127.0.0.1:${server.address().port}` })));
}

async function selfTest() {
  // Statistics.
  const hundred = Array.from({ length: 100 }, (_, i) => ({ ms: i + 1 })).reverse();
  const s = summarize(hundred, 10);
  assert.deepEqual([s.n, s.rps, s.p50, s.p90, s.p95, s.p99, s.max], [100, 10, 50, 90, 95, 99, 100]);
  assert.equal(percentile([5], 99), 5);
  assert.ok(Number.isNaN(percentile([], 50)));
  assert.equal(FRAGMENTS.length, 30);
  assert.equal(new Set(FRAGMENTS).size, 30);

  // Acceptance logic.
  const good = { search: { n: 10, p95: 100 }, errors: 0, unintended429: 0, probe: { ok: true } };
  assert.equal(evaluate(good, { p95Ms: 500 }).pass, true);
  assert.equal(evaluate({ ...good, search: { n: 10, p95: 500 } }, { p95Ms: 500 }).pass, false, 'p95 equal to the threshold fails');
  assert.equal(evaluate({ ...good, errors: 1 }, { p95Ms: 500 }).pass, false);
  assert.equal(evaluate({ ...good, unintended429: 1 }, { p95Ms: 500 }).pass, false);
  assert.equal(evaluate({ ...good, probe: { ok: false, detail: 'x' } }, { p95Ms: 500 }).pass, false);
  assert.equal(evaluate({ ...good, search: { n: 0, p95: NaN } }, { p95Ms: 500 }).pass, false);

  // End to end against the stub. Think time is shortened; the stub limit is 40 per session like the real limiter.
  const base = { users: 5, password: 'pw', ramp: 0.2, warmup: 0.3, duration: 1.5, p95Ms: 500, thinkMin: 40, thinkMax: 80, probeSeconds: 0.6, pdfUsers: 0, pdfEveryMs: 300, origin: 'http://localhost:8200' };
  const quiet = () => {};
  const run1 = async (stubOpts, over = {}) => {
    const stub = await startStub(stubOpts);
    try { return await run({ ...base, ...over, baseUrl: stub.url }, quiet); } finally { stub.server.close(); stub.server.closeAllConnections(); }
  };

  let out = await run1({});
  assert.equal(out.pass, true, out.reasons.join('; '));
  assert.ok(out.catalogSearch.n > 20, `samples ${out.catalogSearch.n}`);
  assert.equal(out.failureCount, 0);
  assert.equal(out.unintended429, 0);
  assert.equal(out.probe.firstThrottled, 41);
  assert.equal(out.probe.retryAfter, '42');

  out = await run1({ delayMs: 60 }, { p95Ms: 30 });
  assert.equal(out.pass, false);
  assert.ok(out.reasons.some((r) => r.includes('p95')), out.reasons.join('; '));

  out = await run1({ badTotal: true });
  assert.equal(out.pass, false);
  assert.ok(out.failureCount > 0 && out.failures[0].detail.includes('meta.total'), JSON.stringify(out.failures[0]));

  out = await run1({ limit: 5 });
  assert.equal(out.pass, false);
  assert.ok(out.unintended429 > 0);
  assert.ok(!out.probe.ok && out.probe.firstThrottled === 6);

  out = await run1({}, { pdfUsers: 2 });
  assert.equal(out.pass, true, out.reasons.join('; '));
  assert.ok(out.pdf.n >= 2, `pdf samples ${out.pdf.n}`);

  console.log('self-test OK: statistics, acceptance rules, login flow (XSRF, Origin, XFF), body validation, burst probe, pdf users');
}

// ---------- main ----------

async function main() {
  const args = process.argv.slice(2);
  if (args.includes('--self-test')) return selfTest();
  const arg = (name) => args.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3);
  const env = process.env;
  const cfg = {
    baseUrl: (env.LOADTEST_BASE_URL ?? 'http://localhost:8200').replace(/\/$/, ''),
    users: Number(env.LOADTEST_USERS ?? 50),
    password: env.LOADTEST_PASSWORD, // no default: the seeded load users never get a published password
    ramp: 10, warmup: 30,
    duration: Number(env.LOADTEST_DURATION ?? 120),
    p95Ms: Number(env.LOADTEST_P95_MS ?? 500),
    thinkMin: 1500, thinkMax: 2500, probeSeconds: 10,
    pdfUsers: Number(arg('pdf-users') ?? 0),
    origin: 'http://localhost:8200', // the rig's web origin (SANCTUM_STATEFUL_DOMAINS); the script talks to the same host
  };
  if (!cfg.password || cfg.password.length < 12) throw new Error('Set LOADTEST_PASSWORD (at least 12 characters): the same value the rig was seeded with.');
  // This script fires 50 virtual users with spoofed X-Forwarded-For headers, bulk logins and a burst probe:
  // it must never be pointed at a real system by accident.
  const host = new URL(cfg.baseUrl).hostname;
  if (!['localhost', '127.0.0.1', '::1', '[::1]'].includes(host) && env.LOADTEST_ALLOW_REMOTE !== '1') {
    throw new Error(`Refusing to load-test ${cfg.baseUrl}: not a local address. Set LOADTEST_ALLOW_REMOTE=1 only for a disposable test environment you own.`);
  }
  if (cfg.users > 99) throw new Error('LOADTEST_USERS must be at most 99 (load001..load099 plus load100 for the burst probe)');
  cfg.probeIndex = 100; // load100 is reserved for the burst probe
  const out = await run({ ...cfg, email: (i) => `load${String(Math.min(i, 100)).padStart(3, '0')}@load.test` });
  report(out);
  const baseline = arg('baseline');
  if (baseline) {
    const b = JSON.parse(readFileSync(baseline, 'utf8')).catalogSearch.p95;
    const ratio = out.catalogSearch.p95 / b;
    console.log(`\nBaseline p95 ${f(b)} ms, this run ${f(out.catalogSearch.p95)} ms: ${((ratio - 1) * 100).toFixed(1)}% -> ${ratio <= 1.2 ? 'within' : 'MORE THAN'} 20%`);
    out.baseline = { p95: b, ratio };
  }
  const dir = join(dirname(fileURLToPath(import.meta.url)), 'results');
  mkdirSync(dir, { recursive: true });
  const file = join(dir, `${new Date().toISOString().replace(/[:.]/g, '-')}${cfg.pdfUsers ? '-mixed' : ''}.json`);
  writeFileSync(file, JSON.stringify(out, null, 2));
  console.log(`Results: ${file}`);
  process.exit(out.pass ? 0 : 1);
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  main().catch((e) => { console.error(e); process.exit(2); });
}
