import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { loc } from '../../catalog/__tests__/fixtures';
import { CART, P1, cartOf, line, order, setupApp } from './fixtures';

const POST = 'POST /api/v1/orders';
const ORDER = 'GET /api/v1/orders/ORD-K7Q2M-X9PA3';

// The cart on screen: 2 x 1,250.50 = 2,501.00. The server now charges 1,300.00 each.
const oldCart = cartOf([line(P1, 2)]);
const newCart = cartOf([
  line(P1, 2, { unit_price_cents: 130000, line_total_cents: 260000 }),
]);
const conflict = {
  status: 409,
  headers: { 'X-Request-Id': 'req-0123456789' },
  body: {
    message:
      'The prices in your cart changed. Review the cart and place the order again.',
    errors: {
      expected_subtotal_cents: [
        'The prices in your cart changed. Review the cart and place the order again.',
      ],
    },
    cart: newCart.data,
  },
};

describe('checkout: price changed (409)', () => {
  it('shows the fresh totals, does not resubmit, and needs a second click that sends the new subtotal', async () => {
    const user = userEvent.setup();
    let n = 0;
    const { api } = setupApp('/checkout', {
      [CART]: { body: oldCart },
      [POST]: () =>
        n++ === 0
          ? conflict
          : { status: 201, body: { data: order({ subtotal_cents: 260000 }) } },
      [ORDER]: { body: { data: order() } },
    });
    await screen.findByRole('heading', { name: 'Checkout' });
    expect(screen.getByText('Total: $2,501.00')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Place order' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(
      'Prices in your cart changed. The new subtotal is $2,600.00 (was $2,501.00). Review and place the order again.',
    );
    expect(alert).toHaveFocus();
    // Fresh cart from the 409 body is on screen.
    expect(screen.getByText('Total: $2,600.00')).toBeInTheDocument();
    expect(screen.getAllByText('$1,300.00').length).toBeGreaterThan(0);
    expect(loc()).toBe('/checkout');
    expect(api.to(POST)).toHaveLength(1); // never an automatic resubmit
    expect(api.to(POST)[0].body).toEqual({ expected_subtotal_cents: 250100 });
    expect(screen.getByRole('button', { name: 'Place order' })).toBeEnabled();

    await user.click(screen.getByRole('button', { name: 'Place order' }));
    await screen.findByText(/was received and is pending/);
    expect(api.to(POST)).toHaveLength(2);
    expect(api.to(POST)[1].body).toEqual({ expected_subtotal_cents: 260000 });
    expect(loc()).toBe('/orders/ORD-K7Q2M-X9PA3');
  });

  it('keeps the PO reference typed before the 409 and sends it again', async () => {
    const user = userEvent.setup();
    let n = 0;
    const { api } = setupApp('/checkout', {
      [CART]: { body: oldCart },
      [POST]: () =>
        n++ === 0 ? conflict : { status: 201, body: { data: order() } },
      [ORDER]: { body: { data: order() } },
    });
    await user.type(
      await screen.findByLabelText('PO reference (optional)'),
      'PO-1',
    );
    await user.click(screen.getByRole('button', { name: 'Place order' }));
    await screen.findByText(/Prices in your cart changed/);
    expect(screen.getByLabelText('PO reference (optional)')).toHaveValue(
      'PO-1',
    );
    await user.click(screen.getByRole('button', { name: 'Place order' }));
    await screen.findByText(/was received/);
    expect(api.to(POST)[1].body).toEqual({
      po_reference: 'PO-1',
      expected_subtotal_cents: 260000,
    });
  });

  it('does not show the stale generic error or a reference for a 409', async () => {
    const user = userEvent.setup();
    setupApp('/checkout', { [CART]: { body: oldCart }, [POST]: conflict });
    await user.click(
      await screen.findByRole('button', { name: 'Place order' }),
    );
    await screen.findByText(/Prices in your cart changed/);
    expect(screen.queryByText(/Something went wrong/)).toBeNull();
    expect(screen.getAllByRole('alert')).toHaveLength(1);
  });

  it('a 409 without a usable cart falls back to a refetch and the plain message', async () => {
    const user = userEvent.setup();
    const { api } = setupApp('/checkout', {
      [CART]: { body: oldCart },
      [POST]: { status: 409, body: { message: 'x', errors: {} } },
    });
    await screen.findByRole('button', { name: 'Place order' });
    const before = api.to(CART).length;
    await user.click(screen.getByRole('button', { name: 'Place order' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /prices in your cart changed|Something went wrong/i,
    );
    await waitFor(() => expect(api.to(CART).length).toBeGreaterThan(before));
  });

  it('shows the request id on a generic server error, as plain text', async () => {
    const user = userEvent.setup();
    setupApp('/checkout', {
      [CART]: { body: oldCart },
      [POST]: {
        status: 500,
        headers: { 'X-Request-Id': 'abc-12345-def' },
        body: { message: 'Server Error' },
      },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Place order' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Something went wrong. Please try again. Reference: abc-12345-def',
    );
  });
});
