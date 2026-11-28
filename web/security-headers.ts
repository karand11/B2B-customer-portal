/**
 * The one definition of the SPA's response headers (docs/phase-7-design.md 3.3).
 * `vite preview` sends them (enforced in the e2e run); the dev server sends none
 * because HMR needs inline script and websockets. docs/ops/nginx-spa-headers.conf
 * repeats the same strings for production; a Vitest test keeps them in sync.
 *
 * Referrer-Policy is `origin`, never `no-referrer`: Sanctum decides "stateful
 * browser" from Origin/Referer (contract Phase 6, token-hygiene note).
 */
const CSP_DIRECTIVES = [
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
];

/** Without upgrade-insecure-requests: the preview server is plain HTTP. */
export const CSP = CSP_DIRECTIVES.join('; ');
/** Production (Nginx over HTTPS). */
export const CSP_WITH_UPGRADE = `${CSP}; upgrade-insecure-requests`;

export const SPA_SECURITY_HEADERS: Record<string, string> = {
  'Content-Security-Policy': CSP,
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'origin',
  'X-Frame-Options': 'DENY',
  'Permissions-Policy':
    'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
  'Cross-Origin-Opener-Policy': 'same-origin',
  'Cross-Origin-Resource-Policy': 'same-origin',
};
