import { render } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import {
  RouterProvider,
  createMemoryRouter,
  useLocation,
} from 'react-router-dom';
import { vi } from 'vitest';
import { AuthProvider } from '../auth/AuthContext';
import AppRoutes from '../AppRoutes';
import type { Me } from '../auth/types';

export const customer: Me = {
  id: 3,
  name: 'Alice Customer',
  email: 'alice@northwind.test',
  role: 'customer',
  company: { id: 1, name: 'Northwind Machinery Ltd' },
};
export const staff: Me = {
  id: 2,
  name: 'Sam Staff',
  email: 'staff@portal.test',
  role: 'staff',
  company: null,
};
export const admin: Me = {
  id: 1,
  name: 'Ada Admin',
  email: 'admin@portal.test',
  role: 'admin',
  company: null,
};

export interface Spec {
  status?: number;
  body?: unknown;
  headers?: Record<string, string>;
}
export interface Req {
  url: string;
  method: string;
  headers: Headers;
  body: Record<string, unknown> | null;
}
type Handler = Spec | ((req: Req) => Spec | Promise<Spec>);

export const CSRF = 'GET /sanctum/csrf-cookie';

/** Stubs global fetch. Keys are "METHOD /path". Unknown routes fail loudly. */
export function mockApi(routes: Record<string, Handler>) {
  const all: Record<string, Handler> = {
    'GET /api/v1/health': { body: { status: 'ok' } },
    [CSRF]: () => {
      document.cookie = 'XSRF-TOKEN=tok%3D123; path=/';
      return { status: 204 };
    },
    ...routes,
  };
  const calls: Req[] = [];
  const fn = vi.fn<typeof fetch>(async (input, init) => {
    const method = (init?.method ?? 'GET').toUpperCase();
    const url = String(input);
    const req: Req = {
      url,
      method,
      headers: new Headers(init?.headers),
      body: init?.body ? JSON.parse(String(init.body)) : null,
    };
    calls.push(req);
    // Exact "METHOD /path?query" first, then "METHOD /path" for any query string.
    const h = all[`${method} ${url}`] ?? all[`${method} ${url.split('?')[0]}`];
    if (!h) throw new Error(`Unmocked request: ${method} ${url}`);
    const spec = typeof h === 'function' ? await h(req) : h;
    const status = spec.status ?? 200;
    return new Response(
      status === 204 || spec.body === undefined
        ? null
        : JSON.stringify(spec.body),
      {
        status,
        headers: { 'Content-Type': 'application/json', ...spec.headers },
      },
    );
  });
  vi.stubGlobal('fetch', fn);
  return {
    fn,
    calls,
    to: (key: string) => calls.filter((c) => `${c.method} ${c.url}` === key),
  };
}

export const meOk = (user: Me) => ({ body: { data: user } });
export const guest = { status: 401, body: { message: 'Unauthenticated.' } };

function Probe() {
  const l = useLocation();
  return <div data-testid="loc">{l.pathname + l.search}</div>;
}

export function renderApp(path = '/') {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  // Data router so tests can drive history: router.navigate(-1).
  const router = createMemoryRouter(
    [
      {
        path: '*',
        element: (
          <>
            <AuthProvider>
              <AppRoutes />
            </AuthProvider>
            <Probe />
          </>
        ),
      },
    ],
    { initialEntries: [path] },
  );
  render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return { client, router };
}
