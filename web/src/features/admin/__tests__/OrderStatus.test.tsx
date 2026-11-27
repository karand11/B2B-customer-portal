import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { admin, customer } from '../../../test/utils';
import { order } from '../../cart/__tests__/fixtures';
import { setupAdmin } from './fixtures';

const GET = 'GET /api/v1/orders/ORD-K7Q2M-X9PA3';
const PATCH = 'PATCH /api/v1/orders/ORD-K7Q2M-X9PA3/status';
const PATH = '/admin/orders/ORD-K7Q2M-X9PA3';
const detail = (
  o = order({ allowed_transitions: ['confirmed', 'cancelled'] }),
) => ({
  body: { data: o },
});
const heading = () =>
  screen.findByRole('heading', { name: /Order ORD-K7Q2M-X9PA3/ });

describe('order status control (staff and admin)', () => {
  it('offers exactly one button per allowed transition and never the customer actions', async () => {
    setupAdmin(PATH, { [GET]: detail() });
    await heading();
    expect(
      screen.getByRole('button', { name: 'Mark as confirmed' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Cancel order' }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: /mark as (shipped|delivered)/i }),
    ).toBeNull();
    expect(screen.queryByRole('button', { name: /re-order/i })).toBeNull();
  });

  it.each([
    ['confirmed', 'Mark as confirmed'],
    ['shipped', 'Mark as shipped'],
    ['delivered', 'Mark as delivered'],
    ['cancelled', 'Cancel order'],
  ] as const)('labels the %s transition "%s"', async (status, label) => {
    setupAdmin(PATH, {
      [GET]: detail(order({ allowed_transitions: [status] })),
    });
    await heading();
    expect(screen.getByRole('button', { name: label })).toBeInTheDocument();
  });

  it('shows no buttons when no transition is allowed', async () => {
    setupAdmin(PATH, {
      [GET]: detail(order({ status: 'delivered', allowed_transitions: [] })),
    });
    await heading();
    expect(
      screen.queryByRole('button', { name: /mark as|cancel order/i }),
    ).toBeNull();
  });

  it('exposes the E2E hooks: an "Order summary" region with the status and a "Status history" list', async () => {
    setupAdmin(PATH, { [GET]: detail() });
    await heading();
    const summary = screen.getByRole('region', { name: 'Order summary' });
    expect(summary).toHaveTextContent('Pending');
    const history = screen.getByRole('list', { name: 'Status history' });
    expect(within(history).getAllByRole('listitem')).toHaveLength(1);
  });

  it('confirming sends only { status }, updates the summary and history from the response and announces it', async () => {
    const user = userEvent.setup();
    const confirmed = order({
      status: 'confirmed',
      allowed_transitions: ['shipped', 'cancelled'],
      history: [
        order().history[0],
        {
          from_status: 'pending',
          to_status: 'confirmed',
          actor: { name: 'Sam Staff', role: 'staff' },
          created_at: '2026-11-26T12:00:00Z',
        },
      ],
    });
    const { api } = setupAdmin(PATH, {
      [GET]: detail(),
      [PATCH]: { body: { data: confirmed } },
    });
    await heading();
    await user.click(screen.getByRole('button', { name: 'Mark as confirmed' }));
    const dialog = screen.getByRole('dialog', {
      name: 'Mark order as confirmed?',
    });
    expect(api.to(PATCH)).toHaveLength(0);
    await user.click(within(dialog).getByRole('button', { name: 'Confirm' }));
    await waitFor(() => expect(api.to(PATCH)).toHaveLength(1));
    expect(api.to(PATCH)[0].body).toEqual({ status: 'confirmed' });
    await waitFor(() =>
      expect(
        screen.getByRole('region', { name: 'Order summary' }),
      ).toHaveTextContent('Confirmed'),
    );
    expect(
      within(screen.getByRole('list', { name: 'Status history' })).getAllByRole(
        'listitem',
      ),
    ).toHaveLength(2);
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByText('Order is now confirmed.')).toBeInTheDocument();
    // The new transitions replace the old buttons.
    expect(
      screen.getByRole('button', { name: 'Mark as shipped' }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Mark as confirmed' }),
    ).toBeNull();
  });

  it('Escape and Go back send nothing; focus returns to the opener', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin(PATH, { [GET]: detail() });
    await heading();
    const opener = screen.getByRole('button', { name: 'Mark as confirmed' });
    await user.click(opener);
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(opener).toHaveFocus();
    await user.click(opener);
    await user.click(screen.getByRole('button', { name: 'Go back' }));
    expect(api.to(PATCH)).toHaveLength(0);
  });

  it('cancelling uses the status endpoint (not the customer cancel endpoint) behind a danger confirm', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin(
      PATH,
      {
        [GET]: detail(),
        [PATCH]: {
          body: {
            data: order({ status: 'cancelled', allowed_transitions: [] }),
          },
        },
      },
      admin,
    );
    await heading();
    await user.click(screen.getByRole('button', { name: 'Cancel order' }));
    const dialog = screen.getByRole('dialog', { name: 'Cancel this order?' });
    expect(dialog).toHaveTextContent('cannot be undone');
    await user.click(within(dialog).getByRole('button', { name: 'Confirm' }));
    await waitFor(() => expect(api.to(PATCH)).toHaveLength(1));
    expect(api.to(PATCH)[0].body).toEqual({ status: 'cancelled' });
    expect(api.to('POST /api/v1/orders/ORD-K7Q2M-X9PA3/cancel')).toHaveLength(
      0,
    );
  });

  it('a 422 shows the server text inside the dialog and refetches the order', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin(PATH, {
      [GET]: detail(),
      [PATCH]: {
        status: 422,
        body: {
          message: 'x',
          errors: {
            status: ['Cannot change status from shipped to confirmed.'],
          },
        },
      },
    });
    await heading();
    await user.click(screen.getByRole('button', { name: 'Mark as confirmed' }));
    const dialog = screen.getByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Confirm' }));
    expect(
      await within(dialog).findByText(
        'Cannot change status from shipped to confirmed.',
      ),
    ).toHaveAttribute('role', 'alert');
    await waitFor(() => expect(api.to(GET).length).toBeGreaterThanOrEqual(2));
  });

  it('customers never get the status buttons, only their own Cancel via the cancel endpoint', async () => {
    setupAdmin(
      '/orders/ORD-K7Q2M-X9PA3',
      { [GET]: detail(order({ allowed_transitions: ['cancelled'] })) },
      customer,
    );
    await heading();
    expect(screen.queryByRole('button', { name: /mark as/i })).toBeNull();
    expect(
      screen.getByRole('button', { name: 'Cancel order' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Re-order' }),
    ).toBeInTheDocument();
  });
});

describe('links between records stay inside the back office', () => {
  it('order detail links to its company, quote and invoice under /admin', async () => {
    setupAdmin(PATH, {
      [GET]: detail(
        order({
          allowed_transitions: [],
          quote_number: 'QUO-K7Q2M-X9PA3',
          invoice: {
            id: '3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90',
            number: 'INV-2026-000001',
            status: 'open',
          },
        }),
      ),
    });
    await heading();
    expect(
      screen.getByRole('link', { name: 'Back to orders' }),
    ).toHaveAttribute('href', '/admin/orders');
    expect(
      screen.getByRole('link', { name: 'Northwind Machinery Ltd' }),
    ).toHaveAttribute('href', '/admin/companies/1');
    expect(
      screen.getByRole('link', { name: 'QUO-K7Q2M-X9PA3' }),
    ).toHaveAttribute('href', '/admin/quotes/QUO-K7Q2M-X9PA3');
    expect(
      screen.getByRole('link', { name: /Invoice INV-2026-000001/ }),
    ).toHaveAttribute(
      'href',
      '/admin/invoices/3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90',
    );
  });

  it('the old /orders URL keeps its customer-style links for staff', async () => {
    setupAdmin('/orders/ORD-K7Q2M-X9PA3', {
      [GET]: detail(
        order({ allowed_transitions: [], quote_number: 'QUO-K7Q2M-X9PA3' }),
      ),
    });
    await heading();
    expect(
      screen.getByRole('link', { name: 'QUO-K7Q2M-X9PA3' }),
    ).toHaveAttribute('href', '/quotes/QUO-K7Q2M-X9PA3');
  });
});
