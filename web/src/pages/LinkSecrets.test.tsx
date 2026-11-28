import { readFileSync } from 'node:fs';
import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { guest, mockApi, renderApp } from '../test/utils';

const QS = '?token=secret-token-123&email=erin%40initech.test';
const pages = [
  {
    name: 'accept invitation',
    path: '/accept-invitation',
    post: 'POST /api/v1/auth/accept-invitation',
    button: 'Set password',
    invalid: /invalid or has expired/i,
  },
  {
    name: 'reset password',
    path: '/reset-password',
    post: 'POST /api/v1/auth/reset-password',
    button: 'Reset password',
    invalid: /invalid or has expired/i,
  },
] as const;

describe.each(pages)('$name: emailed-link secrets', (p) => {
  it('removes token and email from the URL after mount, keeping the form usable', async () => {
    const user = userEvent.setup();
    const api = mockApi({
      'GET /api/v1/me': guest,
      [p.post]: { body: { message: 'ok' } },
    });
    const { router } = renderApp(p.path + QS);
    await screen.findByLabelText('New password');
    await waitFor(() => expect(router.state.location.search).toBe(''));
    expect(router.state.location.pathname).toBe(p.path);
    // The router state changes a render before the DOM probe does: wait for the DOM too.
    await waitFor(() =>
      expect(screen.getByTestId('loc')).toHaveTextContent(
        new RegExp(`^${p.path}$`),
      ),
    );
    expect(JSON.stringify(router.state.location)).not.toContain('secret');

    // Submit after the URL is clean: stored values are used.
    await user.type(screen.getByLabelText('New password'), 'a-long-passw0rd');
    await user.type(
      screen.getByLabelText('Confirm new password'),
      'a-long-passw0rd',
    );
    await user.click(screen.getByRole('button', { name: p.button }));
    await waitFor(() => expect(api.to(p.post)).toHaveLength(1));
    expect(api.to(p.post)[0].body).toMatchObject({
      token: 'secret-token-123',
      email: 'erin@initech.test',
    });
  });

  it('replaces the history entry: back goes to the previous page, not the tokenised URL', async () => {
    mockApi({ 'GET /api/v1/me': guest });
    const { router } = renderApp('/login');
    await screen.findByLabelText('Email');
    await act(() => router.navigate(p.path + QS));
    await screen.findByLabelText('New password');
    await waitFor(() => expect(router.state.location.search).toBe(''));
    await act(() => router.navigate(-1));
    await waitFor(() => expect(router.state.location.pathname).toBe('/login'));
    expect(router.state.location.search).toBe('');
  });

  it('shows the invalid-link state when reloaded without the query', async () => {
    mockApi({ 'GET /api/v1/me': guest });
    renderApp(p.path);
    expect(await screen.findByRole('alert')).toHaveTextContent(p.invalid);
    expect(screen.queryByLabelText('New password')).toBeNull();
  });
});

it('index.html sets an origin-only referrer policy', () => {
  const html = readFileSync(`${process.cwd()}/index.html`, 'utf8');
  expect(html).toMatch(/<meta name="referrer" content="origin"\s*\/?>/);
});
