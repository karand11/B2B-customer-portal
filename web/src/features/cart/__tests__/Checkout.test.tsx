import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { admin, meOk, type Spec } from '../../../test/utils';
import { deferred, loc } from '../../catalog/__tests__/fixtures';
import { CART, ME, P1, cartOf, line, order, setupApp } from './fixtures';

const POST = 'POST /api/v1/orders';
// Two P1 at 1250.50 each: the subtotal on screen, sent so a price change is never silent.
const SUB = 250100;
const full = { [CART]: { body: cartOf([line(P1, 2)]) } };
const place = (u: ReturnType<typeof userEvent.setup>) =>
  u.click(screen.getByRole('button', { name: 'Place order' }));

describe('checkout', () => {
  it('Place order sends no items or totals, empties the cart, navigates and shows the banner', async () => {
    const user = userEvent.setup();
    let placed = false;
    const { api } = setupApp('/checkout', {
      [CART]: () => ({ body: cartOf(placed ? [] : [line(P1, 2)]) }),
      [POST]: () => {
        placed = true;
        return { status: 201, body: { data: order() } };
      },
      'GET /api/v1/orders/ORD-K7Q2M-X9PA3': { body: { data: order() } },
    });
    await screen.findByRole('heading', { name: 'Checkout' });
    await screen.findByRole('link', { name: 'Cart 1 item' });
    await place(user);

    const banner = await screen.findByText(
      'Thank you. Order ORD-K7Q2M-X9PA3 was received and is pending confirmation.',
    );
    expect(banner).toHaveFocus();
    expect(loc()).toBe('/orders/ORD-K7Q2M-X9PA3');
    // Only the PO and the subtotal the user saw: never lines or prices.
    expect(api.to(POST)[0].body).toEqual({ expected_subtotal_cents: SUB });
    await waitFor(() =>
      expect(screen.queryByRole('link', { name: /^Cart \d/ })).toBeNull(),
    );
  });

  it('a double click sends one request and the button is disabled while pending', async () => {
    const user = userEvent.setup();
    const d = deferred<Spec>();
    const { api } = setupApp('/checkout', {
      ...full,
      [POST]: () => d.promise,
      'GET /api/v1/orders/ORD-K7Q2M-X9PA3': { body: { data: order() } },
    });
    const btn = await screen.findByRole('button', { name: 'Place order' });
    await user.dblClick(btn);
    expect(api.to(POST)).toHaveLength(1);
    expect(
      await screen.findByRole('button', { name: 'Placing order...' }),
    ).toBeDisabled();
    d.resolve({ status: 201, body: { data: order() } });
    await screen.findByText(/was received and is pending/);
    expect(api.to(POST)).toHaveLength(1);
  });

  it('sends a trimmed PO reference and shows it on the order page as plain text', async () => {
    const user = userEvent.setup();
    const po = '<b>x</b>';
    const { api } = setupApp('/checkout', {
      ...full,
      [POST]: {
        status: 201,
        body: { data: order({ po_reference: 'PO-4711/A' }) },
      },
      'GET /api/v1/orders/ORD-K7Q2M-X9PA3': {
        body: { data: order({ po_reference: po }) },
      },
    });
    await user.type(
      await screen.findByLabelText('PO reference (optional)'),
      '  PO-4711/A  ',
    );
    await place(user);
    expect(api.to(POST)[0].body).toEqual({
      po_reference: 'PO-4711/A',
      expected_subtotal_cents: SUB,
    });
    // Rendered as a text node: the markup is visible text, not an element.
    expect(
      await screen.findByText(/PO reference: <b>x<\/b>/),
    ).toBeInTheDocument();
    expect(document.querySelector('b')).toBeNull();
  });

  it.each([
    ['-lead', 'Must start with a letter or digit.'],
    ['#x', 'Must start with a letter or digit.'],
    ['ab$c', /Only letters, digits, spaces/],
    ['ab,c', /Only letters, digits, spaces/],
    ['x'.repeat(65), 'Use at most 64 characters.'],
  ])(
    'PO reference %j is rejected inline and nothing is sent',
    async (bad, msg) => {
      const user = userEvent.setup();
      const { api } = setupApp('/checkout', full);
      await user.type(
        await screen.findByLabelText('PO reference (optional)'),
        bad,
      );
      await place(user);
      expect(await screen.findByRole('alert')).toHaveTextContent(msg);
      expect(api.to(POST)).toHaveLength(0);
    },
  );

  it.each(['A', '7', 'a b', 'PO.1-2_3/4#5', 'x'.repeat(64)])(
    'PO reference %j is accepted',
    async (ok) => {
      const user = userEvent.setup();
      const { api } = setupApp('/checkout', {
        ...full,
        [POST]: { status: 201, body: { data: order() } },
        'GET /api/v1/orders/ORD-K7Q2M-X9PA3': { body: { data: order() } },
      });
      await user.type(
        await screen.findByLabelText('PO reference (optional)'),
        ok,
      );
      await place(user);
      await screen.findByText(/was received/);
      expect(api.to(POST)[0].body).toEqual({
        po_reference: ok,
        expected_subtotal_cents: SUB,
      });
    },
  );

  it('a blank PO reference is optional and sends no field', async () => {
    const user = userEvent.setup();
    const { api } = setupApp('/checkout', {
      ...full,
      [POST]: { status: 201, body: { data: order() } },
      'GET /api/v1/orders/ORD-K7Q2M-X9PA3': { body: { data: order() } },
    });
    await screen.findByLabelText('PO reference (optional)');
    await user.type(screen.getByLabelText('PO reference (optional)'), '   ');
    await place(user);
    await screen.findByText(/was received/);
    expect(api.to(POST)[0].body).toEqual({ expected_subtotal_cents: SUB });
  });

  it('a server 422 po_reference error shows inline and keeps the form usable', async () => {
    const user = userEvent.setup();
    setupApp('/checkout', {
      ...full,
      [POST]: {
        status: 422,
        body: {
          message: 'x',
          errors: { po_reference: ['Bad PO from server.'] },
        },
      },
    });
    await user.type(
      await screen.findByLabelText('PO reference (optional)'),
      'ABC',
    );
    await place(user);
    const field = await screen.findByLabelText('PO reference (optional)');
    expect(await screen.findByText('Bad PO from server.')).toHaveAttribute(
      'role',
      'alert',
    );
    expect(field).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByRole('button', { name: 'Place order' })).toBeEnabled();
    expect(loc()).toBe('/checkout');
  });

  it('a 422 cart error lists the messages, refetches the cart and stays on the page', async () => {
    const user = userEvent.setup();
    const { api } = setupApp('/checkout', {
      ...full,
      [POST]: {
        status: 422,
        body: {
          message: 'x',
          errors: {
            cart: ['6305 is out of stock.', 'NU206 is price on request.'],
          },
        },
      },
    });
    await screen.findByRole('button', { name: 'Place order' });
    const before = api.to(CART).length;
    await place(user);
    const alert = await screen.findByText('6305 is out of stock.');
    expect(alert.closest('[role="alert"]')).toHaveTextContent(
      'NU206 is price on request.',
    );
    expect(loc()).toBe('/checkout');
    await waitFor(() => expect(api.to(CART).length).toBeGreaterThan(before));
  });

  it('a 429 shows the wait time', async () => {
    const user = userEvent.setup();
    setupApp('/checkout', {
      ...full,
      [POST]: {
        status: 429,
        headers: { 'Retry-After': '30' },
        body: { message: 'x' },
      },
    });
    await screen.findByRole('button', { name: 'Place order' });
    await place(user);
    expect(await screen.findByRole('alert')).toHaveTextContent('30 seconds');
  });

  it('an empty cart redirects to /cart', async () => {
    setupApp('/checkout');
    expect(await screen.findByText(/Your cart is empty/)).toBeInTheDocument();
    expect(loc()).toBe('/cart');
  });

  it('non-customers see Not allowed', async () => {
    setupApp('/checkout', { [ME]: meOk(admin) }, admin);
    expect(
      await screen.findByRole('heading', { name: 'Not allowed' }),
    ).toBeInTheDocument();
  });
});
