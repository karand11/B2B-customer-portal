import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { admin } from '../../../test/utils';
import { listOf as orderList, order } from '../../cart/__tests__/fixtures';
import {
  INV_ID,
  INV_URL,
  INVOICES,
  detail as invoiceDetail,
  invoice,
  listOfInvoices,
} from '../../invoices/__tests__/fixtures';
import {
  DETAIL as QUOTE_URL,
  LIST as QUOTES,
  detail as quoteDetail,
  listOf as quoteList,
  NUM,
  quote,
} from '../../quotes/__tests__/fixtures';
import { COMPANIES, company, loc, paged, setupAdmin } from './fixtures';

const ORDERS = 'GET /api/v1/orders';
const calls = (api: { calls: { url: string }[] }, prefix: string) =>
  api.calls
    .map((c) => c.url)
    .filter((u) => u === prefix || u.startsWith(`${prefix}?`));

describe('admin orders list', () => {
  const o = order();
  it('redirects an unfiltered URL once to the explicit default and requests it', async () => {
    const { api } = setupAdmin('/admin/orders', {
      [ORDERS]: { body: orderList([o]) },
    });
    await screen.findByRole('table', { name: 'Orders' });
    expect(loc()).toHaveTextContent(
      '/admin/orders?status=pending&sort=created_at',
    );
    expect(calls(api, '/api/v1/orders')).toEqual([
      '/api/v1/orders?status=pending&sort=created_at',
    ]);
    // The default is visible state, not hidden: the Pending box is ticked.
    expect(screen.getByRole('checkbox', { name: 'Pending' })).toBeChecked();
  });

  it('keeps an explicit URL as it is and clearing the filters is not redirected back', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/orders?status=shipped&q=K7', {
      [ORDERS]: { body: orderList([o]) },
    });
    await screen.findByRole('table', { name: 'Orders' });
    expect(loc()).toHaveTextContent('/admin/orders?status=shipped&q=K7');
    await user.click(screen.getByRole('button', { name: 'Clear filters' }));
    await waitFor(() => expect(loc()).toHaveTextContent(/^\/admin\/orders$/));
    await screen.findByRole('table', { name: 'Orders' });
    expect(loc()).toHaveTextContent(/^\/admin\/orders$/);
  });

  it('links rows and companies inside the back office', async () => {
    setupAdmin('/admin/orders?status=pending', {
      [ORDERS]: { body: orderList([o]) },
    });
    const t = within(await screen.findByRole('table', { name: 'Orders' }));
    expect(t.getByRole('link', { name: o.number })).toHaveAttribute(
      'href',
      `/admin/orders/${o.number}`,
    );
    expect(
      t.getByRole('link', { name: 'Northwind Machinery Ltd' }),
    ).toHaveAttribute('href', '/admin/companies/1');
  });

  it('the company filter puts company_id in the URL and the request', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/orders?status=pending', {
      [ORDERS]: { body: orderList([o]) },
      [COMPANIES]: paged([company()]),
      'GET /api/v1/admin/companies/1': { body: { data: company() } },
    });
    await screen.findByRole('table', { name: 'Orders' });
    await user.type(screen.getByLabelText('Company'), 'north');
    await user.click(
      await screen.findByRole('button', { name: 'Northwind Machinery Ltd' }),
    );
    await waitFor(() =>
      expect(loc()).toHaveTextContent(
        '/admin/orders?status=pending&company_id=1',
      ),
    );
    await waitFor(() =>
      expect(calls(api, '/api/v1/orders').at(-1)).toBe(
        '/api/v1/orders?status=pending&company_id=1',
      ),
    );
    await user.click(
      screen.getByRole('button', { name: 'Remove company filter' }),
    );
    await waitFor(() => expect(loc()).toHaveTextContent(/status=pending$/));
  });

  it('the old /orders URL has no company filter, no redirect and customer-style links for staff', async () => {
    const { api } = setupAdmin('/orders', {
      [ORDERS]: { body: orderList([o]) },
    });
    const t = within(await screen.findByRole('table', { name: 'Orders' }));
    expect(loc()).toHaveTextContent(/^\/orders$/);
    expect(screen.queryByLabelText('Company')).toBeNull();
    expect(t.getByRole('link', { name: o.number })).toHaveAttribute(
      'href',
      `/orders/${o.number}`,
    );
    expect(calls(api, '/api/v1/orders')).toEqual(['/api/v1/orders']);
  });

  it('restores company_id from the URL and drops an invalid one', async () => {
    const { api } = setupAdmin('/admin/orders?status=pending&company_id=abc', {
      [ORDERS]: { body: orderList([o]) },
    });
    await screen.findByRole('table', { name: 'Orders' });
    expect(calls(api, '/api/v1/orders')[0]).toBe(
      '/api/v1/orders?status=pending',
    );
  });
});

describe('admin quotes list', () => {
  const q = quote({ status: 'quoted', valid_until: '2026-12-01' });
  it('redirects to the needs-attention default and links into /admin', async () => {
    const { api } = setupAdmin('/admin/quotes', {
      [QUOTES]: { body: quoteList([q]) },
    });
    const t = within(await screen.findByRole('table', { name: 'Quotes' }));
    expect(loc()).toHaveTextContent(
      '/admin/quotes?status=submitted,in_review&sort=created_at',
    );
    expect(calls(api, '/api/v1/quotes')).toEqual([
      '/api/v1/quotes?status=submitted,in_review&sort=created_at',
    ]);
    expect(t.getByRole('link', { name: q.number })).toHaveAttribute(
      'href',
      `/admin/quotes/${q.number}`,
    );
  });

  it('the expiring-soon filter sets expiring_within_days (also from the dashboard link)', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin(
      '/admin/quotes?status=quoted&expiring_within_days=7',
      {
        [QUOTES]: { body: quoteList([q]) },
      },
    );
    await screen.findByRole('table', { name: 'Quotes' });
    expect(calls(api, '/api/v1/quotes')[0]).toBe(
      '/api/v1/quotes?status=quoted&expiring_within_days=7',
    );
    expect(screen.getByLabelText('Expiring within')).toHaveValue('7');
    await user.selectOptions(screen.getByLabelText('Expiring within'), '14');
    await waitFor(() =>
      expect(loc()).toHaveTextContent('status=quoted&expiring_within_days=14'),
    );
    await waitFor(() =>
      expect(calls(api, '/api/v1/quotes').at(-1)).toBe(
        '/api/v1/quotes?status=quoted&expiring_within_days=14',
      ),
    );
  });

  it('drops an out-of-range expiring_within_days', async () => {
    const { api } = setupAdmin(
      '/admin/quotes?status=quoted&expiring_within_days=31',
      {
        [QUOTES]: { body: quoteList([q]) },
      },
    );
    await screen.findByRole('table', { name: 'Quotes' });
    expect(calls(api, '/api/v1/quotes')[0]).toBe(
      '/api/v1/quotes?status=quoted',
    );
  });

  it('quote detail keeps staff controls and links back and to the order inside /admin', async () => {
    setupAdmin(`/admin/quotes/${NUM}`, {
      [QUOTE_URL]: quoteDetail(
        quote({
          status: 'accepted',
          order: { number: 'ORD-K7Q2M-X9PA3' },
          allowed_actions: [],
        }),
      ),
    });
    await screen.findByRole('heading', { name: new RegExp(`Quote ${NUM}`) });
    expect(
      screen.getByRole('link', { name: 'Back to quotes' }),
    ).toHaveAttribute('href', '/admin/quotes');
    expect(
      screen.getByRole('link', { name: 'ORD-K7Q2M-X9PA3' }),
    ).toHaveAttribute('href', '/admin/orders/ORD-K7Q2M-X9PA3');
  });
});

describe('admin invoices list', () => {
  const i = invoice();
  it('redirects to open and overdue, oldest due date first, and links into /admin', async () => {
    const { api } = setupAdmin('/admin/invoices', {
      [INVOICES]: { body: listOfInvoices([i]) },
    });
    const t = within(await screen.findByRole('table', { name: 'Invoices' }));
    expect(loc()).toHaveTextContent(
      '/admin/invoices?status=open,overdue&sort=due_date',
    );
    expect(calls(api, '/api/v1/invoices')).toEqual([
      '/api/v1/invoices?status=open,overdue&sort=due_date',
    ]);
    expect(t.getByRole('link', { name: i.number })).toHaveAttribute(
      'href',
      `/admin/invoices/${i.id}`,
    );
    expect(t.getByRole('link', { name: i.order_number })).toHaveAttribute(
      'href',
      `/admin/orders/${i.order_number}`,
    );
  });

  it('the due-soon filter sets due_within_days, including Today = 0', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin(
      '/admin/invoices?status=open&due_within_days=7',
      {
        [INVOICES]: { body: listOfInvoices([i]) },
      },
    );
    await screen.findByRole('table', { name: 'Invoices' });
    expect(calls(api, '/api/v1/invoices')[0]).toBe(
      '/api/v1/invoices?status=open&due_within_days=7',
    );
    await user.selectOptions(screen.getByLabelText('Due within'), '0');
    await waitFor(() =>
      expect(calls(api, '/api/v1/invoices').at(-1)).toBe(
        '/api/v1/invoices?status=open&due_within_days=0',
      ),
    );
  });

  it('invoice detail links back and to its order inside /admin and keeps staff actions', async () => {
    setupAdmin(
      `/admin/invoices/${INV_ID}`,
      {
        [INV_URL]: {
          body: {
            data: invoiceDetail({
              allowed_actions: ['download', 'mark_paid', 'void'],
            }),
          },
        },
      },
      admin,
    );
    await screen.findByRole('button', { name: /mark as paid/i });
    expect(
      screen.getByRole('link', { name: 'Back to invoices' }),
    ).toHaveAttribute('href', '/admin/invoices');
    expect(screen.getByRole('link', { name: /^Order ORD/ })).toHaveAttribute(
      'href',
      expect.stringMatching(/^\/admin\/orders\/ORD-/),
    );
  });
});
