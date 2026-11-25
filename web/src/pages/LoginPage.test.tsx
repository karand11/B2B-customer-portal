import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { CSRF, customer, guest, meOk, mockApi, renderApp } from '../test/utils';

const LOGIN = 'POST /api/v1/auth/login';

async function fill(email: string, password: string) {
  const user = userEvent.setup();
  if (email) await user.type(screen.getByLabelText('Email'), email);
  if (password) await user.type(screen.getByLabelText('Password'), password);
  await user.click(screen.getByRole('button', { name: /sign in/i }));
}

describe('LoginPage validation', () => {
  it('blocks an empty submit with required errors and no API call', async () => {
    const api = mockApi({ 'GET /api/v1/me': guest });
    renderApp('/login');
    await screen.findByLabelText('Email');
    await fill('', '');
    expect(await screen.findByText('Email is required')).toBeInTheDocument();
    expect(screen.getByText('Password is required')).toBeInTheDocument();
    expect(api.to(LOGIN)).toHaveLength(0);
    expect(api.to(CSRF)).toHaveLength(0);
  });

  it('rejects a malformed email and announces errors accessibly', async () => {
    const api = mockApi({ 'GET /api/v1/me': guest });
    renderApp('/login');
    await screen.findByLabelText('Email');
    await fill('not-an-email', 'whatever');
    const msg = await screen.findByText(/valid email/i);
    expect(msg).toHaveAttribute('role', 'alert');
    const input = screen.getByLabelText('Email');
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input.getAttribute('aria-describedby')).toBe(msg.id);
    expect(api.to(LOGIN)).toHaveLength(0);
  });

  it('has labelled fields, a forgot link and no register link', async () => {
    mockApi({ 'GET /api/v1/me': guest });
    renderApp('/login');
    expect(await screen.findByLabelText('Email')).toBeInTheDocument();
    expect(screen.getByLabelText('Password')).toHaveAttribute(
      'type',
      'password',
    );
    expect(screen.queryByText(/register|sign up/i)).toBeNull();
    expect(
      screen.getByRole('link', { name: /forgot your password/i }),
    ).toHaveAttribute('href', '/forgot-password');
  });
});

describe('LoginPage submit', () => {
  it('fetches csrf cookie, posts with X-XSRF-TOKEN, stores user, goes to /', async () => {
    const api = mockApi({
      'GET /api/v1/me': guest,
      [LOGIN]: meOk(customer),
    });
    renderApp('/login');
    await screen.findByLabelText('Email');
    await fill('alice@northwind.test', 'Demo-Passw0rd!');
    await waitFor(() =>
      expect(screen.getByTestId('loc')).toHaveTextContent(/^\/$/),
    );
    const order = api.calls.map((c) => `${c.method} ${c.url}`);
    expect(order.indexOf(CSRF)).toBeGreaterThan(-1);
    expect(order.indexOf(CSRF)).toBeLessThan(order.indexOf(LOGIN));
    const req = api.to(LOGIN)[0];
    expect(req.headers.get('X-XSRF-TOKEN')).toBe('tok=123');
    expect(req.body).toEqual({
      email: 'alice@northwind.test',
      password: 'Demo-Passw0rd!',
    });
    expect(await screen.findByRole('banner')).toHaveTextContent(
      'Alice Customer',
    );
  });

  it('honours a safe next path', async () => {
    mockApi({ 'GET /api/v1/me': guest, [LOGIN]: meOk(customer) });
    renderApp('/login?next=%2Forders%3Fpage%3D2');
    await screen.findByLabelText('Email');
    await fill('alice@northwind.test', 'pw');
    await waitFor(() =>
      expect(screen.getByTestId('loc')).toHaveTextContent('/orders?page=2'),
    );
  });

  it.each([
    'https%3A%2F%2Fevil.example',
    '%2F%2Fevil.example',
    '%2F%5Cevil',
    '%2F%09%2Fevil.example',
    '%2F%0A%2Fevil.example',
  ])('ignores unsafe next %s', async (next) => {
    mockApi({ 'GET /api/v1/me': guest, [LOGIN]: meOk(customer) });
    renderApp(`/login?next=${next}`);
    await screen.findByLabelText('Email');
    await fill('alice@northwind.test', 'pw');
    await waitFor(() =>
      expect(screen.getByTestId('loc')).toHaveTextContent(/^\/$/),
    );
  });

  it('shows errors.email[0] from a 422 and focuses the alert', async () => {
    mockApi({
      'GET /api/v1/me': guest,
      [LOGIN]: {
        status: 422,
        body: {
          message: 'These credentials do not match our records.',
          errors: { email: ['These credentials do not match our records.'] },
        },
      },
    });
    renderApp('/login');
    await screen.findByLabelText('Email');
    await fill('alice@northwind.test', 'wrong-password');
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('These credentials do not match');
    expect(alert).toHaveFocus();
    expect(screen.getByTestId('loc')).toHaveTextContent('/login');
  });

  it('shows a rate-limit message with seconds from Retry-After on 429', async () => {
    mockApi({
      'GET /api/v1/me': guest,
      [LOGIN]: {
        status: 429,
        headers: { 'Retry-After': '42' },
        body: { message: 'Too many attempts. Please try again in 42 seconds.' },
      },
    });
    renderApp('/login');
    await screen.findByLabelText('Email');
    await fill('alice@northwind.test', 'pw');
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/too many attempts/i);
    expect(alert).toHaveTextContent('42 seconds');
  });

  it('shows a generic message on a network error', async () => {
    mockApi({
      'GET /api/v1/me': guest,
      [LOGIN]: () => {
        throw new TypeError('network down');
      },
    });
    renderApp('/login');
    await screen.findByLabelText('Email');
    await fill('alice@northwind.test', 'pw');
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /could not reach the server/i,
    );
  });

  it('disables the submit button while pending', async () => {
    mockApi({
      'GET /api/v1/me': guest,
      [LOGIN]: () => new Promise(() => {}),
    });
    renderApp('/login');
    await screen.findByLabelText('Email');
    await fill('alice@northwind.test', 'pw');
    expect(
      await screen.findByRole('button', { name: /signing in/i }),
    ).toBeDisabled();
  });
});
