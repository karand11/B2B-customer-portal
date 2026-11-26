import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { admin, meOk, staff, type Spec } from '../../../test/utils';
import { ME, order, setupApp } from '../../cart/__tests__/fixtures';
import { deferred, loc } from '../../catalog/__tests__/fixtures';
import { INV_ID, INV_PATH, INV_URL, detail } from './fixtures';

const O_URL = 'GET /api/v1/orders/ORD-K7Q2M-X9PA3';
const O_PATH = '/orders/ORD-K7Q2M-X9PA3';
const CREATE = 'POST /api/v1/orders/ORD-K7Q2M-X9PA3/invoice';
const eligible = order({
  status: 'confirmed',
  allowed_transitions: [],
  can_invoice: true,
});
const od = (o = eligible) => ({ body: { data: o } });
const created = {
  status: 201,
  body: {
    data: detail({ allowed_actions: ['download', 'mark_paid', 'void'] }),
  },
};
const staffOrder = (routes = {}, who = staff) =>
  setupApp(O_PATH, { [ME]: meOk(who), ...routes }, who);

describe('order detail: invoice link', () => {
  it('links to the invoice with its number and status when the order has one', async () => {
    setupApp(O_PATH, {
      [O_URL]: od(
        order({
          status: 'confirmed',
          invoice: { id: INV_ID, number: 'INV-2026-000123', status: 'overdue' },
        }),
      ),
    });
    const link = await screen.findByRole('link', {
      name: 'Invoice INV-2026-000123',
    });
    expect(link).toHaveAttribute('href', `/invoices/${INV_ID}`);
    expect(link.closest('p')).toHaveTextContent(
      'Invoice INV-2026-000123 (Overdue)',
    );
  });

  it('shows no invoice line when there is none', async () => {
    setupApp(O_PATH, { [O_URL]: od(order()) });
    await screen.findByRole('heading', { name: /Order ORD/ });
    expect(screen.queryByText(/Invoice INV/)).toBeNull();
  });

  it('the invoice page links back to the order', async () => {
    const user = userEvent.setup();
    setupApp(INV_PATH, {
      [INV_URL]: { body: { data: detail() } },
      [O_URL]: od(order()),
    });
    await user.click(
      await screen.findByRole('link', { name: 'Order ORD-K7Q2M-X9PA3' }),
    );
    expect(loc()).toBe(O_PATH);
    await screen.findByRole('heading', { name: /Order ORD/ });
  });
});

describe('order detail: Create invoice', () => {
  it('is hidden for customers, even when the payload says can_invoice', async () => {
    setupApp(O_PATH, { [O_URL]: od(eligible) });
    await screen.findByRole('heading', { name: /Order ORD/ });
    expect(screen.queryByRole('button', { name: 'Create invoice' })).toBeNull();
  });

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])('%s sees it only when can_invoice is true', async (_n, who) => {
    staffOrder({ [O_URL]: od(eligible) }, who);
    expect(
      await screen.findByRole('button', { name: 'Create invoice' }),
    ).toBeInTheDocument();
  });

  it('is hidden for staff when can_invoice is false', async () => {
    staffOrder({ [O_URL]: od(order({ status: 'pending' })) });
    await screen.findByRole('heading', { name: /Order ORD/ });
    expect(screen.queryByRole('button', { name: 'Create invoice' })).toBeNull();
  });

  it('the dialog has a labelled optional due date (min today, max +120 days) and sends {} when empty, then opens the new invoice', async () => {
    const user = userEvent.setup();
    const { api } = staffOrder({
      [O_URL]: od(),
      [CREATE]: created,
      [INV_URL]: { body: { data: detail() } },
    });
    const opener = await screen.findByRole('button', {
      name: 'Create invoice',
    });
    await user.click(opener);
    const dialog = screen.getByRole('dialog', { name: 'Create invoice?' });
    const due = within(dialog).getByLabelText('Due date (optional)');
    const day = (n: number) =>
      new Date(Date.now() + n * 86_400_000).toISOString().slice(0, 10);
    expect(due).toHaveAttribute('min', day(0));
    expect(due).toHaveAttribute('max', day(120));
    expect(within(dialog).getByText(/never reused/)).toBeInTheDocument();
    await user.click(
      within(dialog).getByRole('button', { name: 'Create invoice' }),
    );
    await waitFor(() => expect(loc()).toBe(INV_PATH));
    expect(api.to(CREATE)).toHaveLength(1);
    expect(api.to(CREATE)[0].body).toEqual({});
    expect(
      await screen.findByRole('heading', { name: /Invoice INV-2026-000123/ }),
    ).toBeInTheDocument();
  });

  it('sends only { due_date } when one is chosen', async () => {
    const user = userEvent.setup();
    const { api } = staffOrder({
      [O_URL]: od(),
      [CREATE]: created,
      [INV_URL]: { body: { data: detail() } },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Create invoice' }),
    );
    const dialog = screen.getByRole('dialog');
    const due = new Date(Date.now() + 10 * 86_400_000)
      .toISOString()
      .slice(0, 10);
    await user.type(within(dialog).getByLabelText('Due date (optional)'), due);
    await user.click(
      within(dialog).getByRole('button', { name: 'Create invoice' }),
    );
    await waitFor(() => expect(loc()).toBe(INV_PATH));
    expect(api.to(CREATE)[0].body).toEqual({ due_date: due });
  });

  it('a due date in the past or beyond 120 days is refused on the field and nothing is sent', async () => {
    const user = userEvent.setup();
    const { api } = staffOrder({ [O_URL]: od(), [CREATE]: created });
    await user.click(
      await screen.findByRole('button', { name: 'Create invoice' }),
    );
    const dialog = screen.getByRole('dialog');
    const due = within(dialog).getByLabelText('Due date (optional)');
    await user.type(due, '2020-01-01');
    await user.click(
      within(dialog).getByRole('button', { name: 'Create invoice' }),
    );
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Choose a date from',
    );
    await user.clear(due);
    await user.type(due, '2999-01-01');
    await user.click(
      within(dialog).getByRole('button', { name: 'Create invoice' }),
    );
    await waitFor(() =>
      expect(within(dialog).getByRole('alert')).toHaveTextContent(
        'Choose a date from',
      ),
    );
    expect(api.to(CREATE)).toHaveLength(0);
  });

  it.each([
    ['order', 'This order already has an invoice.'],
    ['company', 'The company has no billing address.'],
    ['invoice', 'Invoicing is not configured.'],
  ])(
    'a 422 %s is shown in the dialog (and the order is refetched)',
    async (key, message) => {
      const user = userEvent.setup();
      const { api } = staffOrder({
        [O_URL]: od(),
        [CREATE]: {
          status: 422,
          body: { message: 'x', errors: { [key]: [message] } },
        },
      });
      await user.click(
        await screen.findByRole('button', { name: 'Create invoice' }),
      );
      const dialog = screen.getByRole('dialog');
      await user.click(
        within(dialog).getByRole('button', { name: 'Create invoice' }),
      );
      expect(await within(dialog).findByRole('alert')).toHaveTextContent(
        message,
      );
      await waitFor(() => expect(api.to(O_URL).length).toBeGreaterThan(1));
      expect(loc()).toBe(O_PATH);
    },
  );

  it('a 422 due_date goes on the field', async () => {
    const user = userEvent.setup();
    staffOrder({
      [O_URL]: od(),
      [CREATE]: {
        status: 422,
        body: {
          message: 'x',
          errors: { due_date: ['The due date is out of range.'] },
        },
      },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Create invoice' }),
    );
    const dialog = screen.getByRole('dialog');
    await user.click(
      within(dialog).getByRole('button', { name: 'Create invoice' }),
    );
    await within(dialog).findByRole('alert');
    expect(
      within(dialog).getByLabelText('Due date (optional)'),
    ).toHaveAccessibleDescription('The due date is out of range.');
  });

  it('is disabled while pending and a double click creates one invoice', async () => {
    const user = userEvent.setup();
    const d = deferred<Spec>();
    const { api } = staffOrder({
      [O_URL]: od(),
      [CREATE]: () => d.promise,
      [INV_URL]: { body: { data: detail() } },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Create invoice' }),
    );
    const dialog = screen.getByRole('dialog');
    const confirm = within(dialog).getByRole('button', {
      name: 'Create invoice',
    });
    await user.dblClick(confirm);
    await waitFor(() => expect(confirm).toBeDisabled());
    expect(api.to(CREATE)).toHaveLength(1);
    d.resolve(created);
    await waitFor(() => expect(loc()).toBe(INV_PATH));
  });

  it('Escape closes the dialog, sends nothing and returns focus to the button', async () => {
    const user = userEvent.setup();
    const { api } = staffOrder({ [O_URL]: od() });
    const opener = await screen.findByRole('button', {
      name: 'Create invoice',
    });
    await user.click(opener);
    const dialog = screen.getByRole('dialog');
    expect(
      within(dialog).getByRole('button', { name: 'Cancel' }),
    ).toHaveFocus();
    await user.tab();
    await user.tab();
    await user.tab();
    expect(dialog).toContainElement(document.activeElement as HTMLElement);
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(opener).toHaveFocus();
    expect(api.to(CREATE)).toHaveLength(0);
  });
});
