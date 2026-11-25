import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { guest, mockApi, renderApp } from '../test/utils';

const FORGOT = 'POST /api/v1/auth/forgot-password';
const RESET = 'POST /api/v1/auth/reset-password';
const GENERIC =
  'If an account exists for that email, a reset link has been sent.';
const INVALID = 'This password reset link is invalid or has expired.';

describe('ForgotPasswordPage', () => {
  const send = () =>
    userEvent.click(screen.getByRole('button', { name: /send reset link/i }));

  it('blocks an invalid email client-side', async () => {
    const api = mockApi({ 'GET /api/v1/me': guest });
    renderApp('/forgot-password');
    await userEvent.type(await screen.findByLabelText('Email'), 'nope');
    await send();
    expect(await screen.findByText(/valid email/i)).toHaveAttribute(
      'role',
      'alert',
    );
    expect(api.to(FORGOT)).toHaveLength(0);
  });

  it('shows the generic message on 200', async () => {
    const api = mockApi({
      'GET /api/v1/me': guest,
      [FORGOT]: { body: { message: GENERIC } },
    });
    renderApp('/forgot-password');
    await userEvent.type(await screen.findByLabelText('Email'), 'ghost@x.test');
    await send();
    expect(
      await screen.findByRole('status', { name: /reset link/i }),
    ).toHaveTextContent(GENERIC);
    expect(api.to(FORGOT)[0].body).toEqual({ email: 'ghost@x.test' });
  });

  it('handles 429', async () => {
    mockApi({
      'GET /api/v1/me': guest,
      [FORGOT]: {
        status: 429,
        headers: { 'Retry-After': '30' },
        body: { message: 'x' },
      },
    });
    renderApp('/forgot-password');
    await userEvent.type(await screen.findByLabelText('Email'), 'a@x.test');
    await send();
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /too many attempts.*30 seconds/i,
    );
  });
});

describe('ResetPasswordPage', () => {
  const url = '/reset-password?token=abc123&email=alice%40northwind.test';
  async function submit(pw: string, confirm: string) {
    await userEvent.type(await screen.findByLabelText('New password'), pw);
    await userEvent.type(
      screen.getByLabelText('Confirm new password'),
      confirm,
    );
    await userEvent.click(
      screen.getByRole('button', { name: /reset password/i }),
    );
  }

  it.each([
    '/reset-password',
    '/reset-password?token=abc',
    '/reset-password?email=a%40x.test',
  ])(
    'shows an invalid-link message when token or email is missing (%s)',
    async (path) => {
      mockApi({ 'GET /api/v1/me': guest });
      renderApp(path);
      expect(await screen.findByRole('alert')).toHaveTextContent(
        /invalid or has expired/i,
      );
      expect(screen.queryByLabelText('New password')).toBeNull();
      expect(
        screen.getByRole('link', { name: /request a new link/i }),
      ).toHaveAttribute('href', '/forgot-password');
    },
  );

  it('blocks short passwords and mismatches client-side', async () => {
    const api = mockApi({ 'GET /api/v1/me': guest });
    renderApp(url);
    await submit('short', 'short');
    expect(
      await screen.findByText(/at least 10 characters/i),
    ).toBeInTheDocument();
    await userEvent.clear(screen.getByLabelText('New password'));
    await userEvent.clear(screen.getByLabelText('Confirm new password'));
    await submit('long-enough-1', 'long-enough-2');
    expect(await screen.findByText(/do not match/i)).toBeInTheDocument();
    expect(api.to(RESET)).toHaveLength(0);
  });

  it('posts token, email and passwords, then links to /login', async () => {
    const api = mockApi({
      'GET /api/v1/me': guest,
      [RESET]: { body: { message: 'Your password has been reset.' } },
    });
    renderApp(url);
    await submit('New-Passw0rd-123', 'New-Passw0rd-123');
    expect(
      await screen.findByRole('status', { name: /password reset/i }),
    ).toHaveTextContent(/has been reset/i);
    expect(api.to(RESET)[0].body).toEqual({
      token: 'abc123',
      email: 'alice@northwind.test',
      password: 'New-Passw0rd-123',
      password_confirmation: 'New-Passw0rd-123',
    });
    expect(screen.getByRole('link', { name: /sign in/i })).toHaveAttribute(
      'href',
      '/login',
    );
  });

  it('shows errors.email[0] on a 422 invalid-link response', async () => {
    mockApi({
      'GET /api/v1/me': guest,
      [RESET]: {
        status: 422,
        body: { message: INVALID, errors: { email: [INVALID] } },
      },
    });
    renderApp(url);
    await submit('New-Passw0rd-123', 'New-Passw0rd-123');
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(INVALID);
    expect(alert).toHaveFocus();
  });

  it('handles 429', async () => {
    mockApi({
      'GET /api/v1/me': guest,
      [RESET]: {
        status: 429,
        headers: { 'Retry-After': '9' },
        body: { message: 'x' },
      },
    });
    renderApp(url);
    await submit('New-Passw0rd-123', 'New-Passw0rd-123');
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /too many attempts.*9 seconds/i,
    );
  });
});
