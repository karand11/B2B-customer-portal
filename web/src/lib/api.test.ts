import { describe, expect, it, vi } from 'vitest';
import { ApiError, api, safeNext, setUnauthorizedHandler } from './api';
import { CSRF, mockApi } from '../test/utils';

describe('api wrapper', () => {
  it('sends credentials and Accept on GET', async () => {
    const mock = mockApi({ 'GET /api/v1/x': { body: { ok: 1 } } });
    await api.get('/api/v1/x');
    expect(mock.fn.mock.calls[0][1]?.credentials).toBe('include');
    expect(mock.calls[0].headers.get('Accept')).toBe('application/json');
  });

  it('fetches the csrf cookie first when missing, then sends the decoded token', async () => {
    const mock = mockApi({ 'POST /api/v1/x': { status: 204 } });
    await api.post('/api/v1/x', { a: 1 });
    expect(mock.calls.map((c) => c.url)).toEqual([
      '/sanctum/csrf-cookie',
      '/api/v1/x',
    ]);
    expect(mock.calls[1].headers.get('X-XSRF-TOKEN')).toBe('tok=123');
    expect(mock.calls[1].headers.get('Content-Type')).toBe('application/json');
  });

  it('on 419 refetches the csrf cookie once and retries once', async () => {
    let n = 0;
    const mock = mockApi({
      'POST /api/v1/x': () =>
        n++ === 0
          ? { status: 419, body: { message: 'CSRF token mismatch.' } }
          : { body: { ok: 1 } },
    });
    document.cookie = 'XSRF-TOKEN=stale; path=/';
    await expect(api.post('/api/v1/x', {})).resolves.toEqual({ ok: 1 });
    expect(mock.to(CSRF)).toHaveLength(1);
    expect(mock.to('POST /api/v1/x')).toHaveLength(2);
  });

  it('does not retry a second 419', async () => {
    const mock = mockApi({
      'POST /api/v1/x': {
        status: 419,
        body: { message: 'CSRF token mismatch.' },
      },
    });
    document.cookie = 'XSRF-TOKEN=stale; path=/';
    await expect(api.post('/api/v1/x', {})).rejects.toMatchObject({
      status: 419,
    });
    expect(mock.to('POST /api/v1/x')).toHaveLength(2);
  });

  it('throws ApiError with retryAfter and calls the 401 handler unless skipped', async () => {
    mockApi({
      'GET /api/v1/a': {
        status: 429,
        headers: { 'Retry-After': '7' },
        body: { message: 'slow' },
      },
      'GET /api/v1/b': { status: 401, body: { message: 'Unauthenticated.' } },
    });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);
    const a = await api.get('/api/v1/a').catch((e: unknown) => e);
    expect(a).toBeInstanceOf(ApiError);
    expect((a as ApiError).retryAfter).toBe(7);
    await expect(api.get('/api/v1/b')).rejects.toMatchObject({ status: 401 });
    expect(handler).toHaveBeenCalledTimes(1);
    await api.get('/api/v1/b', { skipUnauthorized: true }).catch(() => {});
    expect(handler).toHaveBeenCalledTimes(1);
    setUnauthorizedHandler(null);
  });
});

describe('safeNext', () => {
  it.each(['/', '/staff', '/staff?x=1', '/orders?page=2&q=a%20b', '/a/b#frag'])(
    'accepts internal path %j',
    (p) => {
      expect(safeNext(p)).toBe(p);
    },
  );

  it.each([
    null,
    '',
    'https://evil.example',
    '//evil.example',
    '/\\evil.example',
    'evil.example',
    '/\t/evil.example',
    '/\n/evil.example',
    '/\r/x',
    '/\u0000x',
    '/\u007fx',
    '/x\ty',
    '/\t\\evil.example',
  ])('rejects %j', (p) => {
    expect(safeNext(p)).toBe('/');
  });

  it('rejects the decoded form of ?next=/%09/evil.example', () => {
    const next = new URLSearchParams('next=/%09/evil.example').get('next');
    expect(next).toBe('/\t/evil.example');
    expect(safeNext(next)).toBe('/');
  });
});
