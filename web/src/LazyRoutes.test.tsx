import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';
import {
  admin,
  customer,
  guest,
  meOk,
  mockApi,
  renderApp,
  staff,
} from './test/utils';
import { emptyCart } from './features/cart/api';

// Hold two lazy route modules back so the loading and failure states can be observed.
const gates = vi.hoisted(() => {
  const make = () => {
    let resolve!: () => void;
    let reject!: (e: Error) => void;
    const promise = new Promise<void>((res, rej) => {
      resolve = res;
      reject = rej;
    });
    promise.catch(() => {}); // rejected before anything awaits it: not an unhandled error
    return { promise, resolve, reject };
  };
  return { invoices: make(), quotes: make() };
});
vi.mock('./features/invoices/InvoicesPage', async (importOriginal) => {
  await gates.invoices.promise;
  return importOriginal();
});
vi.mock('./features/quotes/QuoteFormPage', async (importOriginal) => {
  await gates.quotes.promise;
  return importOriginal();
});

const ME = 'GET /api/v1/me';
const empty = {
  data: [],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 0,
    from: null,
    to: null,
  },
};
const api = (me: unknown) =>
  mockApi({
    [ME]: me as never,
    'GET /api/v1/cart': { body: { data: emptyCart('USD') } },
    'GET /api/v1/invoices': { body: empty },
    'GET /api/v1/quotes': { body: empty },
  });

describe('lazy routes', () => {
  it('shows an accessible loading state inside the page frame while a route chunk loads, then the page', async () => {
    api(meOk(customer));
    renderApp('/invoices');
    const loading = await screen.findByRole('status', { name: 'Loading page' });
    expect(loading).toHaveTextContent('Loading');
    // The frame (skip link, navigation, footer) is already there.
    expect(
      screen.getByRole('link', { name: 'Skip to main content' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('navigation', { name: 'Main' }),
    ).toBeInTheDocument();
    gates.invoices.resolve();
    expect(
      await screen.findByRole('heading', { name: /^Invoices/ }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('status', { name: 'Loading page' })).toBeNull();
  });

  it('a chunk that fails to load shows a clear error with a way to reload, not a blank page', async () => {
    const u = userEvent.setup();
    api(meOk(customer));
    renderApp('/quotes/new');
    gates.quotes.reject(
      new Error('Failed to fetch dynamically imported module'),
    );
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/could not load this page/i);
    expect(
      screen.getByRole('button', { name: 'Reload page' }),
    ).toBeInTheDocument();
    // The frame still works: navigating elsewhere clears the error.
    await u.click(screen.getByRole('link', { name: 'Home' }));
    expect(
      await screen.findByRole('heading', { level: 1 }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('alert')).toBeNull();
  });
});

describe('guards still decide before any lazy page renders', () => {
  it('a guest is sent to the login page, never into a lazy route', async () => {
    mockApi({ [ME]: guest });
    renderApp('/quotes/new');
    expect(
      await screen.findByRole('heading', { name: 'Sign in' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('status', { name: 'Loading page' })).toBeNull();
  });

  it('a customer on a back-office route gets Not allowed', async () => {
    api(meOk(customer));
    renderApp('/admin/products');
    expect(
      await screen.findByRole('heading', { name: 'Not allowed' }),
    ).toBeInTheDocument();
  });

  it('staff reach the back office but not the admin-only pages', async () => {
    mockApi({
      [ME]: meOk(staff),
      'GET /api/v1/admin/dashboard': { status: 500, body: {} },
    });
    renderApp('/admin/users');
    expect(
      await screen.findByRole('heading', { name: 'Not allowed' }),
    ).toBeInTheDocument();
  });

  it('an admin renders the lazy admin-only page', async () => {
    mockApi({
      [ME]: meOk(admin),
      'GET /api/v1/admin/audit-log': {
        body: { ...empty, meta: { ...empty.meta, per_page: 25 } },
      },
      'GET /api/v1/admin/users': {
        body: { ...empty, meta: { ...empty.meta, per_page: 25 } },
      },
    });
    renderApp('/admin/users');
    expect(
      await screen.findByRole('heading', { name: 'Users' }),
    ).toBeInTheDocument();
  });

  it('an unknown back-office path is the 404 page', async () => {
    mockApi({ [ME]: meOk(admin) });
    renderApp('/admin/nope');
    expect(
      await screen.findByRole('heading', { name: 'Page not found' }),
    ).toBeInTheDocument();
  });
});
