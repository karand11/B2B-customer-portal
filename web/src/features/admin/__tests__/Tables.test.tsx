import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { PRODUCTS, loc, paged, product, setupAdmin } from './fixtures';

const P = [
  product(),
  product({ id: 18, part_number: '6206-2RS', name: 'Bearing 6206' }),
  product({
    id: 19,
    part_number: 'DEMO-INACTIVE-1',
    name: 'Retired bearing',
    active: false,
  }),
];
const header = (name: string) =>
  screen.getByRole('columnheader', { name: new RegExp(`^${name}`) });
const table = () => screen.findByRole('table', { name: 'Products' });
const productCalls = (api: { calls: { url: string }[] }) =>
  api.calls.filter((c) => c.url.startsWith('/api/v1/admin/products'));

describe('tables sort and paginate', () => {
  it('renders a captioned table with a polite result count and a text Inactive badge', async () => {
    setupAdmin('/admin/products', { [PRODUCTS]: paged(P) });
    const t = await table();
    expect(within(t).getAllByRole('row')).toHaveLength(4);
    expect(screen.getByText('3 products found')).toHaveAttribute(
      'aria-live',
      'polite',
    );
    expect(within(t).getByText('Inactive')).toBeInTheDocument();
    expect(within(t).getByRole('link', { name: '6205-2RS' })).toHaveAttribute(
      'href',
      '/admin/products/17',
    );
  });

  it('header buttons cycle ascending, descending, default and update aria-sort, the URL and the request', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/products', { [PRODUCTS]: paged(P) });
    await table();
    expect(header('Part number')).toHaveAttribute('aria-sort', 'ascending');
    expect(header('Name')).toHaveAttribute('aria-sort', 'none');
    // Only sortable columns announce a sort state.
    expect(header('Category')).not.toHaveAttribute('aria-sort');

    await user.click(within(header('Name')).getByRole('button'));
    await waitFor(() =>
      expect(loc()).toHaveTextContent('/admin/products?sort=name'),
    );
    expect(header('Name')).toHaveAttribute('aria-sort', 'ascending');
    expect(header('Part number')).toHaveAttribute('aria-sort', 'none');
    await waitFor(() =>
      expect(productCalls(api).at(-1)?.url).toBe(
        '/api/v1/admin/products?sort=name',
      ),
    );

    await user.click(within(header('Name')).getByRole('button'));
    await waitFor(() =>
      expect(loc()).toHaveTextContent('/admin/products?sort=-name'),
    );
    expect(header('Name')).toHaveAttribute('aria-sort', 'descending');
    await waitFor(() =>
      expect(productCalls(api).at(-1)?.url).toBe(
        '/api/v1/admin/products?sort=-name',
      ),
    );

    await user.click(within(header('Name')).getByRole('button'));
    await waitFor(() => expect(loc()).toHaveTextContent(/^\/admin\/products$/));
    expect(header('Part number')).toHaveAttribute('aria-sort', 'ascending');
    expect(header('Name')).toHaveAttribute('aria-sort', 'none');
  });

  it('Next and Previous move the page in the URL and the request, and are disabled at the ends', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/products', {
      [PRODUCTS]: (req) => {
        const page = Number(
          new URL(req.url, 'http://x').searchParams.get('page') ?? 1,
        );
        return paged(P, { current_page: page, last_page: 2, total: 40 });
      },
    });
    await table();
    const nav = screen.getByRole('navigation', { name: 'Pagination' });
    expect(
      within(nav).getByRole('button', { name: 'Previous' }),
    ).toBeDisabled();
    expect(within(nav).getByText('Page 1 of 2')).toBeInTheDocument();

    await user.click(within(nav).getByRole('button', { name: 'Next' }));
    await waitFor(() =>
      expect(loc()).toHaveTextContent('/admin/products?page=2'),
    );
    await waitFor(() =>
      expect(
        within(
          screen.getByRole('navigation', { name: 'Pagination' }),
        ).getByText('Page 2 of 2'),
      ).toBeInTheDocument(),
    );
    expect(productCalls(api).at(-1)?.url).toBe('/api/v1/admin/products?page=2');
    const nav2 = screen.getByRole('navigation', { name: 'Pagination' });
    expect(within(nav2).getByRole('button', { name: 'Next' })).toBeDisabled();

    await user.click(within(nav2).getByRole('button', { name: 'Previous' }));
    await waitFor(() => expect(loc()).toHaveTextContent(/^\/admin\/products$/));
  });

  it('restores sort, page and filters from the URL and never sends per_page', async () => {
    const { api } = setupAdmin(
      '/admin/products?q=62&active=false&stock_status=low_stock&sort=-price&page=2',
      { [PRODUCTS]: paged(P, { current_page: 2, last_page: 3, total: 60 }) },
    );
    await table();
    expect(header('Price')).toHaveAttribute('aria-sort', 'descending');
    expect(screen.getByLabelText('Status')).toHaveValue('false');
    expect(screen.getByLabelText('Stock')).toHaveValue('low_stock');
    expect(screen.getByLabelText('Part number')).toHaveValue('62');
    expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
    const url = new URL(productCalls(api)[0].url, 'http://x');
    expect(Object.fromEntries(url.searchParams)).toEqual({
      q: '62',
      active: 'false',
      stock_status: 'low_stock',
      sort: '-price',
      page: '2',
    });
  });

  it('drops invalid URL values instead of sending them', async () => {
    const { api } = setupAdmin(
      '/admin/products?sort=bogus&page=0&active=maybe&stock_status=x&category=BAD%20SLUG',
      { [PRODUCTS]: paged(P) },
    );
    await table();
    expect(productCalls(api)[0].url).toBe('/api/v1/admin/products');
    expect(screen.getByLabelText('Status')).toHaveValue('');
  });

  it('the page returns to 1 when a filter changes', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products?page=2', {
      [PRODUCTS]: paged(P, { current_page: 2, last_page: 3, total: 60 }),
    });
    await table();
    await user.selectOptions(screen.getByLabelText('Status'), 'false');
    await waitFor(() =>
      expect(loc()).toHaveTextContent(/^\/admin\/products\?active=false$/),
    );
  });

  it('a page past the end jumps to the last page', async () => {
    setupAdmin('/admin/products?page=9', {
      [PRODUCTS]: (req) => {
        const page = Number(
          new URL(req.url, 'http://x').searchParams.get('page'),
        );
        return page > 2
          ? paged([], { current_page: page, last_page: 2, total: 30 })
          : paged(P, { current_page: page, last_page: 2, total: 30 });
      },
    });
    await waitFor(() => expect(loc()).toHaveTextContent('page=2'));
    await table();
  });

  it('shows a loading skeleton first', async () => {
    setupAdmin('/admin/products', { [PRODUCTS]: () => new Promise(() => {}) });
    expect(
      await screen.findByRole('status', { name: 'Loading Products' }),
    ).toHaveAttribute('aria-busy', 'true');
  });

  it('shows the plain empty message without filters', async () => {
    setupAdmin('/admin/products', { [PRODUCTS]: paged([]) });
    expect(await screen.findByText('No products yet.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Clear filters' })).toBeNull();
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('shows an empty state with Clear filters when filters are active, and clearing restores the list', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products?active=false&sort=-name', {
      [PRODUCTS]: (req) =>
        req.url.includes('active=false') ? paged([]) : paged(P),
    });
    expect(
      await screen.findByText('No results match your filters.'),
    ).toBeInTheDocument();
    await user.click(
      screen.getAllByRole('button', { name: 'Clear filters' })[0],
    );
    await table();
    // The sort is kept, the filters are gone.
    expect(loc()).toHaveTextContent(/^\/admin\/products\?sort=-name$/);
  });

  it('shows an error with a retry that refetches', async () => {
    const user = userEvent.setup();
    let n = 0;
    setupAdmin('/admin/products', {
      [PRODUCTS]: () => (n++ === 0 ? { status: 500, body: {} } : paged(P)),
    });
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /try again/i }));
    await table();
  });

  it('shows the 429 message with the wait time', async () => {
    setupAdmin('/admin/products', {
      [PRODUCTS]: {
        status: 429,
        body: { message: 'Too Many Attempts.' },
        headers: { 'Retry-After': '12' },
      },
    });
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'try again in 12 seconds',
    );
  });

  it('typing in the search box updates the URL by replacing history, not pushing', async () => {
    const user = userEvent.setup();
    const { router } = setupAdmin('/admin/products', { [PRODUCTS]: paged(P) });
    await table();
    const before = router.state.historyAction;
    await user.type(screen.getByLabelText('Part number'), '6205');
    await waitFor(() => expect(loc()).toHaveTextContent('?q=6205'));
    expect(before).toBe('POP');
    expect(router.state.historyAction).toBe('REPLACE');
  });
});
