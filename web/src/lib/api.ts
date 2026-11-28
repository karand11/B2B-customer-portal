export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
  status: number;
  errors: FieldErrors;
  retryAfter: number | null;
  /** X-Request-Id of the failed response, so support can find the log line. */
  requestId: string | null;
  /** The parsed JSON body (the checkout 409 carries the fresh `cart`). */
  body: Record<string, unknown>;

  constructor(
    status: number,
    message: string,
    errors: FieldErrors = {},
    retryAfter: number | null = null,
    extra: { requestId?: string | null; body?: Record<string, unknown> } = {},
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.retryAfter = retryAfter;
    this.requestId = extra.requestId ?? null;
    this.body = extra.body ?? {};
  }
}

// Same shape the server accepts and generates; anything else is not displayed.
const REQUEST_ID = /^[A-Za-z0-9._-]{8,64}$/;

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
  let body: Record<string, unknown> = {};
  try {
    const json: unknown = await res.json();
    if (json && typeof json === 'object')
      body = json as Record<string, unknown>;
  } catch {
    // non-JSON error body: keep defaults
  }
  const retry = Number.parseInt(res.headers.get('Retry-After') ?? '', 10);
  const rid = res.headers.get('X-Request-Id') ?? '';
  return new ApiError(
    res.status,
    typeof body.message === 'string' ? body.message : `HTTP ${res.status}`,
    (body.errors as FieldErrors | undefined) ?? {},
    Number.isFinite(retry) ? retry : null,
    { requestId: REQUEST_ID.test(rid) ? rid : null, body },
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

/**
 * Binary GET (PDF download). Same-origin cookie session, no CSRF header needed for GET.
 * Failures become ApiError exactly like `request`, including the 401 session drop.
 */
export async function fetchBlob(
  path: string,
  accept: string,
): Promise<{ blob: Blob; headers: Headers }> {
  const res = await fetch(path, {
    credentials: 'same-origin',
    headers: { Accept: accept },
  });
  if (!res.ok) {
    const err = await toApiError(res);
    if (res.status === 401) onUnauthorized?.();
    throw err;
  }
  return { blob: await res.blob(), headers: res.headers };
}

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
    // Plain text only (never markup): the id is validated against REQUEST_ID above.
    return `Something went wrong. Please try again.${err.requestId ? ` Reference: ${err.requestId}` : ''}`;
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
