import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import {
  CSRF,
  admin,
  customer,
  guest,
  meOk,
  mockApi,
  renderApp,
  staff,
} from '../test/utils';

const ME = 'GET /api/v1/me';
const loc = () => screen.getByTestId('loc');
const customerHome = () =>
  screen.findByRole('heading', { name: /customer home/i });

describe('protected routes', () => {
  it('redirects a guest from / to /login with next=/', async () => {
    mockApi({ [ME]: guest });
    renderApp('/');
    expect(await screen.findByLabelText('Email')).toBeInTheDocument();
    const url = new URL(loc().textContent!, 'http://x');
    expect(url.pathname).toBe('/login');
    expect(url.searchParams.get('next')).toBe('/');
  });

  it('keeps the original path and query in next', async () => {
    mockApi({ [ME]: guest });
    renderApp('/staff?tab=1');
    await screen.findByLabelText('Email');
    const url = new URL(loc().textContent!, 'http://x');
    expect(url.searchParams.get('next')).toBe('/staff?tab=1');
  });

  it('shows a loading state, not protected content, while /me is pending', () => {
    mockApi({ [ME]: () => new Promise(() => {}) });
    renderApp('/');
    expect(
      screen.getByRole('status', { name: /loading/i }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: /home/i })).toBeNull();
    expect(screen.queryByLabelText('Email')).toBeNull();
  });

  it('keeps the session on refresh: /me 200 shows the user without /login', async () => {
    mockApi({ [ME]: meOk(customer) });
    renderApp('/');
    expect(await customerHome()).toBeInTheDocument();
    expect(loc()).toHaveTextContent(/^\/$/);
  });

  it('shows an error with retry on /me 500, not the login page', async () => {
    let n = 0;
    mockApi({
      [ME]: () => (n++ === 0 ? { status: 500, body: {} } : meOk(customer)),
    });
    renderApp('/');
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /could not load your session/i,
    );
    expect(screen.queryByLabelText('Email')).toBeNull();
    await userEvent.click(screen.getByRole('button', { name: /retry/i }));
    expect(await customerHome()).toBeInTheDocument();
  });

  it('redirects a logged-in user from /login to /', async () => {
    mockApi({ [ME]: meOk(customer) });
    renderApp('/login');
    await customerHome();
    expect(loc()).toHaveTextContent(/^\/$/);
  });

  it('redirects logged-in users away from forgot-password too', async () => {
    mockApi({ [ME]: meOk(customer) });
    renderApp('/forgot-password');
    await customerHome();
  });
});

describe('role-based navigation', () => {
  const links = () =>
    within(screen.getByRole('navigation', { name: /main/i }))
      .getAllByRole('link')
      .map((a) => a.textContent);

  it('customer sees Home only', async () => {
    mockApi({ [ME]: meOk(customer) });
    renderApp('/');
    await screen.findByRole('navigation', { name: /main/i });
    expect(links()).toEqual(['Home']);
    expect(screen.queryByText('Staff area')).toBeNull();
    expect(screen.queryByText('Admin area')).toBeNull();
  });

  it('staff sees Home and Staff area, no Admin area', async () => {
    mockApi({ [ME]: meOk(staff) });
    renderApp('/');
    await screen.findByRole('navigation', { name: /main/i });
    expect(links()).toEqual(['Home', 'Staff area']);
    expect(screen.queryByText('Admin area')).toBeNull();
  });

  it('admin sees all three', async () => {
    mockApi({ [ME]: meOk(admin) });
    renderApp('/');
    await screen.findByRole('navigation', { name: /main/i });
    expect(links()).toEqual(['Home', 'Staff area', 'Admin area']);
  });
});

describe('header', () => {
  it('shows name, role and company for customers', async () => {
    mockApi({ [ME]: meOk(customer) });
    renderApp('/');
    const header = await screen.findByRole('banner');
    await within(header).findByText('Alice Customer');
    expect(header).toHaveTextContent('customer');
    expect(header).toHaveTextContent('Northwind Machinery Ltd');
  });

  it('shows no company for staff and admin', async () => {
    mockApi({ [ME]: meOk(staff) });
    renderApp('/');
    const header = await screen.findByRole('banner');
    await within(header).findByText('Sam Staff');
    expect(header).toHaveTextContent('staff');
    expect(screen.queryByText(/northwind/i)).toBeNull();
    expect(screen.queryByTestId('company')).toBeNull();
  });
});

describe('role guards (403 page)', () => {
  it('customer on /staff sees Not allowed, never the page or its API call', async () => {
    const api = mockApi({ [ME]: meOk(customer) });
    renderApp('/staff');
    expect(
      await screen.findByRole('heading', { name: /not allowed/i }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: /staff home/i })).toBeNull();
    expect(api.to('GET /api/v1/staff/ping')).toHaveLength(0);
    expect(loc()).toHaveTextContent('/staff');
  });

  it('customer on /admin sees Not allowed', async () => {
    mockApi({ [ME]: meOk(customer) });
    renderApp('/admin');
    expect(
      await screen.findByRole('heading', { name: /not allowed/i }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: /admin area/i })).toBeNull();
  });

  it('staff on /admin sees Not allowed but can open /staff', async () => {
    mockApi({
      [ME]: meOk(staff),
      'GET /api/v1/staff/ping': { body: { status: 'ok' } },
    });
    renderApp('/admin');
    expect(
      await screen.findByRole('heading', { name: /not allowed/i }),
    ).toBeInTheDocument();
    await userEvent.click(screen.getByRole('link', { name: 'Staff area' }));
    expect(
      await screen.findByRole('heading', { name: /staff home/i }),
    ).toBeInTheDocument();
  });

  it('admin can open /admin and /staff', async () => {
    mockApi({
      [ME]: meOk(admin),
      'GET /api/v1/staff/ping': { body: { status: 'ok' } },
      'GET /api/v1/admin/ping': { body: { status: 'ok' } },
    });
    renderApp('/admin');
    expect(
      await screen.findByRole('heading', { name: /admin area/i }),
    ).toBeInTheDocument();
    expect(await screen.findByText(/admin api: ok/i)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('link', { name: 'Staff area' }));
    expect(
      await screen.findByRole('heading', { name: /staff home/i }),
    ).toBeInTheDocument();
  });

  it('shows an error state when the staff ping fails with 500', async () => {
    mockApi({
      [ME]: meOk(staff),
      'GET /api/v1/staff/ping': { status: 500, body: {} },
    });
    renderApp('/staff');
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /could not load/i,
    );
  });
});

describe('session handling', () => {
  it('a 401 on a later API call clears auth and goes to /login?next=', async () => {
    mockApi({ [ME]: meOk(staff), 'GET /api/v1/staff/ping': guest });
    renderApp('/staff');
    await screen.findByLabelText('Email');
    const url = new URL(loc().textContent!, 'http://x');
    expect(url.pathname).toBe('/login');
    expect(url.searchParams.get('next')).toBe('/staff');
  });

  it('logout posts, refreshes csrf, goes to /login and re-guards', async () => {
    const api = mockApi({
      [ME]: meOk(customer),
      'POST /api/v1/auth/logout': { status: 204 },
    });
    renderApp('/');
    await customerHome();
    await userEvent.click(screen.getByRole('button', { name: /log out/i }));
    expect(await screen.findByLabelText('Email')).toBeInTheDocument();
    await waitFor(() => expect(loc()).toHaveTextContent(/^\/login/));
    const order = api.calls.map((c) => `${c.method} ${c.url}`);
    const i = order.indexOf('POST /api/v1/auth/logout');
    expect(i).toBeGreaterThan(-1);
    expect(api.calls[i].headers.get('X-XSRF-TOKEN')).toBe('tok=123');
    expect(order.slice(i + 1)).toContain(CSRF);
    expect(screen.queryByRole('navigation', { name: /main/i })).toBeNull();
  });

  it('logout clears cached server state', async () => {
    mockApi({
      [ME]: meOk(customer),
      'POST /api/v1/auth/logout': { status: 204 },
    });
    const { client } = renderApp('/');
    await customerHome();
    client.setQueryData(['orders'], [1, 2, 3]);
    await userEvent.click(screen.getByRole('button', { name: /log out/i }));
    await screen.findByLabelText('Email');
    expect(client.getQueryData(['orders'])).toBeUndefined();
  });
});

describe('health status', () => {
  it('stays visible in the footer', async () => {
    mockApi({ [ME]: guest });
    renderApp('/login');
    const footer = await screen.findByRole('contentinfo');
    expect(
      await within(footer).findByText('API status: ok'),
    ).toBeInTheDocument();
  });
});
