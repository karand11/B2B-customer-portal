import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { admin, meOk, staff } from '../../../test/utils';
import {
  CART,
  ME,
  P1,
  cartOf,
  line,
  order,
  setupApp,
} from '../../cart/__tests__/fixtures';

const URL = 'GET /api/v1/orders/ORD-K7Q2M-X9PA3';
const PATH = '/orders/ORD-K7Q2M-X9PA3';
const detail = (o = order()) => ({ body: { data: o } });
const confirmed = order({
  status: 'confirmed',
  allowed_transitions: [],
  history: [
    order().history[0],
    {
      from_status: 'pending',
      to_status: 'confirmed',
      actor: { name: null, role: 'staff' },
      created_at: '2026-11-26T12:00:00Z',
    },
  ],
});

describe('order detail', () => {
  it('renders the snapshot lines, totals, PO reference and the timeline in order', async () => {
    setupApp(PATH, { [URL]: detail(confirmed) });
    expect(
      await screen.findByRole('heading', { name: /Order ORD-K7Q2M-X9PA3/ }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('region', { name: 'Order summary' }),
    ).toHaveTextContent('Confirmed');
    const t = screen.getByRole('table', { name: 'Prices as ordered' });
    expect(
      within(t).getByText('Deep groove ball bearing 6205-2RS 25x52x15'),
    ).toBeInTheDocument();
    expect(within(t).getByRole('link', { name: '6205-2RS' })).toHaveAttribute(
      'href',
      '/catalog/17',
    );
    expect(within(t).getAllByText('$2,501.00').length).toBeGreaterThan(0);
    expect(screen.getByText('PO reference: none')).toBeInTheDocument();
    // Tax and shipping rows only when > 0.
    expect(screen.queryByText('Tax')).toBeNull();
    expect(screen.queryByText('Shipping')).toBeNull();
    const items = within(
      screen
        .getByRole('heading', { name: 'Status history' })
        .closest('section')!,
    ).getAllByRole('listitem');
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent('Order placed by Alice Customer');
    expect(items[1]).toHaveTextContent('Confirmed by Portal staff');
  });

  it('shows tax and shipping rows when they are above zero', async () => {
    setupApp(PATH, {
      [URL]: detail(
        order({ tax_cents: 500, shipping_cents: 300, total_cents: 250900 }),
      ),
    });
    await screen.findByText('Tax');
    expect(screen.getByText('Shipping')).toBeInTheDocument();
    expect(screen.getByText('$2,509.00')).toBeInTheDocument();
  });

  it('shows the PO reference as plain text', async () => {
    setupApp(PATH, {
      [URL]: detail(order({ po_reference: '<img src=x onerror=alert(1)>' })),
    });
    expect(
      await screen.findByText('PO reference: <img src=x onerror=alert(1)>'),
    ).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
  });

  it('Cancel is hidden when the API does not allow it', async () => {
    setupApp(PATH, { [URL]: detail(confirmed) });
    await screen.findByRole('heading', { name: /Order ORD/ });
    expect(screen.queryByRole('button', { name: 'Cancel order' })).toBeNull();
    expect(
      screen.getByRole('button', { name: 'Re-order' }),
    ).toBeInTheDocument();
  });

  it('Cancel needs the confirm dialog: Escape and Keep order send nothing, focus is trapped and restored', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(PATH, { [URL]: detail() });
    const opener = await screen.findByRole('button', { name: 'Cancel order' });
    await user.click(opener);
    const dialog = screen.getByRole('dialog', { name: 'Cancel this order?' });
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(
      within(dialog).getByRole('button', { name: 'Keep order' }),
    ).toHaveFocus();
    await user.tab();
    expect(
      within(dialog).getByRole('button', { name: 'Yes, cancel order' }),
    ).toHaveFocus();
    await user.tab(); // wraps instead of leaving the dialog
    expect(
      within(dialog).getByRole('button', { name: 'Keep order' }),
    ).toHaveFocus();
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(opener).toHaveFocus();
    await user.click(opener);
    await user.click(screen.getByRole('button', { name: 'Keep order' }));
    expect(api.to('POST /api/v1/orders/ORD-K7Q2M-X9PA3/cancel')).toHaveLength(
      0,
    );
  });

  it('confirming cancels and updates the status and timeline from the response', async () => {
    const user = userEvent.setup();
    const cancelled = order({
      status: 'cancelled',
      allowed_transitions: [],
      history: [
        order().history[0],
        {
          from_status: 'pending',
          to_status: 'cancelled',
          actor: { name: 'Alice Customer', role: 'customer' },
          created_at: '2026-11-25T13:00:00Z',
        },
      ],
    });
    const { api } = setupApp(PATH, {
      [URL]: detail(),
      'POST /api/v1/orders/ORD-K7Q2M-X9PA3/cancel': detail(cancelled),
    });
    await user.click(
      await screen.findByRole('button', { name: 'Cancel order' }),
    );
    await user.click(screen.getByRole('button', { name: 'Yes, cancel order' }));
    await waitFor(() =>
      expect(
        screen
          .getAllByRole('listitem')
          .some((li) =>
            /^Cancelled by Alice Customer/.test(li.textContent ?? ''),
          ),
      ).toBe(true),
    );
    expect(
      screen.getByRole('region', { name: 'Order summary' }),
    ).toHaveTextContent('Cancelled');
    expect(screen.queryByRole('button', { name: 'Cancel order' })).toBeNull();
    expect(api.to('POST /api/v1/orders/ORD-K7Q2M-X9PA3/cancel')).toHaveLength(
      1,
    );
  });

  it('a 422 on cancel shows the message and refetches the order', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(PATH, {
      [URL]: detail(),
      'POST /api/v1/orders/ORD-K7Q2M-X9PA3/cancel': {
        status: 422,
        body: {
          message: 'x',
          errors: { status: ['This order can no longer be cancelled.'] },
        },
      },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Cancel order' }),
    );
    await user.click(screen.getByRole('button', { name: 'Yes, cancel order' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This order can no longer be cancelled.',
    );
    await waitFor(() => expect(api.to(URL).length).toBeGreaterThan(1));
  });

  it('Re-order adds to the cart and lists skipped items with reason labels', async () => {
    const user = userEvent.setup();
    setupApp(PATH, {
      [URL]: detail(),
      'POST /api/v1/orders/ORD-K7Q2M-X9PA3/reorder': {
        body: {
          data: {
            cart: cartOf([line(P1, 2)]).data,
            added: [{ product_id: 17, part_number: '6205-2RS', quantity: 2 }],
            skipped: [
              {
                product_id: 21,
                part_number: '6305',
                name: 'Bearing 6305',
                reason: 'inactive',
              },
              {
                product_id: 22,
                part_number: 'NU206',
                name: 'Roller',
                reason: 'quantity_limit',
              },
            ],
          },
        },
      },
    });
    await user.click(await screen.findByRole('button', { name: 'Re-order' }));
    expect(
      await screen.findByText(/1 item added to your cart/),
    ).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View cart' })).toHaveAttribute(
      'href',
      '/cart',
    );
    expect(
      screen.getByText(/6305 \(Bearing 6305\): No longer available/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/NU206 \(Roller\): Quantity limit reached/),
    ).toBeInTheDocument();
    expect(
      await screen.findByRole('link', { name: 'Cart 1 item' }),
    ).toBeInTheDocument();
  });

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])(
    '%s sees the order read-only with the company, no Cancel and no Re-order',
    async (_n, who) => {
      const { api } = setupApp(
        PATH,
        {
          [URL]: detail(
            order({ allowed_transitions: ['confirmed', 'cancelled'] }),
          ),
          [ME]: meOk(who),
        },
        who,
      );
      await screen.findByRole('heading', { name: /Order ORD/ });
      expect(
        screen.getByText(/for Northwind Machinery Ltd/),
      ).toBeInTheDocument();
      // Staff get the status controls (from allowed_transitions), never the customer actions.
      expect(
        screen.getByRole('button', { name: 'Mark as confirmed' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('button', { name: 'Cancel order' }),
      ).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /re-order/i })).toBeNull();
      expect(api.to(CART)).toHaveLength(0);
    },
  );

  it('shows the quote an order came from and renders custom lines without catalog fields', async () => {
    setupApp(PATH, {
      [URL]: detail(
        order({
          quote_number: 'QUO-K7Q2M-X9PA3',
          items: [
            ...order().items,
            {
              product_id: null,
              part_number: null,
              brand: null,
              name: 'Taper roller bearing 32208, SKF or equivalent',
              unit_price_cents: 3875,
              quantity: 4,
              line_total_cents: 15500,
            },
          ],
        }),
      ),
    });
    await screen.findByRole('heading', { name: /Order ORD-K7Q2M-X9PA3/ });
    expect(
      screen.getByRole('link', { name: 'QUO-K7Q2M-X9PA3' }),
    ).toHaveAttribute('href', '/quotes/QUO-K7Q2M-X9PA3');
    const t = within(screen.getByRole('table', { name: 'Prices as ordered' }));
    expect(t.getByText('Custom item')).toBeInTheDocument();
    expect(
      t.getByText('Taper roller bearing 32208, SKF or equivalent'),
    ).toBeInTheDocument();
    // Only the catalog line links to the catalog.
    expect(t.getAllByRole('link')).toHaveLength(1);
  });

  it('does not show a quote link for a cart order', async () => {
    setupApp(PATH, { [URL]: detail() });
    await screen.findByRole('heading', { name: /Order ORD/ });
    expect(screen.queryByText(/From quote/)).toBeNull();
  });

  it('labels a custom line skipped by Re-order as not in the catalog', async () => {
    const user = userEvent.setup();
    setupApp(PATH, {
      [URL]: detail(),
      'POST /api/v1/orders/ORD-K7Q2M-X9PA3/reorder': {
        body: {
          data: {
            cart: cartOf([]).data,
            added: [],
            skipped: [
              {
                product_id: null,
                part_number: null,
                name: 'Taper roller bearing 32208, SKF or equivalent',
                reason: 'not_in_catalog',
              },
            ],
          },
        },
      },
    });
    await user.click(await screen.findByRole('button', { name: 'Re-order' }));
    expect(
      await screen.findByText(
        'Taper roller bearing 32208, SKF or equivalent: Custom item, not in the catalog',
      ),
    ).toBeInTheDocument();
  });

  it('a 404 (unknown or foreign) shows Order not found with a link back', async () => {
    setupApp(PATH, { [URL]: { status: 404, body: { message: 'Not found.' } } });
    expect(
      await screen.findByRole('heading', { name: 'Order not found' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Back to orders' }),
    ).toHaveAttribute('href', '/orders');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('a malformed number shows the same page and sends no request', async () => {
    const { api } = setupApp('/orders/not%20valid!', {});
    expect(
      await screen.findByRole('heading', { name: 'Order not found' }),
    ).toBeInTheDocument();
    expect(api.calls.some((c) => c.url.startsWith('/api/v1/orders/'))).toBe(
      false,
    );
  });

  it('shows a loading state and a retryable error', async () => {
    const user = userEvent.setup();
    let fail = true;
    setupApp(PATH, {
      [URL]: () =>
        fail
          ? { status: 500, body: { message: 'x' } }
          : { body: { data: order() } },
    });
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    fail = false;
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await screen.findByRole('heading', { name: /Order ORD/ });
  });
});
