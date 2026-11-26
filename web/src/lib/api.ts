export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
  status: number;
  errors: FieldErrors;
  retryAfter: number | null;

  constructor(
    status: number,
    message: string,
    errors: FieldErrors = {},
    retryAfter: number | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.retryAfter = retryAfter;
  }
}

let onUnauthorized: (() => void) | null = null;
/** AuthProvider registers this so any 401 on a non-bootstrap call drops the session. */
export function setUnauthorizedHandler(fn: (() => void) | null) {
  onUnauthorized = fn;
}

function readXsrf(): string | null {
  const pair = document.cookie
    .split('; ')
    .find((c) => c.startsWith('XSRF-TOKEN='));
  return pair ? decodeURIComponent(pair.slice('XSRF-TOKEN='.length)) : null;
}

async function toApiError(res: Response): Promise<ApiError> {
  let body: { message?: unknown; errors?: unknown } = {};
  try {
    body = await res.json();
  } catch {
    // non-JSON error body: keep defaults
  }
  const retry = Number.parseInt(res.headers.get('Retry-After') ?? '', 10);
  return new ApiError(
    res.status,
    typeof body.message === 'string' ? body.message : `HTTP ${res.status}`,
    (body.errors as FieldErrors | undefined) ?? {},
    Number.isFinite(retry) ? retry : null,
  );
}

export async function csrfCookie(): Promise<void> {
  const res = await fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  });
  if (!res.ok) throw await toApiError(res);
}

interface Options {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  /** A FormData body is sent as multipart: the browser sets the boundary header. */
  body?: unknown;
  /** Bootstrap calls (GET /me) treat 401 as "guest", not as a session loss. */
  skipUnauthorized?: boolean;
}

async function request<T>(
  path: string,
  opts: Options = {},
  retried = false,
): Promise<T> {
  const { method = 'GET', body, skipUnauthorized } = opts;
  const mutating = method !== 'GET';
  if (mutating && !readXsrf()) await csrfCookie();

  const multipart = body instanceof FormData;
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (mutating) {
    if (!multipart) headers['Content-Type'] = 'application/json';
    const token = readXsrf();
    if (token) headers['X-XSRF-TOKEN'] = token;
  }
  const res = await fetch(path, {
    method,
    headers,
    credentials: 'include',
    body:
      body === undefined ? undefined : multipart ? body : JSON.stringify(body),
  });

  if (res.status === 419 && !retried) {
    await csrfCookie();
    return request<T>(path, opts, true);
  }
  if (!res.ok) {
    const err = await toApiError(res);
    if (res.status === 401 && !skipUnauthorized) onUnauthorized?.();
    throw err;
  }
  return (res.status === 204 ? undefined : await res.json()) as T;
}

export const api = {
  get: <T>(path: string, opts?: Pick<Options, 'skipUnauthorized'>) =>
    request<T>(path, opts),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body }),
  put: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'PATCH', body }),
  upload: <T>(path: string, form: FormData) =>
    request<T>(path, { method: 'POST', body: form }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};

/** Safe user-facing text for any error thrown by the wrapper. */
export function describeError(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 429) {
      return err.retryAfter
        ? `Too many attempts. Please try again in ${err.retryAfter} seconds.`
        : 'Too many attempts. Please try again later.';
    }
    if (err.status === 422) {
      return (
        err.errors.email?.[0] ??
        Object.values(err.errors)[0]?.[0] ??
        err.message
      );
    }
    return 'Something went wrong. Please try again.';
  }
  return 'Could not reach the server. Check your connection and try again.';
}

/**
 * Open-redirect guard: a single leading slash, no backslash tricks, and no
 * control characters (browsers strip tab/CR/LF from URLs, so "/\t/evil" would
 * become "//evil").
 */
export function safeNext(next: string | null): string {
  // eslint-disable-next-line no-control-regex -- matching control chars is the point
  if (!next || /[\u0000-\u001f\u007f]/.test(next)) return '/';
  return /^\/(?![/\\])/.test(next) ? next : '/';
}
