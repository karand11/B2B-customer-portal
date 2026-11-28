import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';
import Pagination from '../Pagination';
import DataTable from '../../../components/DataTable';
import { parseQuery } from '../urlState';
import { parseQuery as parseOrders } from '../../orders/urlState';
import { parseQuery as parseQuotes } from '../../quotes/urlState';
import { parseQuery as parseInvoices } from '../../invoices/urlState';
import { WINDOW_MESSAGE } from '../../../lib/pageWindow';

const sp = (s: string) => new URLSearchParams(s);

describe('Pagination at the window edge', () => {
  it('disables Next and shows the hint when the next page leaves the window', async () => {
    const onPage = vi.fn();
    render(
      <Pagination page={416} lastPage={900} perPage={24} onPage={onPage} />,
    );
    const next = screen.getByRole('button', { name: 'Next' });
    expect(next).toBeDisabled();
    expect(next).toHaveAccessibleDescription(WINDOW_MESSAGE);
    expect(screen.getByText(WINDOW_MESSAGE)).toBeInTheDocument();
    await userEvent.click(next);
    expect(onPage).not.toHaveBeenCalled();
    // Previous still works.
    await userEvent.click(screen.getByRole('button', { name: 'Previous' }));
    expect(onPage).toHaveBeenCalledWith(415);
  });

  it('is unchanged inside the window and on the true last page', () => {
    const { rerender } = render(
      <Pagination page={415} lastPage={900} perPage={24} onPage={vi.fn()} />,
    );
    expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled();
    expect(screen.queryByText(WINDOW_MESSAGE)).toBeNull();
    rerender(
      <Pagination page={5} lastPage={5} perPage={24} onPage={vi.fn()} />,
    );
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    expect(screen.queryByText(WINDOW_MESSAGE)).toBeNull();
  });

  it('works without perPage (old callers)', () => {
    render(<Pagination page={1} lastPage={3} onPage={vi.fn()} />);
    expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled();
  });
});

describe('DataTable at the window edge', () => {
  it('clamps its Next button the same way', () => {
    render(
      <DataTable
        caption="Items"
        columns={[{ key: 'a', header: 'A', render: (r: { a: string }) => r.a }]}
        rows={[{ a: 'x' }]}
        rowKey={(r) => r.a}
        sort="a"
        defaultSort="a"
        onSortChange={vi.fn()}
        page={400}
        lastPage={900}
        perPage={25}
        total={22_500}
        onPageChange={vi.fn()}
        isLoading={false}
        onRetry={vi.fn()}
        emptyMessage="None"
      />,
    );
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    expect(screen.getByText(WINDOW_MESSAGE)).toBeInTheDocument();
  });
});

describe('a hand-edited page in the URL is clamped to the window, never sent past it', () => {
  it('catalog (24 per page): max page 416', () => {
    expect(parseQuery(sp('page=900')).page).toBe(416);
    expect(parseQuery(sp('page=416')).page).toBe(416);
    expect(parseQuery(sp('page=7')).page).toBe(7);
  });
  it.each([
    ['orders', parseOrders],
    ['quotes', parseQuotes],
    ['invoices', parseInvoices],
  ])('%s (20 per page): max page 500', (_n, parse) => {
    expect(parse(sp('page=9000')).page).toBe(500);
    expect(parse(sp('page=7')).page).toBe(7);
  });
});
