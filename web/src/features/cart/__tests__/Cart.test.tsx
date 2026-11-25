import { cleanup, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { admin, renderApp, staff, type Spec } from '../../../test/utils';
import { deferred, loc } from '../../catalog/__tests__/fixtures';
import {
  CART,
  ME,
  P1,
  P2,
  P4,
  cartOf,
  cartServer,
  line,
  setupApp,
} from './fixtures';
import { meOk } from '../../../test/utils';

const row = (name: string) =>
  screen.getByRole('link', { name }).closest('tr') as HTMLElement;
const catalogRow = (name: string) => row(name);

describe('add to cart (catalog list)', () => {
  it('POSTs exactly {product_id, quantity}, updates the badge, announces and lists the line', async () => {
    const user = userEvent.setup();
    const srv = cartServer();
    const { api } = setupApp('/catalog', srv.routes);
    await screen.findByRole('link', { name: '6205-2RS' });
    const qty = screen.getByLabelText('Quantity for 6205-2RS');
    await user.clear(qty);
    await user.type(qty, '3');
    await user.click(
      within(catalogRow('6205-2RS')).getByRole('button', {
        name: 'Add to cart',
      }),
    );

    const post = api.to('POST /api/v1/cart/items')[0];
    expect(post.body).toEqual({ product_id: 17, quantity: 3 });
    expect(Object.keys(post.body!).sort()).toEqual(['product_id', 'quantity']);
    const live = await screen.findByText('Added 3 x 6205-2RS to your cart');
    expect(live).toHaveAttribute('aria-live', 'polite');
    expect(
      await screen.findByRole('link', { name: 'Cart, 1 item' }),
    ).toHaveTextContent('Cart1');

    await user.click(screen.getByRole('link', { name: 'Cart, 1 item' }));
    const table = await screen.findByRole('table', {
      name: 'Items in your cart',
    });
    expect(within(table).getByText('6205-2RS')).toBeInTheDocument();
    expect(within(table).getByLabelText('Quantity for 6205-2RS')).toHaveValue(
      '3',
    );
    expect(loc()).toBe('/cart');
  });

  it.each(['0', '10000', '1.5', '', 'abc'])(
    'quantity %j shows an inline error and sends nothing',
    async (bad) => {
      const user = userEvent.setup();
      const { api } = setupApp('/catalog', cartServer().routes);
      await screen.findByRole('link', { name: '6205-2RS' });
      const qty = screen.getByLabelText('Quantity for 6205-2RS');
      await user.clear(qty);
      if (bad) await user.type(qty, bad);
      await user.click(
        within(catalogRow('6205-2RS')).getByRole('button', {
          name: 'Add to cart',
        }),
      );
      expect(await screen.findByRole('alert')).toHaveTextContent(/1 to 9999/);
      expect(api.to('POST /api/v1/cart/items')).toHaveLength(0);
    },
  );

  it('price-on-request and out-of-stock products show the reason and no enabled button', async () => {
    setupApp('/catalog', cartServer().routes);
    await screen.findByRole('link', { name: 'NU206' });
    for (const [part, reason] of [
      ['NU206', 'Price on request'],
      ['6305', 'Out of stock'],
    ]) {
      const r = within(catalogRow(part));
      expect(r.getByRole('button', { name: 'Add to cart' })).toBeDisabled();
      expect(r.getAllByText(reason).length).toBeGreaterThan(0);
      expect(r.queryByLabelText(`Quantity for ${part}`)).toBeNull();
    }
  });

  it('shows the 422 product_id message inline', async () => {
    const user = userEvent.setup();
    setupApp('/catalog', {
      'POST /api/v1/cart/items': {
        status: 422,
        body: {
          message: 'x',
          errors: { product_id: ['The selected product is not available.'] },
        },
      },
    });
    await screen.findByRole('link', { name: '6205-2RS' });
    await user.click(
      within(catalogRow('6205-2RS')).getByRole('button', {
        name: 'Add to cart',
      }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The selected product is not available.',
    );
  });

  it('shows the 429 wait time from Retry-After', async () => {
    const user = userEvent.setup();
    setupApp('/catalog', {
      'POST /api/v1/cart/items': {
        status: 429,
        headers: { 'Retry-After': '42' },
        body: { message: 'Too many attempts. Please try again in 42 seconds.' },
      },
    });
    await screen.findByRole('link', { name: '6205-2RS' });
    await user.click(
      within(catalogRow('6205-2RS')).getByRole('button', {
        name: 'Add to cart',
      }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent('42 seconds');
  });

  it('the product detail page has the same control', async () => {
    const user = userEvent.setup();
    const srv = cartServer();
    const { api } = setupApp('/catalog/17', {
      ...srv.routes,
      'GET /api/v1/products/17': {
        body: { data: { ...P1, description: null } },
      },
    });
    await screen.findByRole('heading', { name: '6205-2RS' });
    await user.click(screen.getByRole('button', { name: 'Add to cart' }));
    await screen.findByText('Added 1 x 6205-2RS to your cart');
    expect(api.to('POST /api/v1/cart/items')[0].body).toEqual({
      product_id: 17,
      quantity: 1,
    });
  });

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])(
    '%s gets no Cart item, never calls the cart API, and /cart is Not allowed',
    async (_n, who) => {
      const { api } = setupApp('/catalog', { [ME]: meOk(who) }, who);
      await screen.findByRole('link', { name: '6205-2RS' });
      expect(screen.queryByRole('link', { name: /^Cart/ })).toBeNull();
      cleanup();
      setupApp('/cart', { [ME]: meOk(who) }, who);
      expect(
        await screen.findByRole('heading', { name: 'Not allowed' }),
      ).toBeInTheDocument();
      expect(api.calls.some((c) => c.url.includes('/cart'))).toBe(false);
    },
  );

  it('staff on the product detail page see no controls', async () => {
    setupApp(
      '/catalog/17',
      {
        [ME]: meOk(staff),
        'GET /api/v1/products/17': {
          body: { data: { ...P1, description: null } },
        },
      },
      staff,
    );
    await screen.findByRole('heading', { name: '6205-2RS' });
    expect(screen.queryByRole('button', { name: /cart/i })).toBeNull();
  });
});

describe('cart page', () => {
  it('shows the loading skeleton first', async () => {
    const d = deferred<Spec>();
    setupApp('/cart', { [CART]: () => d.promise });
    expect(
      await screen.findByRole('status', { name: 'Loading cart' }),
    ).toHaveAttribute('aria-busy', 'true');
    d.resolve({ body: cartOf([line(P1, 2)]) });
    await screen.findByRole('table', { name: 'Items in your cart' });
  });

  it('empty cart shows the message and a catalog link', async () => {
    setupApp('/cart');
    expect(await screen.findByText(/Your cart is empty/)).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Browse the catalog' }),
    ).toHaveAttribute('href', '/catalog');
  });

  it('error shows an alert and Try again refetches', async () => {
    const user = userEvent.setup();
    let fail = true;
    setupApp('/cart', {
      [CART]: () =>
        fail
          ? { status: 500, body: { message: 'boom' } }
          : { body: cartOf([line(P1, 1)]) },
    });
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    fail = false;
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await screen.findByRole('table', { name: 'Items in your cart' });
  });

  it('a 429 shows the seconds', async () => {
    setupApp('/cart', {
      [CART]: {
        status: 429,
        headers: { 'Retry-After': '17' },
        body: { message: 'x' },
      },
    });
    expect(await screen.findByRole('alert')).toHaveTextContent('17 seconds');
  });

  it('changing the quantity PATCHes the product id and re-renders totals from the response', async () => {
    const user = userEvent.setup();
    const srv = cartServer([line(P1, 2)]);
    const { api } = setupApp('/cart', srv.routes);
    const qty = await screen.findByLabelText('Quantity for 6205-2RS');
    expect(screen.getByText('Subtotal: $2,501.00')).toBeInTheDocument();
    await user.clear(qty);
    await user.type(qty, '5{Enter}');
    expect(await screen.findByText('Subtotal: $6,252.50')).toBeInTheDocument();
    expect(api.to('PATCH /api/v1/cart/items/17')[0].body).toEqual({
      quantity: 5,
    });
    expect(within(row('6205-2RS')).getByText('$6,252.50')).toBeInTheDocument();
    expect(screen.getByText('Quantity updated')).toBeInTheDocument();
  });

  it.each(['0', '10000', '', '1.5'])(
    'invalid quantity %j shows an inline error and sends nothing',
    async (bad) => {
      const user = userEvent.setup();
      const { api } = setupApp('/cart', cartServer([line(P1, 2)]).routes);
      const qty = await screen.findByLabelText('Quantity for 6205-2RS');
      await user.clear(qty);
      if (bad) await user.type(qty, bad);
      await user.click(
        screen.getByRole('button', { name: /Update quantity for 6205-2RS/ }),
      );
      expect(await screen.findByRole('alert')).toHaveTextContent(/1 to 9999/);
      expect(qty).toHaveAttribute('aria-invalid', 'true');
      expect(api.to('PATCH /api/v1/cart/items/17')).toHaveLength(0);
    },
  );

  it('Remove sends DELETE, the line disappears and the last removal shows the empty state', async () => {
    const user = userEvent.setup();
    const srv = cartServer([line(P1, 2)]);
    const { api } = setupApp('/cart', srv.routes);
    await user.click(
      await screen.findByRole('button', { name: 'Remove 6205-2RS from cart' }),
    );
    expect(await screen.findByText(/Your cart is empty/)).toBeInTheDocument();
    expect(api.to('DELETE /api/v1/cart/items/17')).toHaveLength(1);
    expect(screen.getByText('Item removed')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Your cart' })).toHaveFocus();
  });

  it('Clear cart needs the confirm step (Keep items sends nothing)', async () => {
    const user = userEvent.setup();
    const srv = cartServer([line(P1, 2)]);
    const { api } = setupApp('/cart', srv.routes);
    await user.click(await screen.findByRole('button', { name: 'Clear cart' }));
    await user.click(screen.getByRole('button', { name: 'Keep items' }));
    expect(api.to('DELETE /api/v1/cart')).toHaveLength(0);
    await user.click(screen.getByRole('button', { name: 'Clear cart' }));
    await user.click(screen.getByRole('button', { name: 'Yes, clear cart' }));
    expect(await screen.findByText(/Your cart is empty/)).toBeInTheDocument();
    expect(api.to('DELETE /api/v1/cart')).toHaveLength(1);
  });

  it('unavailable lines show their reason, keep the server subtotal and block checkout', async () => {
    setupApp('/cart', {
      [CART]: {
        body: cartOf([
          line(P1, 2),
          line(P4, 1, { available: false, unavailable_reason: 'out_of_stock' }),
          line(P2, 1, {
            available: false,
            unavailable_reason: 'price_on_request',
          }),
        ]),
      },
    });
    await screen.findByRole('table', { name: 'Items in your cart' });
    expect(within(row('6305')).getByText('Out of stock')).toBeInTheDocument();
    expect(
      within(row('NU206')).getByText('Price on request'),
    ).toBeInTheDocument();
    // 2 x 1250.50 only: the unavailable lines are not in the server subtotal.
    expect(screen.getByText('Subtotal: $2,501.00')).toBeInTheDocument();
    const btn = screen.getByRole('button', { name: 'Proceed to checkout' });
    expect(btn).toBeDisabled();
    expect(btn).toHaveAccessibleDescription(/Remove the unavailable items/);
    expect(
      screen.queryByRole('link', { name: 'Proceed to checkout' }),
    ).toBeNull();
    // Only Remove works on an unavailable line.
    expect(
      within(row('6305')).getByLabelText('Quantity for 6305'),
    ).toBeDisabled();
  });

  it('Proceed to checkout is a link when checkout is possible', async () => {
    setupApp('/cart', { [CART]: { body: cartOf([line(P1, 1)]) } });
    expect(
      await screen.findByRole('link', { name: 'Proceed to checkout' }),
    ).toHaveAttribute('href', '/checkout');
  });

  it.each([
    ['USD', '$1,250.50'],
    ['EUR', '€1,250.50'],
  ])('formats money from the body currency (%s)', async (cur, text) => {
    setupApp('/cart', { [CART]: { body: cartOf([line(P1, 1)], cur) } });
    await screen.findByRole('table', { name: 'Items in your cart' });
    expect(screen.getAllByText(text).length).toBeGreaterThan(0);
  });
});

describe('cart persistence', () => {
  it('survives a reload: the new session refetches GET /cart and renders the same lines and badge, no web storage', async () => {
    const user = userEvent.setup();
    const setItem = vi.spyOn(Storage.prototype, 'setItem');
    const srv = cartServer();
    const { api } = setupApp('/catalog', srv.routes);
    await screen.findByRole('link', { name: '6205-2RS' });
    await user.click(
      within(catalogRow('6205-2RS')).getByRole('button', {
        name: 'Add to cart',
      }),
    );
    await screen.findByRole('link', { name: 'Cart, 1 item' });

    cleanup(); // the page is gone, the "server" keeps the cart
    const before = api.to(CART).length;
    renderApp('/cart');
    await screen.findByRole('table', { name: 'Items in your cart' });
    expect(api.to(CART).length).toBeGreaterThan(before);
    expect(screen.getByLabelText('Quantity for 6205-2RS')).toHaveValue('1');
    expect(
      screen.getByRole('link', { name: 'Cart, 1 item' }),
    ).toBeInTheDocument();
    expect(setItem).not.toHaveBeenCalled();
    setItem.mockRestore();
  });

  it('logout clears the cart cache so the next user never sees it', async () => {
    const user = userEvent.setup();
    const { client } = setupApp('/cart', {
      [CART]: { body: cartOf([line(P1, 1)]) },
      'POST /api/v1/auth/logout': { status: 204 },
    });
    await screen.findByRole('table', { name: 'Items in your cart' });
    expect(client.getQueryData(['cart'])).toBeDefined();
    await user.click(screen.getByRole('button', { name: 'Log out' }));
    await screen.findByLabelText('Email');
    expect(client.getQueryData(['cart'])).toBeUndefined();
  });
});
