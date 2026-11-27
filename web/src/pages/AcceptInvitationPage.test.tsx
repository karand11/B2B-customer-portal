import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { customer, guest, meOk, mockApi, renderApp } from '../test/utils';

const ME = 'GET /api/v1/me';
const ACCEPT = 'POST /api/v1/auth/accept-invitation';
const PATH =
  '/accept-invitation?token=secret-token-123&email=erin%40initech.test';
const GENERIC = 'This invitation link is invalid or has expired.';

async function fill(
  user: ReturnType<typeof userEvent.setup>,
  pw = 'a-long-passw0rd',
  conf = pw,
) {
  await user.type(await screen.findByLabelText('New password'), pw);
  await user.type(screen.getByLabelText('Confirm new password'), conf);
  await user.click(screen.getByRole('button', { name: 'Set password' }));
}

describe('accept invitation page', () => {
  it('posts token, email and the passwords, then sends the user to login with a status message', async () => {
    const user = userEvent.setup();
    const api = mockApi({
      [ME]: guest,
      [ACCEPT]: {
        body: { message: 'Your account is ready. You can sign in now.' },
      },
    });
    renderApp(PATH);
    await fill(user);
    await waitFor(() => expect(api.to(ACCEPT)).toHaveLength(1));
    expect(api.to(ACCEPT)[0].body).toEqual({
      token: 'secret-token-123',
      email: 'erin@initech.test',
      password: 'a-long-passw0rd',
      password_confirmation: 'a-long-passw0rd',
    });
    expect(api.to(ACCEPT)[0].headers.get('X-XSRF-TOKEN')).toBe('tok=123');
    expect(await screen.findByLabelText('Email')).toBeInTheDocument();
    expect(screen.getByTestId('loc')).toHaveTextContent(/^\/login$/);
    expect(
      screen.getByText('Your account is ready. You can sign in now.'),
    ).toBeInTheDocument();
  });

  it('has labelled fields, never shows the token and enforces the password rules before sending', async () => {
    const user = userEvent.setup();
    const api = mockApi({ [ME]: guest });
    renderApp(PATH);
    expect(
      await screen.findByRole('heading', { name: 'Accept invitation' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('main')).not.toHaveTextContent('secret-token-123');
    expect(screen.getByLabelText('New password')).toHaveAttribute(
      'type',
      'password',
    );
    await fill(user, 'short', 'short');
    expect(
      await screen.findByText('Password must be at least 10 characters'),
    ).toBeInTheDocument();
    await user.clear(screen.getByLabelText('New password'));
    await user.clear(screen.getByLabelText('Confirm new password'));
    await user.type(screen.getByLabelText('New password'), 'a-long-passw0rd');
    await user.type(
      screen.getByLabelText('Confirm new password'),
      'different-passw0rd',
    );
    await user.click(screen.getByRole('button', { name: 'Set password' }));
    expect(
      await screen.findByText('Passwords do not match'),
    ).toBeInTheDocument();
    expect(api.to(ACCEPT)).toHaveLength(0);
  });

  it.each(['unknown', 'expired'])(
    'shows the same generic message for a %s link',
    async () => {
      const user = userEvent.setup();
      mockApi({
        [ME]: guest,
        [ACCEPT]: {
          status: 422,
          body: { message: GENERIC, errors: { email: [GENERIC] } },
        },
      });
      renderApp(PATH);
      await fill(user);
      const alert = await screen.findByRole('alert');
      expect(alert).toHaveTextContent(GENERIC);
      // Stays on the page so the user can read it; nothing else is revealed.
      expect(screen.getByTestId('loc')).toHaveTextContent('/accept-invitation');
    },
  );

  it('shows the wait time on a 429', async () => {
    const user = userEvent.setup();
    mockApi({
      [ME]: guest,
      [ACCEPT]: { status: 429, body: {}, headers: { 'Retry-After': '20' } },
    });
    renderApp(PATH);
    await fill(user);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'try again in 20 seconds',
    );
  });

  it.each([
    '/accept-invitation',
    '/accept-invitation?token=abc',
    '/accept-invitation?email=a%40b.test',
  ])(
    'shows the generic message and no form when the link is incomplete (%s)',
    async (path) => {
      const api = mockApi({ [ME]: guest });
      renderApp(path);
      expect(await screen.findByRole('alert')).toHaveTextContent(GENERIC);
      expect(screen.queryByLabelText('New password')).toBeNull();
      expect(api.to(ACCEPT)).toHaveLength(0);
    },
  );

  it('is a guest-only route: a logged-in user is sent home', async () => {
    mockApi({ [ME]: meOk(customer) });
    renderApp(PATH);
    expect(
      await screen.findByRole('heading', { name: /customer home/i }),
    ).toBeInTheDocument();
  });
});
