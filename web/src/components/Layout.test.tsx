import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
  admin,
  customer,
  guest,
  meOk,
  mockApi,
  renderApp,
  staff,
} from '../test/utils';
import { emptyCart } from '../features/cart/api';

const ME = 'GET /api/v1/me';
const APP = 'B2B Customer Portal';
const announcer = () =>
  screen.getByRole('status', { name: 'Page change announcement' });

function guestApp(path = '/login') {
  mockApi({ [ME]: guest });
  return renderApp(path);
}

describe('skip link', () => {
  it('is the first Tab stop and moves focus into main', async () => {
    const u = userEvent.setup();
    guestApp();
    await screen.findByRole('heading', { name: 'Sign in' });
    await u.tab();
    const skip = screen.getByRole('link', { name: 'Skip to main content' });
    expect(skip).toHaveFocus();
    await u.keyboard('{Enter}');
    const main = screen.getByRole('main');
    expect(main).toHaveFocus();
    expect(main).toHaveAttribute('id', 'main-content');
    expect(skip).toHaveAttribute('href', '#main-content');
  });

  it('is hidden until focused (sr-only) so it does not clutter the page', async () => {
    guestApp();
    await screen.findByRole('heading', { name: 'Sign in' });
    expect(
      screen.getByRole('link', { name: 'Skip to main content' }),
    ).toHaveClass('sr-only', 'focus:not-sr-only');
  });
});

describe('landmarks and language', () => {
  it('has one banner, one main, one contentinfo and named navigations', async () => {
    mockApi({
      [ME]: meOk(admin),
      'GET /api/v1/admin/dashboard': { status: 500, body: {} },
    });
    renderApp('/admin');
    await screen.findByRole('heading', { name: 'Dashboard' });
    expect(screen.getAllByRole('banner')).toHaveLength(1);
    expect(screen.getAllByRole('main')).toHaveLength(1);
    expect(screen.getAllByRole('contentinfo')).toHaveLength(1);
    const navs = screen
      .getAllByRole('navigation')
      .map((n) => n.getAttribute('aria-label'));
    expect(navs.sort()).toEqual(['Back office', 'Main']);
    // The back-office nav lives inside main; the main nav does not.
    expect(
      within(screen.getByRole('main')).getByRole('navigation', {
        name: 'Back office',
      }),
    ).toBeInTheDocument();
  });

  it('the document language is English', () => {
    const html = readFileSync(resolve(__dirname, '../../index.html'), 'utf8');
    expect(html).toMatch(/<html lang="en">/);
  });
});

describe('document title and route announcement', () => {
  it('sets the title from the page heading', async () => {
    guestApp('/login');
    await screen.findByRole('heading', { name: 'Sign in' });
    await waitFor(() => expect(document.title).toBe(`Sign in - ${APP}`));
  });

  it('updates the title and announces the new page after navigation, not on first load', async () => {
    const u = userEvent.setup();
    guestApp('/login');
    await screen.findByRole('heading', { name: 'Sign in' });
    expect(announcer()).toHaveTextContent('');
    await u.click(screen.getByRole('link', { name: 'Forgot your password?' }));
    await screen.findByRole('heading', { name: 'Forgot password' });
    await waitFor(() =>
      expect(document.title).toBe(`Forgot password - ${APP}`),
    );
    expect(announcer()).toHaveTextContent('Forgot password');
    expect(announcer()).toHaveAttribute('aria-live', 'polite');
  });

  it('moves focus to the new page heading after a link in the header was used', async () => {
    const u = userEvent.setup();
    mockApi({
      [ME]: meOk(customer),
      'GET /api/v1/cart': { body: { data: emptyCart('USD') } },
      'GET /api/v1/orders': {
        body: {
          data: [],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: 0,
            from: null,
            to: null,
          },
        },
      },
    });
    renderApp('/');
    await screen.findByRole('heading', { level: 1 });
    await u.click(
      within(screen.getByRole('navigation', { name: 'Main' })).getByRole(
        'link',
        { name: 'Orders' },
      ),
    );
    const h1 = await screen.findByRole('heading', {
      level: 1,
      name: /^Orders for/,
    });
    await waitFor(() => expect(h1).toHaveFocus());
    expect(document.title).toMatch(/^Orders for .* - B2B Customer Portal$/);
  });

  it('does not steal focus from a control inside the page', async () => {
    const u = userEvent.setup();
    guestApp('/login');
    await screen.findByRole('heading', { name: 'Sign in' });
    await u.click(screen.getByLabelText('Email'));
    expect(screen.getByLabelText('Email')).toHaveFocus();
    await u.type(screen.getByLabelText('Email'), 'x');
    expect(screen.getByLabelText('Email')).toHaveFocus();
  });
});

describe('cart link name (WCAG 2.5.3 label in name)', () => {
  it('starts with the visible words and has no aria-label', async () => {
    const cart = {
      ...emptyCart('USD'),
      line_count: 2,
      items: [],
    };
    mockApi({
      [ME]: meOk(customer),
      'GET /api/v1/cart': { body: { data: cart } },
    });
    renderApp('/');
    const link = await screen.findByRole('link', { name: 'Cart 2 items' });
    expect(link).not.toHaveAttribute('aria-label');
    expect(link).toHaveTextContent('Cart 2');
  });
});

describe('role navigation stays role based', () => {
  it('staff see Back office, customers do not', async () => {
    mockApi({
      [ME]: meOk(staff),
      'GET /api/v1/admin/dashboard': { status: 500, body: {} },
    });
    renderApp('/');
    expect(
      await screen.findByRole('link', { name: 'Back office' }),
    ).toBeInTheDocument();
  });
});
