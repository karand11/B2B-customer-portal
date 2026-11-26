import {
  act,
  fireEvent,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { admin, meOk, staff, type Spec } from '../../../test/utils';
import { ME, setupApp } from '../../cart/__tests__/fixtures';
import { deferred, loc } from '../../catalog/__tests__/fixtures';
import { INVOICES, allStatuses, invoice, listOfInvoices } from './fixtures';

afterEach(() => vi.useRealTimers());

const fakeTimers = () => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime.bind(vi) });
};
const base = { [INVOICES]: { body: listOfInvoices(allStatuses()) } };
const urls = (api: { calls: { url: string }[] }) =>
  api.calls
    .map((c) => c.url)
    .filter(
      (u) => u === '/api/v1/invoices' || u.startsWith('/api/v1/invoices?'),
    );
const tick = (name: string) => screen.findByRole('checkbox', { name });
const table = () => screen.findByRole('table', { name: 'Invoices' });

describe('invoices list rendering', () => {
  it('shows all four statuses as text, links to the invoice and the order, and formats money from the body currency', async () => {
    setupApp('/invoices', {
      [INVOICES]: {
        body: listOfInvoices([
          ...allStatuses().slice(0, 3),
          invoice({
            ...allStatuses()[3],
            currency: 'EUR',
            total_cents: 127424,
            po_reference: null,
          }),
        ]),
      },
    });
    expect(
      await screen.findByRole('heading', {
        name: 'Invoices for Northwind Machinery Ltd',
      }),
    ).toBeInTheDocument();
    const t = within(await table());
    for (const s of ['Open', 'Overdue', 'Paid', 'Void'])
      expect(t.getByText(s)).toBeInTheDocument();
    expect(t.getByRole('link', { name: 'INV-2026-000001' })).toHaveAttribute(
      'href',
      '/invoices/3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90',
    );
    expect(
      t.getAllByRole('link', { name: 'ORD-K7Q2M-X9PA3' })[0],
    ).toHaveAttribute('href', '/orders/ORD-K7Q2M-X9PA3');
    expect(t.getAllByText('$1,274.24')).toHaveLength(3);
    expect(t.getByText('€1,274.24')).toBeInTheDocument();
    expect(t.getByText('—')).toBeInTheDocument(); // null PO reference
    expect(screen.getByText('4 invoices found')).toBeInTheDocument();
  });

  it('overdue is flagged with text, an aria-hidden icon and "N days overdue" (not colour alone)', async () => {
    setupApp('/invoices', base);
    const row = within(await table())
      .getByRole('link', { name: 'INV-2026-000002' })
      .closest('tr')!;
    const r = within(row);
    expect(r.getByText('Overdue')).toBeInTheDocument();
    expect(r.getByText('12 days overdue')).toBeVisible();
    const icon = row.querySelector('svg');
    expect(icon).toHaveAttribute('aria-hidden', 'true');
    // Only overdue rows carry the warning; open, paid and void do not.
    const others = ['000001', '000003', '000004'].map((n) =>
      screen.getByRole('link', { name: `INV-2026-${n}` }).closest('tr')!,
    );
    for (const o of others) {
      expect(o.querySelector('svg')).toBeNull();
      expect(within(o).queryByText(/overdue/)).toBeNull();
    }
  });

  it('uses the singular for one day overdue', async () => {
    setupApp('/invoices', {
      [INVOICES]: {
        body: listOfInvoices([invoice({ status: 'overdue', days_overdue: 1 })]),
      },
    });
    expect(await screen.findByText('1 day overdue')).toBeInTheDocument();
  });

  it('renders API text as text, never HTML', async () => {
    setupApp('/invoices', {
      [INVOICES]: {
        body: listOfInvoices([
          invoice({ po_reference: '<img src=x onerror=alert(1)>' }),
        ]),
      },
    });
    expect(
      await screen.findByText('<img src=x onerror=alert(1)>'),
    ).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
  });

  it('customers have no Company column and no Overdue shortcut', async () => {
    setupApp('/invoices', base);
    const t = within(await table());
    expect(t.queryByRole('columnheader', { name: 'Company' })).toBeNull();
    expect(
      screen.queryByRole('button', { name: 'Show overdue only' }),
    ).toBeNull();
  });

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])('%s sees the Company column and the Overdue shortcut', async (_n, who) => {
    const user = userEvent.setup();
    setupApp('/invoices', { ...base, [ME]: meOk(who) }, who);
    const t = within(await table());
    expect(
      t.getByRole('columnheader', { name: 'Company' }),
    ).toBeInTheDocument();
    expect(t.getAllByText('Northwind Machinery Ltd')).toHaveLength(4);
    expect(
      screen.getByRole('heading', { name: 'Invoices' }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Show overdue only' }));
    expect(loc()).toBe('/invoices?status=overdue');
  });
});

describe('invoices list states', () => {
  it('shows a loading skeleton first', async () => {
    const d = deferred<Spec>();
    setupApp('/invoices', { [INVOICES]: () => d.promise });
    expect(
      await screen.findByRole('status', { name: 'Loading invoices' }),
    ).toHaveAttribute('aria-busy', 'true');
    d.resolve({ body: listOfInvoices(allStatuses()) });
    await table();
  });

  it('empty without filters: customer text, staff text', async () => {
    setupApp('/invoices', { [INVOICES]: { body: listOfInvoices([]) } });
    expect(
      await screen.findByText('You have no invoices yet.'),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Clear filters' })).toBeNull();
  });

  it('empty without filters for staff', async () => {
    setupApp(
      '/invoices',
      { [INVOICES]: { body: listOfInvoices([]) }, [ME]: meOk(staff) },
      staff,
    );
    expect(await screen.findByText('No invoices.')).toBeInTheDocument();
  });

  it('empty with filters offers Clear filters, which empties the query string', async () => {
    const user = userEvent.setup();
    setupApp('/invoices?status=void', {
      [INVOICES]: { body: listOfInvoices([]) },
    });
    expect(
      await screen.findByText('No invoices match your filters.'),
    ).toBeInTheDocument();
    await user.click(
      screen.getAllByRole('button', { name: 'Clear filters' })[0],
    );
    expect(loc()).toBe('/invoices');
  });

  it('error shows an alert, Try again refetches, and a 429 shows the seconds', async () => {
    const user = userEvent.setup();
    let mode: 'err' | 'rate' | 'ok' = 'err';
    setupApp('/invoices', {
      [INVOICES]: (): Spec =>
        mode === 'err'
          ? { status: 500, body: { message: 'x' } }
          : mode === 'rate'
            ? {
                status: 429,
                headers: { 'Retry-After': '9' },
                body: { message: 'x' },
              }
            : { body: listOfInvoices(allStatuses()) },
    });
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    mode = 'rate';
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent('9 seconds'),
    );
    mode = 'ok';
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await table();
  });

  it('every control has an accessible name, the table has a caption and the count is a polite live region', async () => {
    setupApp('/invoices', base);
    await table();
    expect(screen.getByRole('group', { name: 'Status' })).toBeInTheDocument();
    for (const s of ['Open', 'Overdue', 'Paid', 'Void'])
      expect(screen.getByRole('checkbox', { name: s })).toBeInTheDocument();
    expect(screen.getByLabelText('From date')).toBeInTheDocument();
    expect(screen.getByLabelText('To date')).toBeInTheDocument();
    expect(
      screen.getByLabelText('Invoice number, order number or PO reference'),
    ).toBeInTheDocument();
    expect(screen.getByLabelText('Sort by')).toBeInTheDocument();
    const live = screen.getByText('4 invoices found');
    expect(live).toHaveAttribute('aria-live', 'polite');
  });
});

describe('invoices list filters and the URL', () => {
  it('ticking statuses writes the CSV, any filter change removes page', async () => {
    const user = userEvent.setup();
    const { api } = setupApp('/invoices?page=3', {
      [INVOICES]: {
        body: listOfInvoices(allStatuses(), {
          current_page: 3,
          last_page: 5,
          total: 90,
        }),
      },
    });
    await user.click(await tick('Open'));
    expect(loc()).toBe('/invoices?status=open');
    await user.click(await tick('Overdue'));
    expect(loc()).toBe('/invoices?status=open,overdue');
    await waitFor(() =>
      expect(urls(api)).toContain('/api/v1/invoices?status=open,overdue'),
    );
  });

  it('from and to write their params and are requested', async () => {
    const { api } = setupApp('/invoices', base);
    await table();
    fireEvent.change(screen.getByLabelText('From date'), {
      target: { value: '2026-10-01' },
    });
    fireEvent.change(screen.getByLabelText('To date'), {
      target: { value: '2026-10-31' },
    });
    expect(loc()).toBe('/invoices?from=2026-10-01&to=2026-10-31');
    await waitFor(() =>
      expect(urls(api)).toContain(
        '/api/v1/invoices?from=2026-10-01&to=2026-10-31',
      ),
    );
  });

  it('search is debounced 300 ms, sends one request and replaces the history entry', async () => {
    const user = fakeTimers();
    const { api, router } = setupApp('/invoices', base);
    await table();
    const before = urls(api).length;
    await user.type(
      screen.getByLabelText('Invoice number, order number or PO reference'),
      'inv-2026-1',
    );
    expect(loc()).toBe('/invoices');
    await act(() => vi.advanceTimersByTimeAsync(300));
    expect(loc()).toBe('/invoices?q=inv-2026-1');
    expect(router.state.historyAction).toBe('REPLACE');
    await waitFor(() => expect(urls(api)).toHaveLength(before + 1));
    expect(urls(api).at(-1)).toBe('/api/v1/invoices?q=inv-2026-1');
  });

  it('sort writes ?sort= and the default is omitted', async () => {
    const user = userEvent.setup();
    setupApp('/invoices', base);
    await table();
    await user.selectOptions(
      screen.getByLabelText('Sort by'),
      'Total (highest)',
    );
    expect(loc()).toBe('/invoices?sort=-total');
    await user.selectOptions(screen.getByLabelText('Sort by'), 'Newest');
    expect(loc()).toBe('/invoices');
  });

  it('restores everything from the URL and the first request carries exactly those params', async () => {
    const { api } = setupApp(
      '/invoices?status=open,overdue&from=2026-10-01&to=2026-10-31&q=k7q2&sort=due_date&page=2',
      {
        [INVOICES]: {
          body: listOfInvoices(allStatuses(), {
            current_page: 2,
            last_page: 3,
            total: 41,
          }),
        },
      },
    );
    await table();
    expect(urls(api)[0]).toBe(
      '/api/v1/invoices?status=open,overdue&from=2026-10-01&to=2026-10-31&q=k7q2&sort=due_date&page=2',
    );
    expect(screen.getByRole('checkbox', { name: 'Open' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Overdue' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Paid' })).not.toBeChecked();
    expect(screen.getByLabelText('From date')).toHaveValue('2026-10-01');
    expect(screen.getByLabelText('To date')).toHaveValue('2026-10-31');
    expect(
      screen.getByLabelText('Invoice number, order number or PO reference'),
    ).toHaveValue('k7q2');
    expect(screen.getByLabelText('Sort by')).toHaveValue('due_date');
    expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
  });

  it('back and forward restore the filter state', async () => {
    const user = userEvent.setup();
    const { router } = setupApp('/invoices', base);
    await user.click(await tick('Open'));
    await user.click(await tick('Paid'));
    await act(() => router.navigate(-1));
    expect(loc()).toBe('/invoices?status=open');
    expect(screen.getByRole('checkbox', { name: 'Paid' })).not.toBeChecked();
    await act(() => router.navigate(1));
    expect(screen.getByRole('checkbox', { name: 'Paid' })).toBeChecked();
  });

  it('invalid URL values are dropped and never sent', async () => {
    const { api } = setupApp(
      '/invoices?status=evil,void,void&from=2026-13-01&to=nope&sort=x&page=-1&q=' +
        'z'.repeat(40),
      base,
    );
    await table();
    expect(urls(api)).toEqual(['/api/v1/invoices?status=void']);
    expect(screen.getByRole('checkbox', { name: 'Void' })).toBeChecked();
    expect(screen.getByLabelText('From date')).toHaveValue('');
  });

  it('from after to shows an inline alert and sends nothing', async () => {
    const { api } = setupApp('/invoices?from=2026-11-10&to=2026-11-01', base);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /must not be after/,
    );
    expect(screen.getByLabelText('From date')).toHaveAttribute(
      'aria-invalid',
      'true',
    );
    expect(urls(api)).toHaveLength(0);
  });

  it('Clear filters empties the query string', async () => {
    const user = userEvent.setup();
    setupApp('/invoices?status=paid&from=2026-10-01&q=abc&sort=total', base);
    await table();
    await user.click(screen.getByRole('button', { name: 'Clear filters' }));
    expect(loc()).toBe('/invoices');
  });
});

describe('invoices list pagination', () => {
  it('Previous is disabled on page 1 and Next moves to page 2', async () => {
    const user = userEvent.setup();
    setupApp('/invoices', {
      [INVOICES]: {
        body: listOfInvoices(allStatuses(), { last_page: 3, total: 41 }),
      },
    });
    await table();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(loc()).toBe('/invoices?page=2');
  });

  it('a page beyond the last jumps to the last page', async () => {
    setupApp('/invoices?page=9', {
      [INVOICES]: (r): Spec =>
        r.url.includes('page=9')
          ? {
              body: listOfInvoices([], {
                current_page: 9,
                last_page: 3,
                total: 41,
              }),
            }
          : {
              body: listOfInvoices(allStatuses(), {
                current_page: 3,
                last_page: 3,
                total: 41,
              }),
            },
    });
    await table();
    await waitFor(() => expect(loc()).toBe('/invoices?page=3'));
  });
});
