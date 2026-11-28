import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  LIST,
  ME,
  P1,
  P2,
  P3,
  P4,
  deferred,
  filtersBody,
  listBody,
  listUrls,
  loc,
  setup,
} from './fixtures';
import { admin, meOk, staff, type Spec } from '../../../test/utils';

afterEach(() => vi.useRealTimers());

const fakeTimers = () => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime.bind(vi) });
};
const checkbox = (name: string) => screen.findByRole('checkbox', { name });
const rows = () =>
  screen.findAllByRole('link', { name: /^(6205-2RS|NU206|30205|6305)$/ });

describe('filters update the URL', () => {
  it('brand ticks write a CSV; other facets and sort write their params', async () => {
    const user = userEvent.setup();
    setup('/catalog');
    await user.click(await checkbox('SKF'));
    expect(loc()).toBe('/catalog?brand=SKF');
    await user.click(await checkbox('NSK'));
    expect(loc()).toBe('/catalog?brand=SKF,NSK');
    await user.click(await checkbox('Deep groove ball bearings'));
    await user.click(await checkbox('2RS (rubber sealed)'));
    await user.click(await checkbox('Low stock'));
    await user.selectOptions(
      screen.getByLabelText('Sort by'),
      'Price (descending)',
    );
    expect(loc()).toBe(
      '/catalog?category=deep-groove-ball&brand=SKF,NSK&seal_type=2rs&stock_status=low_stock&sort=-price',
    );
  });

  it('typing a range writes the param only after the 400 ms debounce', async () => {
    const user = fakeTimers();
    setup('/catalog');
    await screen.findByRole('checkbox', { name: 'SKF' });
    await user.type(screen.getByLabelText('Outer diameter min (mm)'), '40');
    expect(loc()).toBe('/catalog');
    await act(() => vi.advanceTimersByTimeAsync(400));
    expect(loc()).toBe('/catalog?outer_diameter_min=40');
  });

  it('any filter change removes page; Clear all empties the query string', async () => {
    const user = userEvent.setup();
    setup('/catalog?brand=SKF&page=2&sort=price', {
      [LIST]: {
        body: listBody([P1], { current_page: 2, last_page: 3, total: 50 }),
      },
    });
    await user.click(await checkbox('NSK'));
    expect(loc()).toBe('/catalog?brand=SKF,NSK&sort=price');
    await user.click(
      screen.getAllByRole('button', { name: 'Clear all filters' })[0],
    );
    expect(loc()).toBe('/catalog');
  });
});

describe('filters restore from the URL', () => {
  const url =
    '/catalog?q=6205&category=deep-groove-ball&brand=SKF,NSK&outer_diameter_min=40&sort=-price&page=2';

  it('ticks, fills and selects from the URL and sends exactly those params', async () => {
    const { api } = setup(url, {
      [LIST]: {
        body: listBody([P1], { current_page: 2, last_page: 3, total: 50 }),
      },
    });
    expect(await checkbox('SKF')).toBeChecked();
    expect(await checkbox('NSK')).toBeChecked();
    expect(await checkbox('FAG')).not.toBeChecked();
    expect(await checkbox('Deep groove ball bearings')).toBeChecked();
    expect(screen.getByLabelText('Part number')).toHaveValue('6205');
    expect(screen.getByLabelText('Outer diameter min (mm)')).toHaveValue('40');
    expect(screen.getByLabelText('Sort by')).toHaveValue('-price');
    await rows();
    expect(listUrls(api)).toEqual([
      '/api/v1/products?q=6205&category=deep-groove-ball&brand=SKF,NSK&outer_diameter_min=40&sort=-price&page=2',
    ]);
  });

  it('back and forward restore the filter state and the results', async () => {
    const user = userEvent.setup();
    const { router, api } = setup('/catalog', {
      [LIST]: (req) => ({
        body: listBody(req.url.includes('brand=SKF') ? [P1] : [P1, P2, P3]),
      }),
    });
    await user.click(await checkbox('SKF'));
    await waitFor(() => expect(screen.queryByText('NU206')).toBeNull());
    await act(() => router.navigate(-1));
    await waitFor(() =>
      expect(screen.getByRole('checkbox', { name: 'SKF' })).not.toBeChecked(),
    );
    expect(await screen.findByText('NU206')).toBeInTheDocument();
    await act(() => router.navigate(1));
    await waitFor(() =>
      expect(screen.getByRole('checkbox', { name: 'SKF' })).toBeChecked(),
    );
    await waitFor(() => expect(screen.queryByText('NU206')).toBeNull());
    expect(listUrls(api)).toContain('/api/v1/products?brand=SKF');
  });
});

describe('empty and error states', () => {
  it('empty with filters offers Clear all filters, which works', async () => {
    const user = userEvent.setup();
    setup('/catalog?brand=SKF', {
      [LIST]: (req) => ({
        body: listBody(req.url.includes('brand') ? [] : [P1]),
      }),
    });
    const msg = await screen.findByText('No products match your search.');
    expect(screen.getByText('0 products found')).toBeInTheDocument();
    await user.click(
      within(msg.parentElement!).getByRole('button', {
        name: 'Clear all filters',
      }),
    );
    expect(
      await screen.findByRole('link', { name: '6205-2RS' }),
    ).toBeInTheDocument();
    expect(loc()).toBe('/catalog');
  });

  it('empty without filters says the catalog is empty and has no clear button', async () => {
    setup('/catalog', { [LIST]: { body: listBody([]) } });
    expect(
      await screen.findByText('The catalog is empty.'),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /clear all/i })).toBeNull();
  });

  it('a 500 shows an alert; Try again refetches and recovers', async () => {
    const user = userEvent.setup();
    let n = 0;
    setup('/catalog', {
      [LIST]: () =>
        n++ === 0 ? { status: 500, body: {} } : { body: listBody([P1]) },
    });
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /something went wrong/i,
    );
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(
      await screen.findByRole('link', { name: '6205-2RS' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('a 429 shows the seconds from Retry-After', async () => {
    setup('/catalog', {
      [LIST]: {
        status: 429,
        body: { message: 'Too many attempts.' },
        headers: { 'Retry-After': '42' },
      },
    });
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Too many requests, try again in 42 seconds',
    );
  });

  it('a failing filters request also shows the alert and no table', async () => {
    setup('/catalog', {
      'GET /api/v1/products/filters': { status: 500, body: {} },
    });
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('a 422 offers Clear all filters', async () => {
    const user = userEvent.setup();
    setup('/catalog?category=nope', {
      [LIST]: (req): Spec =>
        req.url.includes('category=nope')
          ? {
              status: 422,
              body: { message: 'bad', errors: { category: ['bad'] } },
            }
          : { body: listBody([P1]) },
    });
    const alert = await screen.findByRole('alert');
    await user.click(
      within(alert).getByRole('button', { name: 'Clear all filters' }),
    );
    expect(
      await screen.findByRole('link', { name: '6205-2RS' }),
    ).toBeInTheDocument();
  });

  it('a categories failure only disables the Category facet', async () => {
    setup('/catalog', { 'GET /api/v1/categories': { status: 500, body: {} } });
    expect(
      await screen.findByText('Could not load categories.'),
    ).toBeInTheDocument();
    expect(
      await screen.findByRole('link', { name: '6205-2RS' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: 'SKF' })).toBeInTheDocument();
  });
});

describe('search and range debounce', () => {
  it('typing sends one request after 300 ms, using replace', async () => {
    const user = fakeTimers();
    const { api, router } = setup('/catalog');
    await rows();
    const before = listUrls(api).length;
    await user.type(screen.getByLabelText('Part number'), '6205');
    expect(listUrls(api)).toHaveLength(before);
    await act(() => vi.advanceTimersByTimeAsync(300));
    await waitFor(() => expect(loc()).toBe('/catalog?q=6205'));
    expect(router.state.historyAction).toBe('REPLACE');
    await waitFor(() => expect(listUrls(api)).toHaveLength(before + 1));
    expect(listUrls(api).at(-1)).toBe('/api/v1/products?q=6205');
  });

  it('a filter chip keeps its visible text at the start of its name (WCAG 2.5.3)', async () => {
    setup('/catalog?q=6205');
    const chip = await screen.findByRole('button', {
      name: /^Part number: 6205/,
    });
    expect(chip).not.toHaveAttribute('aria-label');
    expect(chip).toHaveAccessibleName('Part number: 6205 remove filter');
  });

  it('a q changed by back/forward updates the input', async () => {
    const user = userEvent.setup();
    const { router } = setup('/catalog?q=6205');
    const input = await screen.findByLabelText('Part number');
    expect(input).toHaveValue('6205');
    await user.click(
      screen.getByRole('button', { name: 'Part number: 6205 remove filter' }),
    );
    await waitFor(() => expect(input).toHaveValue(''));
    await act(() => router.navigate(-1));
    await waitFor(() => expect(input).toHaveValue('6205'));
  });

  it('a pending search commit does not overwrite a later checkbox change', async () => {
    const user = fakeTimers();
    setup('/catalog');
    await user.type(await screen.findByLabelText('Part number'), '62');
    await user.click(await checkbox('SKF'));
    await act(() => vi.advanceTimersByTimeAsync(300));
    await waitFor(() => expect(loc()).toBe('/catalog?q=62&brand=SKF'));
  });
});

describe('invalid URL values', () => {
  it('are dropped and never sent', async () => {
    const { api } = setup(
      '/catalog?sort=evil&page=-3&outer_diameter_min=abc&seal_type=rubber',
    );
    await rows();
    expect(listUrls(api)).toEqual(['/api/v1/products']);
    expect(screen.getByLabelText('Sort by')).toHaveValue('');
    expect(screen.getByRole('alert')).toHaveTextContent(
      /between 0\.01 and 5000/,
    );
  });

  it('min greater than max in the URL shows the inline error and is not sent', async () => {
    const { api } = setup(
      '/catalog?outer_diameter_min=60&outer_diameter_max=50',
    );
    await rows();
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Minimum must not exceed maximum.',
    );
    expect(
      screen.getByLabelText('Outer diameter min (mm)'),
    ).toHaveAccessibleDescription('Minimum must not exceed maximum.');
    expect(listUrls(api).join()).not.toContain('outer_diameter');
  });

  it('typing min greater than max shows the error and sends nothing', async () => {
    const user = fakeTimers();
    const { api } = setup('/catalog');
    await rows();
    const before = listUrls(api).length;
    await user.type(screen.getByLabelText('Width min (mm)'), '60');
    await user.type(screen.getByLabelText('Width max (mm)'), '50');
    await act(() => vi.advanceTimersByTimeAsync(400));
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Minimum must not exceed maximum.',
    );
    expect(listUrls(api)).toHaveLength(before);
    expect(loc()).not.toContain('width');
  });
});

describe('loading', () => {
  it('first load shows a busy skeleton, not the table', async () => {
    const d = deferred<Spec>();
    setup('/catalog', { [LIST]: () => d.promise });
    const sk = await screen.findByRole('status', { name: 'Loading products' });
    expect(sk).toHaveAttribute('aria-busy', 'true');
    expect(screen.queryByRole('table')).toBeNull();
    d.resolve({ body: listBody([P1]) });
    expect(await screen.findByRole('table')).toBeInTheDocument();
  });

  it('keeps the previous rows (busy) while the next page loads', async () => {
    const user = userEvent.setup();
    const d = deferred<Spec>();
    setup('/catalog', {
      [LIST]: (req) =>
        req.url.includes('page=2')
          ? d.promise
          : { body: listBody([P1], { last_page: 2, total: 30 }) },
    });
    await user.click(await screen.findByRole('button', { name: 'Next' }));
    expect(screen.getByRole('link', { name: '6205-2RS' })).toBeInTheDocument();
    expect(screen.getByRole('table').parentElement).toHaveAttribute(
      'aria-busy',
      'true',
    );
    d.resolve({
      body: listBody([P2], { current_page: 2, last_page: 2, total: 30 }),
    });
    expect(
      await screen.findByRole('link', { name: 'NU206' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: '6205-2RS' })).toBeNull();
  });
});

describe('pagination', () => {
  const page = (n: number) => ({ current_page: n, last_page: 3, total: 60 });

  it('disables Previous on page 1, Next on the last page; Next sets page=2, focuses the heading', async () => {
    const user = userEvent.setup();
    const { api } = setup('/catalog', {
      [LIST]: (req) => ({
        body: listBody(
          [P1],
          page(Number(/page=(\d+)/.exec(req.url)?.[1] ?? 1)),
        ),
      }),
    });
    const prev = await screen.findByRole('button', { name: 'Previous' });
    expect(prev).toBeDisabled();
    expect(screen.getByText('Page 1 of 3')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(loc()).toBe('/catalog?page=2');
    expect(listUrls(api)).toContain('/api/v1/products?page=2');
    expect(screen.getByRole('heading', { name: 'Catalog' })).toHaveFocus();
    await screen.findByText('Page 2 of 3');
    await user.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText('Page 3 of 3');
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeEnabled();
  });

  it('a page beyond the last is replaced by the last page', async () => {
    const { router } = setup('/catalog?page=9', {
      [LIST]: (req) =>
        req.url.includes('page=9')
          ? { body: listBody([], { current_page: 9, last_page: 3, total: 60 }) }
          : {
              body: listBody([P1], {
                current_page: 3,
                last_page: 3,
                total: 60,
              }),
            },
    });
    await screen.findByText('Page 3 of 3');
    expect(loc()).toBe('/catalog?page=3');
    expect(router.state.historyAction).toBe('REPLACE');
  });
});

describe('price and labels', () => {
  it('formats USD, shows Price on request, never zero, and labels stock and seal', async () => {
    setup('/catalog', { [LIST]: { body: listBody([P1, P2, P3, P4]) } });
    const table = await screen.findByRole('table', {
      name: 'Product search results',
    });
    const t = within(table);
    expect(t.getByText('$1,250.50')).toBeInTheDocument();
    expect(t.getByText('$9.90')).toBeInTheDocument();
    // Customers also get the reason in the Order column (two cells, not one).
    expect(t.getAllByText('Price on request')).toHaveLength(2);
    expect(table).not.toHaveTextContent('$0.00');
    for (const label of ['In stock', 'On order', 'Low stock', 'Out of stock'])
      expect(t.getAllByText(label).length).toBeGreaterThan(0);
    expect(t.getAllByText('2RS (rubber sealed)')).toHaveLength(2);
    expect(t.getByText('ZZ (metal shielded)')).toBeInTheDocument();
    expect(t.getByText('Open')).toBeInTheDocument();
    expect(t.getByText('25 x 52 x 16.25 mm')).toBeInTheDocument();
  });

  it('uses the currency from /products/filters', async () => {
    setup('/catalog', {
      [LIST]: { body: listBody([P1]) },
      'GET /api/v1/products/filters': { body: filtersBody('EUR') },
    });
    expect(await screen.findByText('€1,250.50')).toBeInTheDocument();
  });
});

describe('accessibility', () => {
  it('names every control, groups facets, announces the count, labels chips and caption', async () => {
    setup('/catalog?brand=SKF&outer_diameter_min=40&outer_diameter_max=60');
    await rows();
    for (const legend of [
      'Category',
      'Brand',
      'Material',
      'Seal type',
      'Stock status',
    ])
      expect(screen.getByRole('group', { name: legend })).toBeInTheDocument();
    for (const name of ['SKF', 'Chrome steel', 'In stock', 'Open'])
      expect(screen.getByRole('checkbox', { name })).toBeInTheDocument();
    for (const l of [
      'Part number',
      'Inner diameter min (mm)',
      'Inner diameter max (mm)',
      'Outer diameter min (mm)',
      'Outer diameter max (mm)',
      'Width min (mm)',
      'Width max (mm)',
      'Sort by',
    ])
      expect(screen.getByLabelText(l)).toBeInTheDocument();
    expect(screen.getByLabelText('Part number')).toHaveAccessibleDescription(
      'e.g. 6205 or 6205-2RS',
    );
    expect(screen.getByLabelText('Outer diameter min (mm)')).toHaveAttribute(
      'placeholder',
      '10',
    );
    const count = screen.getByText('3 products found');
    expect(count).toHaveAttribute('role', 'status');
    expect(count).toHaveAttribute('aria-live', 'polite');
    expect(
      screen.getByRole('button', { name: 'Brand: SKF remove filter' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', {
        name: 'Outer diameter: 40 to 60 mm remove filter',
      }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('table', { name: 'Product search results' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('complementary', { name: 'Filters' }),
    ).toBeInTheDocument();
  });

  it('chip removal updates the URL', async () => {
    const user = userEvent.setup();
    setup('/catalog?brand=SKF,NSK');
    await user.click(
      await screen.findByRole('button', { name: 'Brand: SKF remove filter' }),
    );
    expect(loc()).toBe('/catalog?brand=NSK');
  });

  it('the mobile Filters button toggles aria-expanded', async () => {
    const user = userEvent.setup();
    setup('/catalog');
    const btn = await screen.findByRole('button', { name: 'Filters' });
    expect(btn).toHaveAttribute('aria-expanded', 'false');
    expect(btn).toHaveAttribute('aria-controls', 'catalog-filters');
    await user.click(btn);
    expect(btn).toHaveAttribute('aria-expanded', 'true');
    expect(document.getElementById('catalog-filters')).not.toBeNull();
  });

  it('customers get add-to-cart controls and a Request quote link only for price-on-request and out-of-stock rows', async () => {
    setup('/catalog', { [LIST]: { body: listBody([P1, P2, P3, P4]) } });
    await rows();
    expect(
      screen.getAllByRole('button', { name: 'Add to cart' }).length,
    ).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: /quote/i })).toBeNull();
    const links = screen.getAllByRole('link', { name: /request quote/i });
    // NU206 is price on request, 6305 is out of stock; the others keep Add to cart only.
    expect(links.map((a) => a.getAttribute('href'))).toEqual([
      '/quotes/new?product=18',
      '/quotes/new?product=20',
    ]);
  });

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])('%s sees no cart controls and no Order column', async (_n, user) => {
    setup('/catalog', { [ME]: meOk(user) });
    await rows();
    expect(screen.queryByRole('button', { name: /cart|quote/i })).toBeNull();
    expect(screen.queryByRole('link', { name: /request quote/i })).toBeNull();
    expect(screen.queryByRole('columnheader', { name: 'Order' })).toBeNull();
    expect(screen.queryByLabelText(/^Quantity for/)).toBeNull();
  });
});

describe('categories names in chips', () => {
  it('shows the category name, not the slug', async () => {
    setup('/catalog?category=tapered-roller');
    expect(
      await screen.findByRole('button', {
        name: 'Category: Tapered roller bearings remove filter',
      }),
    ).toBeInTheDocument();
  });
});
