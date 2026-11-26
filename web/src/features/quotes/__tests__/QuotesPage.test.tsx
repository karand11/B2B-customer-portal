import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { meOk, staff, admin, type Spec } from '../../../test/utils';
import { deferred, loc } from '../../catalog/__tests__/fixtures';
import StatusBadge from '../StatusBadge';
import { LIST, ME, day, listOf, quote, quoted, setupApp } from './fixtures';

afterEach(() => vi.useRealTimers());

const fakeTimers = () => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime.bind(vi) });
};
const two = listOf([
  quote({ po_reference: 'PO-4711/A' }),
  quoted({
    number: 'QUO-AAAAA-BBBBB',
    company: { id: 2, name: 'Globex Corp' },
  }),
]);
const base = { [LIST]: { body: two } };
const urls = (api: { calls: { url: string }[] }) =>
  api.calls
    .map((c) => c.url)
    .filter((u) => u === '/api/v1/quotes' || u.startsWith('/api/v1/quotes?'));
const tick = (name: string) => screen.findByRole('checkbox', { name });
const table = () => screen.findByRole('table', { name: 'Quotes' });

describe('status badge', () => {
  it.each([
    ['submitted', 'Submitted'],
    ['in_review', 'In review'],
    ['quoted', 'Quoted'],
    ['accepted', 'Accepted'],
    ['declined', 'Declined'],
    ['expired', 'Expired'],
  ])('%s renders the text label %s', (status, label) => {
    render(<StatusBadge status={status} />);
    expect(screen.getByText(label)).toHaveAttribute('data-status', status);
  });

  it('every status has its own colour classes', () => {
    const classes = [
      'submitted',
      'in_review',
      'quoted',
      'accepted',
      'declined',
      'expired',
    ].map((s) => {
      const { unmount } = render(<StatusBadge status={s} />);
      const c = screen.getByText(/./).className;
      unmount();
      return c;
    });
    expect(new Set(classes).size).toBe(6);
  });

  it('an unknown status falls back to its raw text and neutral styling', () => {
    render(<StatusBadge status="weird" />);
    const el = screen.getByText('weird');
    expect(el).toHaveAttribute('data-status', 'unknown');
    expect(el.className).not.toMatch(/green|amber|indigo|red/);
  });
});

describe('quotes list', () => {
  it('customers see PO text, status badges, Awaiting pricing, valid-until and no Company column', async () => {
    setupApp('/quotes', {
      [LIST]: {
        body: listOf([
          quote({ po_reference: 'PO-4711/A' }),
          quoted({ number: 'QUO-AAAAA-BBBBB' }),
          quoted({
            number: 'QUO-AAAAA-CCCCC',
            status: 'expired',
            valid_until: day(-3),
          }),
        ]),
      },
    });
    expect(
      await screen.findByRole('heading', {
        name: 'Quotes for Northwind Machinery Ltd',
      }),
    ).toBeInTheDocument();
    const t = within(await table());
    expect(t.getByText('PO-4711/A')).toBeInTheDocument();
    expect(t.getByText('Submitted')).toBeInTheDocument();
    expect(t.getByText('Quoted')).toBeInTheDocument();
    expect(t.getByText('Awaiting pricing')).toBeInTheDocument();
    expect(t.getAllByText('$280.00')).toHaveLength(2);
    expect(t.getByText(day(30))).toBeInTheDocument();
    // An expired quote says so instead of showing a date.
    const row = t
      .getByRole('link', { name: 'QUO-AAAAA-CCCCC' })
      .closest('tr') as HTMLElement;
    expect(within(row).getAllByText('Expired')).toHaveLength(2);
    expect(t.getByRole('link', { name: 'QUO-AAAAA-BBBBB' })).toHaveAttribute(
      'href',
      '/quotes/QUO-AAAAA-BBBBB',
    );
    expect(t.queryByRole('columnheader', { name: 'Company' })).toBeNull();
    expect(screen.getByText('3 quotes found')).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'New quote request' }),
    ).toHaveAttribute('href', '/quotes/new');
    expect(
      screen.queryByRole('button', { name: 'Needs attention' }),
    ).toBeNull();
  });

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])(
    '%s sees all companies, the Company column and no new-quote button',
    async (_n, who) => {
      setupApp('/quotes', { ...base, [ME]: meOk(who) }, who);
      const t = within(await table());
      expect(
        t.getByRole('columnheader', { name: 'Company' }),
      ).toBeInTheDocument();
      expect(t.getByText('Globex Corp')).toBeInTheDocument();
      expect(
        screen.getByRole('heading', { name: 'Quotes' }),
      ).toBeInTheDocument();
      expect(
        screen.queryByRole('link', { name: 'New quote request' }),
      ).toBeNull();
    },
  );

  it('the Needs attention shortcut sets status=submitted,in_review for staff', async () => {
    const user = userEvent.setup();
    const { api } = setupApp('/quotes', { ...base, [ME]: meOk(staff) }, staff);
    await user.click(
      await screen.findByRole('button', { name: 'Needs attention' }),
    );
    expect(loc()).toBe('/quotes?status=submitted,in_review');
    await waitFor(() =>
      expect(urls(api)).toContain('/api/v1/quotes?status=submitted,in_review'),
    );
    expect(screen.getByRole('checkbox', { name: 'In review' })).toBeChecked();
  });

  it('shows a loading skeleton first', async () => {
    const d = deferred<Spec>();
    setupApp('/quotes', { [LIST]: () => d.promise });
    expect(
      await screen.findByRole('status', { name: 'Loading quotes' }),
    ).toHaveAttribute('aria-busy', 'true');
    d.resolve({ body: two });
    await table();
  });

  it('empty without filters: customer text and button, staff text', async () => {
    setupApp('/quotes', { [LIST]: { body: listOf([]) } });
    expect(
      await screen.findByText('You have no quote requests yet.'),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Request a quote' }),
    ).toHaveAttribute('href', '/quotes/new');
    expect(screen.queryByRole('button', { name: 'Clear filters' })).toBeNull();
  });

  it('empty without filters for staff', async () => {
    setupApp(
      '/quotes',
      { [LIST]: { body: listOf([]) }, [ME]: meOk(staff) },
      staff,
    );
    expect(await screen.findByText('No quotes.')).toBeInTheDocument();
  });

  it('empty with filters offers Clear filters', async () => {
    const user = userEvent.setup();
    setupApp('/quotes?status=accepted', { [LIST]: { body: listOf([]) } });
    expect(
      await screen.findByText('No quotes match your filters.'),
    ).toBeInTheDocument();
    await user.click(
      screen.getAllByRole('button', { name: 'Clear filters' })[0],
    );
    expect(loc()).toBe('/quotes');
  });

  it('error shows an alert, Try again refetches, 429 shows seconds', async () => {
    const user = userEvent.setup();
    let mode: 'err' | 'rate' | 'ok' = 'err';
    setupApp('/quotes', {
      [LIST]: (): Spec =>
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
    setupApp('/quotes', base);
    await table();
    for (const s of [
      'Submitted',
      'In review',
      'Quoted',
      'Accepted',
      'Declined',
      'Expired',
    ])
      expect(screen.getByRole('checkbox', { name: s })).toBeInTheDocument();
    expect(screen.getByRole('group', { name: 'Status' })).toBeInTheDocument();
    expect(screen.getByLabelText('From date')).toBeInTheDocument();
    expect(screen.getByLabelText('To date')).toBeInTheDocument();
    expect(
      screen.getByLabelText('Quote number or PO reference'),
    ).toBeInTheDocument();
    expect(screen.getByLabelText('Sort by')).toBeInTheDocument();
  });
});

describe('quotes list filters and the URL', () => {
  it('ticking a status writes ?status= and requests it; any filter change removes page', async () => {
    const user = userEvent.setup();
    const { api } = setupApp('/quotes?page=3', {
      [LIST]: {
        body: listOf([quote()], { current_page: 3, last_page: 5, total: 90 }),
      },
    });
    await user.click(await tick('Submitted'));
    expect(loc()).toBe('/quotes?status=submitted');
    await user.click(await tick('Quoted'));
    expect(loc()).toBe('/quotes?status=submitted,quoted');
    await waitFor(() =>
      expect(urls(api)).toContain('/api/v1/quotes?status=submitted,quoted'),
    );
  });

  it('from and to write their params and are requested', async () => {
    const { api } = setupApp('/quotes', base);
    await table();
    fireEvent.change(screen.getByLabelText('From date'), {
      target: { value: '2026-10-01' },
    });
    fireEvent.change(screen.getByLabelText('To date'), {
      target: { value: '2026-10-31' },
    });
    expect(loc()).toBe('/quotes?from=2026-10-01&to=2026-10-31');
    await waitFor(() =>
      expect(urls(api)).toContain(
        '/api/v1/quotes?from=2026-10-01&to=2026-10-31',
      ),
    );
  });

  it('search is debounced 300 ms, sends one request and replaces the history entry', async () => {
    const user = fakeTimers();
    const { api, router } = setupApp('/quotes', base);
    await table();
    const before = urls(api).length;
    await user.type(
      screen.getByLabelText('Quote number or PO reference'),
      'k7q2',
    );
    expect(loc()).toBe('/quotes');
    await act(() => vi.advanceTimersByTimeAsync(300));
    expect(loc()).toBe('/quotes?q=k7q2');
    expect(router.state.historyAction).toBe('REPLACE');
    await waitFor(() => expect(urls(api)).toHaveLength(before + 1));
    expect(urls(api).at(-1)).toBe('/api/v1/quotes?q=k7q2');
  });

  it('sort writes ?sort= and the default is omitted', async () => {
    const user = userEvent.setup();
    setupApp('/quotes', base);
    await table();
    await user.selectOptions(screen.getByLabelText('Sort by'), 'Oldest');
    expect(loc()).toBe('/quotes?sort=created_at');
    await user.selectOptions(
      screen.getByLabelText('Sort by'),
      'Number (A to Z)',
    );
    expect(loc()).toBe('/quotes?sort=number');
    await user.selectOptions(screen.getByLabelText('Sort by'), 'Newest');
    expect(loc()).toBe('/quotes');
  });

  it('restores everything from the URL and the first request carries exactly those params', async () => {
    const { api } = setupApp(
      '/quotes?status=submitted,expired&from=2026-10-01&to=2026-10-31&sort=-number&page=2',
      {
        [LIST]: {
          body: listOf([quote()], { current_page: 2, last_page: 3, total: 41 }),
        },
      },
    );
    await table();
    expect(urls(api)[0]).toBe(
      '/api/v1/quotes?status=submitted,expired&from=2026-10-01&to=2026-10-31&sort=-number&page=2',
    );
    expect(screen.getByRole('checkbox', { name: 'Submitted' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Expired' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Quoted' })).not.toBeChecked();
    expect(screen.getByLabelText('From date')).toHaveValue('2026-10-01');
    expect(screen.getByLabelText('Sort by')).toHaveValue('-number');
    expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
  });

  it('back and forward restore the filter state', async () => {
    const user = userEvent.setup();
    const { router } = setupApp('/quotes', base);
    await user.click(await tick('Submitted'));
    await user.click(await tick('Quoted'));
    await act(() => router.navigate(-1));
    expect(loc()).toBe('/quotes?status=submitted');
    expect(screen.getByRole('checkbox', { name: 'Quoted' })).not.toBeChecked();
    await act(() => router.navigate(1));
    expect(screen.getByRole('checkbox', { name: 'Quoted' })).toBeChecked();
  });

  it('invalid URL values are dropped and never sent', async () => {
    const { api } = setupApp(
      '/quotes?status=evil&from=2026-13-01&to=nope&sort=-total&page=-1&q=' +
        'z'.repeat(40),
      base,
    );
    await table();
    expect(urls(api)).toEqual(['/api/v1/quotes']);
    expect(
      screen.getByRole('checkbox', { name: 'Submitted' }),
    ).not.toBeChecked();
    expect(screen.getByLabelText('From date')).toHaveValue('');
  });

  it('from after to shows an inline error and sends nothing', async () => {
    const { api } = setupApp('/quotes?from=2026-11-10&to=2026-11-01', base);
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
    setupApp('/quotes?status=quoted&from=2026-10-01&q=abc&sort=number', base);
    await table();
    await user.click(screen.getByRole('button', { name: 'Clear filters' }));
    expect(loc()).toBe('/quotes');
  });

  it('pagination: Previous is disabled on page 1, Next moves to page 2', async () => {
    const user = userEvent.setup();
    setupApp('/quotes', {
      [LIST]: { body: listOf([quote()], { last_page: 3, total: 41 }) },
    });
    await table();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(loc()).toBe('/quotes?page=2');
  });
});
