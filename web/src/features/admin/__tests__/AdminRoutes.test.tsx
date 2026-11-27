import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import {
  admin,
  customer,
  guest,
  meOk,
  mockApi,
  renderApp,
  staff,
} from '../../../test/utils';
import {
  AUDIT,
  COMPANIES,
  DASH,
  DASH_URL,
  ME,
  PRODUCTS,
  USERS,
  dashOk,
  loc,
  paged,
  setupAdmin,
} from './fixtures';

const adminNav = () =>
  within(screen.getByRole('navigation', { name: 'Back office' }));
const links = () =>
  adminNav()
    .getAllByRole('link')
    .map((a) => a.textContent);
const notAllowed = () => screen.findByRole('heading', { name: /not allowed/i });

describe('admin routes are guarded', () => {
  it.each(['/admin', '/admin/products', '/admin/users', '/admin/audit-log'])(
    'a guest on %s goes to the login with next',
    async (path) => {
      mockApi({ [ME]: guest });
      renderApp(path);
      await screen.findByLabelText('Email');
      expect(loc()).toHaveTextContent(
        `/login?next=${encodeURIComponent(path)}`,
      );
    },
  );

  it.each([
    '/admin',
    '/admin/products',
    '/admin/products/new',
    '/admin/companies',
    '/admin/orders',
    '/admin/users',
    '/admin/audit-log',
  ])(
    'a customer on %s sees Not allowed and no back-office nav or API call',
    async (path) => {
      const { api } = setupAdmin(path, {}, customer);
      expect(await notAllowed()).toBeInTheDocument();
      expect(
        screen.queryByRole('navigation', { name: 'Back office' }),
      ).toBeNull();
      expect(api.calls.some((c) => c.url.includes('/admin/'))).toBe(false);
    },
  );

  it('staff sees the dashboard but no Users or Audit log link', async () => {
    setupAdmin('/admin', { [DASH_URL]: dashOk });
    await screen.findByRole('heading', { name: 'Dashboard' });
    expect(links()).toEqual([
      'Dashboard',
      'Orders',
      'Quotes',
      'Invoices',
      'Products',
      'Companies',
    ]);
  });

  it.each(['/admin/users', '/admin/users/7', '/admin/audit-log'])(
    'staff on %s sees Not allowed and sends no request to it',
    async (path) => {
      const { api } = setupAdmin(path, {
        [USERS]: paged([]),
        [AUDIT]: paged([]),
      });
      expect(await notAllowed()).toBeInTheDocument();
      expect(api.calls.some((c) => /admin\/(users|audit)/.test(c.url))).toBe(
        false,
      );
      // The nav is still shown (the layout is staff level) without the admin items.
      expect(links()).not.toContain('Users');
      expect(links()).not.toContain('Audit log');
    },
  );

  it('admin sees Users and Audit log links and can open both', async () => {
    const user = userEvent.setup();
    setupAdmin(
      '/admin',
      { [DASH_URL]: dashOk, [USERS]: paged([]), [AUDIT]: paged([]) },
      admin,
    );
    await screen.findByRole('heading', { name: 'Dashboard' });
    expect(links()).toEqual([
      'Dashboard',
      'Orders',
      'Quotes',
      'Invoices',
      'Products',
      'Companies',
      'Users',
      'Audit log',
    ]);
    await user.click(adminNav().getByRole('link', { name: 'Users' }));
    expect(
      await screen.findByRole('heading', { name: 'Users' }),
    ).toBeInTheDocument();
    await user.click(adminNav().getByRole('link', { name: 'Audit log' }));
    expect(
      await screen.findByText('No audit entries yet.'),
    ).toBeInTheDocument();
  });

  it('/staff redirects to /admin', async () => {
    setupAdmin('/staff', { [DASH_URL]: dashOk });
    expect(
      await screen.findByRole('heading', { name: 'Dashboard' }),
    ).toBeInTheDocument();
    expect(loc()).toHaveTextContent(/^\/admin$/);
  });

  it('keeps unknown admin paths as the 404 page for staff', async () => {
    setupAdmin('/admin/nope', { [DASH_URL]: dashOk });
    expect(
      await screen.findByRole('heading', { name: /not found/i }),
    ).toBeInTheDocument();
  });
});

describe('role-aware top navigation', () => {
  const top = () =>
    within(screen.getByRole('navigation', { name: 'Main' }))
      .getAllByRole('link')
      .map((a) => a.textContent);

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])(
    '%s has Catalog and Back office, no Orders, Quotes or Invoices',
    async (_n, who) => {
      mockApi({ [ME]: meOk(who) });
      renderApp('/');
      await screen.findByRole('navigation', { name: 'Main' });
      expect(top()).toEqual(['Home', 'Catalog', 'Back office']);
    },
  );

  it('customers have no Back office item', async () => {
    mockApi({ [ME]: meOk(customer) });
    renderApp('/');
    await screen.findByRole('navigation', { name: 'Main' });
    expect(top()).toEqual([
      'Home',
      'Catalog',
      'Orders',
      'Quotes',
      'Invoices',
      'Cart',
    ]);
  });
});

describe('dashboard', () => {
  it('shows the values and links to the exact filtered lists', async () => {
    setupAdmin('/admin', { [DASH_URL]: dashOk });
    await screen.findByRole('link', { name: /^open quotes/i });
    const href = (name: RegExp) =>
      screen.getByRole('link', { name }).getAttribute('href');
    expect(href(/^open quotes/i)).toBe(
      '/admin/quotes?status=submitted,in_review',
    );
    expect(href(/^awaiting customer/i)).toBe('/admin/quotes?status=quoted');
    expect(href(/^quotes expiring soon/i)).toBe(
      '/admin/quotes?status=quoted&expiring_within_days=7',
    );
    expect(href(/^orders this week/i)).toBe(
      `/admin/orders?from=${DASH.week_start}`,
    );
    expect(href(/^pending orders/i)).toBe('/admin/orders?status=pending');
    expect(href(/^overdue invoices/i)).toBe('/admin/invoices?status=overdue');
    expect(href(/^invoices due soon/i)).toBe(
      '/admin/invoices?status=open&due_within_days=7',
    );
    expect(href(/^inactive products/i)).toBe('/admin/products?active=false');
    // Money comes from the body currency.
    expect(
      screen.getByRole('link', { name: /overdue invoices/i }),
    ).toHaveTextContent('$2,548.48');
    expect(screen.getByRole('link', { name: /due soon/i })).toHaveTextContent(
      '$1,200.00',
    );
    expect(
      screen.getByRole('link', { name: /^open quotes/i }),
    ).toHaveTextContent('4');
  });

  it('follows a card to the filtered, explicitly filtered list', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin', {
      [DASH_URL]: dashOk,
      [PRODUCTS]: paged([]),
    });
    await user.click(
      await screen.findByRole('link', { name: /inactive products/i }),
    );
    expect(loc()).toHaveTextContent('/admin/products?active=false');
    expect(await screen.findByLabelText('Status')).toHaveValue('false');
  });

  it('shows a skeleton while loading and a retryable error', async () => {
    let n = 0;
    setupAdmin('/admin', {
      [DASH_URL]: () => (n++ === 0 ? { status: 500, body: {} } : dashOk),
    });
    expect(
      await screen.findByRole('status', { name: /loading dashboard/i }),
    ).toBeInTheDocument();
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /try again/i }));
    expect(
      await screen.findByRole('link', { name: /pending orders/i }),
    ).toBeInTheDocument();
  });

  it('companies list is staff level too', async () => {
    setupAdmin('/admin/companies', { [COMPANIES]: paged([]) });
    expect(
      await screen.findByRole('heading', { name: 'Companies' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'New company' })).toBeNull();
  });
});
