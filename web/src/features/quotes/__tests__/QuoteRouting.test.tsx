import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import {
  admin,
  guest,
  meOk,
  mockApi,
  renderApp,
  staff,
} from '../../../test/utils';
import { P1, P2, setup } from '../../catalog/__tests__/fixtures';
import {
  DETAIL,
  LIST,
  ME,
  NUM,
  detail,
  listOf,
  quote,
  setupApp,
} from './fixtures';

const nav = () => within(screen.getByRole('navigation', { name: /main/i }));

describe('quote routing and navigation', () => {
  it('redirects a guest from a quote page to the login with next', async () => {
    mockApi({ [ME]: guest });
    renderApp(`/quotes/${NUM}`);
    await screen.findByLabelText('Email');
    expect(screen.getByTestId('loc')).toHaveTextContent(
      `/login?next=${encodeURIComponent(`/quotes/${NUM}`)}`,
    );
  });

  it('customers see the Quotes nav item, open the list and can open /quotes/new', async () => {
    const user = userEvent.setup();
    setupApp('/', { [LIST]: { body: listOf([]) } });
    await user.click(await screen.findByRole('link', { name: 'Quotes' }));
    expect(
      await screen.findByText('You have no quote requests yet.'),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('link', { name: 'New quote request' }));
    expect(
      await screen.findByRole('heading', { name: 'Request a quote' }),
    ).toBeInTheDocument();
    expect(screen.getByTestId('loc')).toHaveTextContent('/quotes/new');
  });

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])(
    '%s can still open /quotes (old URL) and has no Quotes top-nav item',
    async (_n, who) => {
      setupApp(
        '/quotes',
        { [LIST]: { body: listOf([]) }, [ME]: meOk(who) },
        who,
      );
      expect(await screen.findByText('No quotes.')).toBeInTheDocument();
      expect(nav().queryByRole('link', { name: 'Quotes' })).toBeNull();
      expect(
        nav().getByRole('link', { name: 'Back office' }),
      ).toBeInTheDocument();
    },
  );

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])('%s opening /quotes/new gets Not allowed', async (_n, who) => {
    const { api } = setupApp('/quotes/new', { [ME]: meOk(who) }, who);
    expect(
      await screen.findByRole('heading', { name: 'Not allowed' }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('heading', { name: 'Request a quote' }),
    ).toBeNull();
    expect(api.calls.some((c) => c.url.startsWith('/api/v1/quotes'))).toBe(
      false,
    );
  });

  it('logout clears the cached quotes', async () => {
    const user = userEvent.setup();
    const { client } = setupApp(`/quotes/${NUM}`, {
      [DETAIL]: detail(quote()),
      'POST /api/v1/auth/logout': { status: 204 },
    });
    await screen.findByRole('heading', { name: /Quote QUO-/ });
    expect(client.getQueryData(['quotes', 'detail', NUM])).toBeDefined();
    await user.click(screen.getByRole('button', { name: /log out/i }));
    await screen.findByLabelText('Email');
    expect(client.getQueryData(['quotes', 'detail', NUM])).toBeUndefined();
  });
});

describe('Request quote entry points', () => {
  const D = (p: typeof P1) => ({ body: { data: { ...p, description: null } } });

  it('the product page links to the form with the product id for a price-on-request product', async () => {
    setup('/catalog/18', { 'GET /api/v1/products/18': D(P2) });
    await screen.findByRole('heading', { name: 'NU206' });
    expect(screen.getByRole('link', { name: /Request quote/ })).toHaveAttribute(
      'href',
      '/quotes/new?product=18',
    );
    expect(
      screen.getByText('Price on request', { selector: 'span' }),
    ).toBeInTheDocument();
  });

  it('an out-of-stock product also gets the link, an in-stock priced product does not', async () => {
    setup('/catalog/20', {
      'GET /api/v1/products/20': D({
        ...P1,
        id: 20,
        part_number: '6305',
        stock_status: 'out_of_stock',
      }),
    });
    expect(
      await screen.findByRole('link', { name: /Request quote/ }),
    ).toHaveAttribute('href', '/quotes/new?product=20');
  });

  it('no link for an in-stock priced product', async () => {
    setup('/catalog/17', { 'GET /api/v1/products/17': D(P1) });
    await screen.findByRole('heading', { name: '6205-2RS' });
    expect(screen.queryByRole('link', { name: /Request quote/ })).toBeNull();
  });

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])('%s never sees the link', async (_n, who) => {
    setup('/catalog/18', { 'GET /api/v1/products/18': D(P2), [ME]: meOk(who) });
    await screen.findByRole('heading', { name: 'NU206' });
    expect(screen.queryByRole('link', { name: /Request quote/ })).toBeNull();
  });
});
