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
import { deferred, loc } from '../../catalog/__tests__/fixtures';
import {
  ME,
  ORDERS,
  listOf,
  order,
  setupApp,
} from '../../cart/__tests__/fixtures';

afterEach(() => vi.useRealTimers());

const fakeTimers = () => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime.bind(vi) });
};
const two = listOf([
  order({ po_reference: 'PO-4711/A' }),
  order({
    number: 'ORD-AAAAA-BBBBB',
    status: 'shipped',
    total_cents: 990,
    company: { id: 2, name: 'Globex Corp' },
  }),
]);
const base = { [ORDERS]: { body: two } };
const urls = (api: { calls: { url: string }[] }) =>
  api.calls
    .map((c) => c.url)
    .filter((u) => u === '/api/v1/orders' || u.startsWith('/api/v1/orders?'));
const tick = (name: string) => screen.findByRole('checkbox', { name });
const table = () => screen.findByRole('table', { name: 'Orders' });

describe('orders list', () => {
  it('customers see their company heading, PO reference and status as text, no Company column', async () => {
    setupApp('/orders', base);
    expect(
      await screen.findByRole('heading', {
        name: 'Orders for Northwind Machinery Ltd',
      }),
    ).toBeInTheDocument();
    const t = within(await table());
    expect(t.getByText('PO-4711/A')).toBeInTheDocument();
    expect(t.getByText('Shipped')).toBeInTheDocument();
    expect(t.getByText('Pending')).toBeInTheDocument();
    expect(t.getByText('$9.90')).toBeInTheDocument();
    expect(t.getByRole('link', { name: 'ORD-K7Q2M-X9PA3' })).toHaveAttribute(
      'href',
      '/orders/ORD-K7Q2M-X9PA3',
    );
    expect(t.queryByRole('columnheader', { name: 'Company' })).toBeNull();
    expect(screen.getByText('2 orders found')).toBeInTheDocument();
  });

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])('%s sees all orders with the Company column', async (_n, who) => {
    setupApp('/orders', { ...base, [ME]: meOk(who) }, who);
    const t = within(await table());
    expect(
      t.getByRole('columnheader', { name: 'Company' }),
    ).toBeInTheDocument();
    expect(t.getByText('Globex Corp')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Orders' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /^Cart/ })).toBeNull();
  });

  it('shows a loading skeleton first', async () => {
    const d = deferred<Spec>();
    setupApp('/orders', { [ORDERS]: () => d.promise });
    expect(
      await screen.findByRole('status', { name: 'Loading orders' }),
    ).toHaveAttribute('aria-busy', 'true');
    d.resolve({ body: two });
    await table();
  });

  it('empty without filters: customer text with catalog link, staff text', async () => {
    setupApp('/orders', { [ORDERS]: { body: listOf([]) } });
    expect(
      await screen.findByText(/You have no orders yet/),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Browse the catalog' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Clear filters' })).toBeNull();
  });

  it('empty without filters for staff', async () => {
    setupApp(
      '/orders',
      { [ORDERS]: { body: listOf([]) }, [ME]: meOk(staff) },
      staff,
    );
    expect(await screen.findByText('No orders yet.')).toBeInTheDocument();
  });

  it('empty with filters offers Clear filters, which empties the query string', async () => {
    const user = userEvent.setup();
    setupApp('/orders?status=cancelled', { [ORDERS]: { body: listOf([]) } });
    expect(
      await screen.findByText('No orders match your filters.'),
    ).toBeInTheDocument();
    await user.click(
      screen.getAllByRole('button', { name: 'Clear filters' })[0],
    );
    expect(loc()).toBe('/orders');
  });

  it('error shows an alert and Try again refetches; 429 shows seconds', async () => {
    const user = userEvent.setup();
    let mode: 'err' | 'rate' | 'ok' = 'err';
    setupApp('/orders', {
      [ORDERS]: (): Spec =>
        mode === 'err'
          ? { status: 500, body: { message: 'x' } }
          : mode === 'rate'
            ? {
                status: 429,
                headers: { 'Retry-After': '9' },
                body: { message: 'x' },
              }
            : { body: two },
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

  it('every filter control has an accessible name', async () => {
    setupApp('/orders', base);
    await table();
    for (const s of [
      'Pending',
      'Confirmed',
      'Shipped',
      'Delivered',
      'Cancelled',
    ])
      expect(screen.getByRole('checkbox', { name: s })).toBeInTheDocument();
    expect(screen.getByRole('group', { name: 'Status' })).toBeInTheDocument();
    expect(screen.getByLabelText('From date')).toBeInTheDocument();
    expect(screen.getByLabelText('To date')).toBeInTheDocument();
    expect(
      screen.getByLabelText('Order number or PO reference'),
    ).toBeInTheDocument();
    expect(screen.getByLabelText('Sort by')).toBeInTheDocument();
  });
});

describe('orders list filters and the URL', () => {
  it('ticking a status writes ?status= and requests it; any filter change removes page', async () => {
    const user = userEvent.setup();
    const { api } = setupApp('/orders?page=3', {
      [ORDERS]: {
        body: listOf([order()], { current_page: 3, last_page: 5, total: 90 }),
      },
    });
    await user.click(await tick('Pending'));
    expect(loc()).toBe('/orders?status=pending');
    await user.click(await tick('Shipped'));
    expect(loc()).toBe('/orders?status=pending,shipped');
    await waitFor(() =>
      expect(urls(api)).toContain('/api/v1/orders?status=pending,shipped'),
    );
  });

  it('from and to write their params and are requested', async () => {
    const { api } = setupApp('/orders', base);
    await table();
    fireEvent.change(screen.getByLabelText('From date'), {
      target: { value: '2026-10-01' },
    });
    fireEvent.change(screen.getByLabelText('To date'), {
      target: { value: '2026-10-31' },
    });
    expect(loc()).toBe('/orders?from=2026-10-01&to=2026-10-31');
    await waitFor(() =>
      expect(urls(api)).toContain(
        '/api/v1/orders?from=2026-10-01&to=2026-10-31',
      ),
    );
  });

  it('search is debounced 300 ms, sends one request and replaces the history entry', async () => {
    const user = fakeTimers();
    const { api, router } = setupApp('/orders', base);
    await table();
    const before = urls(api).length;
    await user.type(
      screen.getByLabelText('Order number or PO reference'),
      'k7q2',
    );
    expect(loc()).toBe('/orders');
    await act(() => vi.advanceTimersByTimeAsync(300));
    expect(loc()).toBe('/orders?q=k7q2');
    expect(router.state.historyAction).toBe('REPLACE');
    await waitFor(() => expect(urls(api)).toHaveLength(before + 1));
    expect(urls(api).at(-1)).toBe('/api/v1/orders?q=k7q2');
  });

  it('sort writes ?sort= and the default is omitted', async () => {
    const user = userEvent.setup();
    setupApp('/orders', base);
    await table();
    await user.selectOptions(screen.getByLabelText('Sort by'), 'Highest total');
    expect(loc()).toBe('/orders?sort=-total');
    await user.selectOptions(screen.getByLabelText('Sort by'), 'Newest');
    expect(loc()).toBe('/orders');
  });

  it('restores everything from the URL and the first request carries exactly those params', async () => {
    const { api } = setupApp(
      '/orders?status=pending,shipped&from=2026-10-01&to=2026-10-31&sort=-total&page=2',
      {
        [ORDERS]: {
          body: listOf([order()], { current_page: 2, last_page: 3, total: 41 }),
        },
      },
    );
    await table();
    expect(urls(api)[0]).toBe(
      '/api/v1/orders?status=pending,shipped&from=2026-10-01&to=2026-10-31&sort=-total&page=2',
    );
    expect(screen.getByRole('checkbox', { name: 'Pending' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Shipped' })).toBeChecked();
    expect(
      screen.getByRole('checkbox', { name: 'Delivered' }),
    ).not.toBeChecked();
    expect(screen.getByLabelText('From date')).toHaveValue('2026-10-01');
    expect(screen.getByLabelText('To date')).toHaveValue('2026-10-31');
    expect(screen.getByLabelText('Sort by')).toHaveValue('-total');
    expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
  });

  it('back and forward restore the filter state', async () => {
    const user = userEvent.setup();
    const { router } = setupApp('/orders', base);
    await user.click(await tick('Pending'));
    await user.click(await tick('Shipped'));
    await act(() => router.navigate(-1));
    expect(loc()).toBe('/orders?status=pending');
    expect(screen.getByRole('checkbox', { name: 'Shipped' })).not.toBeChecked();
    await act(() => router.navigate(1));
    expect(screen.getByRole('checkbox', { name: 'Shipped' })).toBeChecked();
  });

  it('invalid URL values are dropped and never sent', async () => {
    const { api } = setupApp(
      '/orders?status=evil&from=2026-13-01&to=nope&sort=x&page=-1&q=' +
        'z'.repeat(40),
      base,
    );
    await table();
    expect(urls(api)).toEqual(['/api/v1/orders']);
    expect(screen.getByRole('checkbox', { name: 'Pending' })).not.toBeChecked();
    expect(screen.getByLabelText('From date')).toHaveValue('');
  });

  it('from after to shows an inline error and sends nothing', async () => {
    const { api } = setupApp('/orders?from=2026-11-10&to=2026-11-01', base);
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
    setupApp('/orders?status=pending&from=2026-10-01&q=abc&sort=total', base);
    await table();
    await user.click(screen.getByRole('button', { name: 'Clear filters' }));
    expect(loc()).toBe('/orders');
  });
});

describe('orders list pagination', () => {
  it('Previous is disabled on page 1 and Next moves to page 2', async () => {
    const user = userEvent.setup();
    setupApp('/orders', {
      [ORDERS]: { body: listOf([order()], { last_page: 3, total: 41 }) },
    });
    await table();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(loc()).toBe('/orders?page=2');
  });

  it('a page beyond the last jumps to the last page', async () => {
    setupApp('/orders?page=9', {
      [ORDERS]: (r): Spec =>
        r.url.includes('page=9')
          ? {
              body: {
                ...listOf([], { current_page: 9, last_page: 3, total: 41 }),
              },
            }
          : {
              body: listOf([order()], {
                current_page: 3,
                last_page: 3,
                total: 41,
              }),
            },
    });
    await table();
    // The jump happens in an effect after the first (empty) page renders.
    await waitFor(() => expect(loc()).toBe('/orders?page=3'));
  });
});
