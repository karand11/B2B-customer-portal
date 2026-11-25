import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import HealthStatus from './HealthStatus';

function renderIt() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <HealthStatus />
    </QueryClientProvider>,
  );
}

function mockFetch(impl: typeof fetch) {
  const fn = vi.fn<typeof fetch>(impl);
  vi.stubGlobal('fetch', fn);
  return fn;
}

afterEach(() => vi.unstubAllGlobals());

describe('HealthStatus', () => {
  it('shows a loading state while the request is pending', () => {
    mockFetch(() => new Promise(() => {}));
    renderIt();
    expect(screen.getByRole('status')).toHaveTextContent(/checking/i);
  });

  it('shows "API status: ok" on success and calls /api/v1/health', async () => {
    const fetchFn = mockFetch(() =>
      Promise.resolve(Response.json({ status: 'ok' })),
    );
    renderIt();
    expect(await screen.findByText('API status: ok')).toBeInTheDocument();
    expect(fetchFn.mock.calls[0][0]).toBe('/api/v1/health');
  });

  it('shows an error when the request fails', async () => {
    mockFetch(() => Promise.reject(new TypeError('network down')));
    renderIt();
    expect(await screen.findByRole('alert')).toHaveTextContent(/unavailable/i);
  });

  it('shows an error on a non-200 response', async () => {
    mockFetch(() => Promise.resolve(new Response('boom', { status: 500 })));
    renderIt();
    expect(await screen.findByRole('alert')).toHaveTextContent(/unavailable/i);
  });

  it('shows an error when the body is not {status: "ok"}', async () => {
    mockFetch(() => Promise.resolve(Response.json({ status: 'down' })));
    renderIt();
    expect(await screen.findByRole('alert')).toBeInTheDocument();
  });
});
