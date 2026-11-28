#!/usr/bin/env node
// Fails the build on high/critical dependency advisories that are not covered by a valid allowlist entry.
// Usage: node ops/audit/check-audit.mjs --composer composer-audit.json --npm web=web-audit.json --npm e2e=e2e-audit.json
//        [--allowlist .audit-allowlist.json] [--today YYYY-MM-DD]
//        node ops/audit/check-audit.mjs --self-test
// No dependencies. Policy: docs/phase-7-design.md section 9.1.
import { readFileSync, existsSync, appendFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const FAIL_SEVERITIES = new Set(['high', 'critical']);
const MAX_ALLOWLIST_DAYS = 60;
const DAY_MS = 86_400_000;

// Findings: { ecosystem, package, ids: string[], severity, title, source }
export function parseComposer(json, source = 'composer') {
  // Fail closed: `composer audit --format=json` always prints an `advisories` key (empty when clean). Anything
  // else (an error object, an empty file, a crash) must NOT be read as "no findings".
  if (!json || typeof json !== 'object' || json.error || !('advisories' in json)) {
    throw new Error(`${source}: not a composer audit report (no "advisories" key): the audit tool probably failed`);
  }
  const out = [];
  const advisories = json.advisories ?? {};
  for (const [pkg, list] of Object.entries(Array.isArray(advisories) ? {} : advisories)) {
    for (const a of list) {
      const ids = [a.advisoryId, a.cve, ...(String(a.link ?? '').match(/GHSA-[a-z0-9-]+/gi) ?? [])].filter(Boolean);
      // Fail safe: an advisory without a severity counts as high.
      const severity = String(a.severity ?? 'high').toLowerCase();
      out.push({ ecosystem: 'composer', package: a.packageName ?? pkg, ids, severity, title: a.title ?? '', source });
    }
  }
  return out;
}

export function parseNpm(json, source) {
  // Fail closed: a registry or network error prints {"error": {...}} without `vulnerabilities`, which used to look
  // like "zero findings". A real report has auditReportVersion 2 and metadata.vulnerabilities.
  if (!json || typeof json !== 'object' || json.error || json.auditReportVersion !== 2 || !json.metadata?.vulnerabilities) {
    throw new Error(`${source}: not an npm audit report (auditReportVersion 2 with metadata.vulnerabilities expected): the audit tool probably failed`);
  }
  const out = [];
  for (const [name, v] of Object.entries(json?.vulnerabilities ?? {})) {
    const ids = [];
    for (const via of v.via ?? []) {
      if (typeof via === 'object') {
        ids.push(...(String(via.url ?? '').match(/GHSA-[a-z0-9-]+/gi) ?? []));
        if (via.source) ids.push(String(via.source));
      }
    }
    out.push({ ecosystem: 'npm', package: v.name ?? name, ids, severity: String(v.severity ?? 'high').toLowerCase(), title: '', source });
  }
  return out;
}

const norm = (s) => String(s).toLowerCase();

export function evaluate(findings, allowlist, todayIso) {
  const today = Date.parse(todayIso);
  const errors = [];
  const warnings = [];
  const used = new Set();

  allowlist.forEach((e, i) => {
    const label = `allowlist[${i}] ${e.ecosystem}:${e.package}:${e.id}`;
    for (const k of ['ecosystem', 'package', 'id', 'reason', 'owner', 'added', 'expires']) {
      if (!e[k]) errors.push(`${label}: missing "${k}"`);
    }
    const added = Date.parse(e.added);
    const expires = Date.parse(e.expires);
    if (Number.isNaN(added) || Number.isNaN(expires)) errors.push(`${label}: invalid date`);
    else {
      if (expires < today) errors.push(`${label}: expired on ${e.expires}`);
      if ((expires - added) / DAY_MS > MAX_ALLOWLIST_DAYS) errors.push(`${label}: lifetime exceeds ${MAX_ALLOWLIST_DAYS} days`);
    }
  });

  for (const f of findings) {
    if (!FAIL_SEVERITIES.has(f.severity)) continue;
    const idx = allowlist.findIndex(
      (e) =>
        norm(e.ecosystem) === f.ecosystem &&
        norm(e.package) === norm(f.package) &&
        f.ids.some((id) => norm(id) === norm(e.id)),
    );
    if (idx === -1) errors.push(`${f.severity.toUpperCase()} ${f.ecosystem}:${f.package} [${f.ids.join(', ') || 'no id'}] (${f.source}) ${f.title}`.trim());
    else used.add(idx);
  }

  allowlist.forEach((e, i) => {
    if (!used.has(i)) warnings.push(`allowlist[${i}] ${e.ecosystem}:${e.package}:${e.id} matches no finding any more: remove it`);
  });
  return { errors, warnings };
}

function selfTest() {
  const composer = { advisories: { 'a/b': [{ advisoryId: 'PKSA-1', cve: 'CVE-1', packageName: 'a/b', severity: 'high', title: 't' }, { advisoryId: 'PKSA-2', packageName: 'c/d' }] } };
  const npm = { auditReportVersion: 2, metadata: { vulnerabilities: {} }, vulnerabilities: { x: { name: 'x', severity: 'critical', via: [{ url: 'https://github.com/advisories/GHSA-aaaa-bbbb-cccc', source: 1 }] }, y: { name: 'y', severity: 'moderate', via: [] } } };
  const findings = [...parseComposer(composer), ...parseNpm(npm, 'web')];
  assert.equal(findings.find((f) => f.ids.includes('PKSA-2')).severity, 'high', 'missing severity counts as high');
  let r = evaluate(findings, [], '2026-01-01');
  assert.equal(r.errors.length, 3, 'two composer + one npm finding fail, moderate does not');
  const ok = (id, pkg, eco, expires = '2026-02-01') => ({ ecosystem: eco, package: pkg, id, reason: 'not reachable', owner: 'me', added: '2026-01-01', expires });
  r = evaluate(findings, [ok('PKSA-1', 'a/b', 'composer'), ok('PKSA-2', 'c/d', 'composer'), ok('GHSA-AAAA-BBBB-CCCC', 'x', 'npm')], '2026-01-15');
  assert.deepEqual(r.errors, [], 'allowlisted findings pass');
  r = evaluate(findings, [ok('PKSA-1', 'a/b', 'composer', '2026-01-10'), ok('PKSA-2', 'c/d', 'composer'), ok('GHSA-aaaa-bbbb-cccc', 'x', 'npm')], '2026-01-15');
  assert.ok(r.errors.some((e) => e.includes('expired')), 'expired entry fails');
  r = evaluate(findings, [ok('PKSA-1', 'a/b', 'composer', '2026-06-01')], '2026-01-15');
  assert.ok(r.errors.some((e) => e.includes('lifetime exceeds')), 'entries over 60 days fail');
  r = evaluate([], [ok('GONE-1', 'z', 'npm')], '2026-01-15');
  assert.equal(r.warnings.length, 1, 'stale entry warns');
  r = evaluate(findings, [{ ecosystem: 'npm', package: 'x', id: 'GHSA-aaaa-bbbb-cccc' }], '2026-01-15');
  assert.ok(r.errors.some((e) => e.includes('missing "reason"')), 'entries need a reason and owner');
  // Fail closed on tool failures (these used to count as "no findings").
  assert.throws(() => parseNpm({ error: { code: 'ENOTFOUND', summary: 'request failed' } }, 'web'), /not an npm audit report/);
  assert.throws(() => parseNpm({}, 'web'), /not an npm audit report/);
  assert.throws(() => parseNpm(null, 'web'), /not an npm audit report/);
  assert.throws(() => parseNpm({ vulnerabilities: {} }, 'web'), /not an npm audit report/, 'no report version');
  assert.deepEqual(parseNpm({ auditReportVersion: 2, metadata: { vulnerabilities: { total: 0 } }, vulnerabilities: {} }, 'web'), [], 'a clean report has no findings');
  assert.throws(() => parseComposer({ error: 'x' }), /not a composer audit report/);
  assert.throws(() => parseComposer({}), /not a composer audit report/);
  assert.throws(() => parseComposer(null), /not a composer audit report/);
  assert.deepEqual(parseComposer({ advisories: [] }), [], 'a clean composer report has no findings');
  console.log('check-audit self-test: ok');
}

function readJson(path) {
  if (!existsSync(path)) throw new Error(`missing input file: ${path}`);
  return JSON.parse(readFileSync(path, 'utf8').replace(/^﻿/, '')); // tolerate a UTF-8 BOM (PowerShell redirects add one)
}

function main(argv) {
  if (argv.includes('--self-test')) return selfTest();
  const args = { composer: [], npm: [], allowlist: '.audit-allowlist.json', today: new Date().toISOString().slice(0, 10) };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--composer') args.composer.push(argv[++i]);
    else if (a === '--npm') args.npm.push(argv[++i]);
    else if (a === '--allowlist') args.allowlist = argv[++i];
    else if (a === '--today') args.today = argv[++i];
  }
  const findings = [];
  try {
    if (!args.composer.length && !args.npm.length) throw new Error('no audit inputs given (--composer / --npm)');
    for (const p of args.composer) findings.push(...parseComposer(readJson(p), p));
    for (const spec of args.npm) {
      const [name, file] = spec.includes('=') ? spec.split('=') : [spec, spec];
      findings.push(...parseNpm(readJson(file), name));
    }
  } catch (e) {
    // Fail closed: an unreadable or failed audit is a failed check, never a pass.
    const msg = ['## Dependency audit', '', `- FAIL the audit could not be evaluated: ${e.message}`].join('\n');
    console.error(msg);
    if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, msg + '\n');
    process.exit(2);
  }
  const allowlist = existsSync(args.allowlist) ? readJson(args.allowlist) : [];
  const { errors, warnings } = evaluate(findings, allowlist, args.today);

  const lines = ['## Dependency audit', '', `Findings: ${findings.length} (high/critical: ${findings.filter((f) => FAIL_SEVERITIES.has(f.severity)).length}), allowlist entries: ${allowlist.length}`, ''];
  for (const e of errors) lines.push(`- FAIL ${e}`);
  for (const w of warnings) lines.push(`- warn ${w}`);
  if (!errors.length) lines.push('- OK: no high/critical advisory without a valid allowlist entry');
  const text = lines.join('\n');
  console.log(text);
  if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, text + '\n');
  process.exit(errors.length ? 1 : 0);
}

if (import.meta.url === `file://${process.argv[1].replace(/\\/g, '/')}` || process.argv[1]?.endsWith('check-audit.mjs')) main(process.argv.slice(2));
