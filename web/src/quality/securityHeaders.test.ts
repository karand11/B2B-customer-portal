import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import config from '../../vite.config';
import {
  CSP,
  CSP_WITH_UPGRADE,
  SPA_SECURITY_HEADERS,
} from '../../security-headers';

const root = resolve(__dirname, '../..');
const nginxFile = resolve(root, '../docs/ops/nginx-spa-headers.conf');
const squash = (s: string) => s.replace(/\s+/g, ' ');

describe('SPA security headers (single definition)', () => {
  it('has the exact CSP of the design (section 3.3)', () => {
    expect(CSP.split('; ')).toEqual([
      "default-src 'self'",
      "script-src 'self'",
      "style-src 'self'",
      "img-src 'self' data:",
      "font-src 'self'",
      "connect-src 'self'",
      "object-src 'none'",
      "base-uri 'self'",
      "form-action 'self'",
      "frame-ancestors 'none'",
      "manifest-src 'self'",
    ]);
    expect(CSP_WITH_UPGRADE).toBe(`${CSP}; upgrade-insecure-requests`);
  });

  it('never sends no-referrer (Sanctum needs Origin/Referer, contract Phase 6)', () => {
    expect(SPA_SECURITY_HEADERS['Referrer-Policy']).toBe('origin');
  });

  it('is exactly what `vite preview` sends, and dev sends none', () => {
    expect(config.preview?.headers).toEqual(SPA_SECURITY_HEADERS);
    expect(SPA_SECURITY_HEADERS['Content-Security-Policy']).toBe(CSP);
    expect(config.server?.headers).toBeUndefined();
  });

  it('matches the meta fallback in index.html and has no inline code', () => {
    const html = readFileSync(resolve(root, 'index.html'), 'utf8');
    expect(html).toContain(
      `<meta name="referrer" content="${SPA_SECURITY_HEADERS['Referrer-Policy']}" />`,
    );
    // A meta CSP cannot express frame-ancestors and would break the dev server.
    expect(html).not.toMatch(/http-equiv=["']Content-Security-Policy/i);
    // The enforced CSP has no 'unsafe-inline': the entry HTML must not need it.
    expect(html).not.toMatch(/<style/i);
    expect(html).not.toMatch(/\sstyle=/i);
    expect(html).not.toMatch(/<script(?![^>]*\ssrc=)/i);
    expect(html).not.toMatch(/\son[a-z]+=/i);
  });

  it('has the documented Nginx header set (when the file exists)', () => {
    if (!existsSync(nginxFile)) return; // written later by the main session
    const conf = squash(readFileSync(nginxFile, 'utf8'));
    for (const [name, value] of Object.entries(SPA_SECURITY_HEADERS)) {
      if (name === 'Content-Security-Policy') continue;
      expect(conf, name).toContain(name);
      expect(conf, `${name} value`).toContain(value);
    }
    // Nginx serves HTTPS, so it also carries upgrade-insecure-requests.
    expect(conf).toContain('Content-Security-Policy');
    for (const directive of CSP_WITH_UPGRADE.split('; '))
      expect(conf, directive).toContain(directive);
    expect(conf).not.toMatch(/Referrer-Policy\S*\s+"?no-referrer/i);
  });
});
