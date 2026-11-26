import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { admin, guest, meOk, staff, type Spec } from '../../../test/utils';
import { ME, setupApp } from '../../cart/__tests__/fixtures';
import { deferred, loc } from '../../catalog/__tests__/fixtures';
import {
  INV_ID,
  INV_PATH,
  INV_URL,
  PAY_URL,
  PDF_URL,
  STAFF_ACTIONS,
  VOID_URL,
  detail,
  pdfSpec,
  stubDownload,
} from './fixtures';

const ok = (o = detail()) => ({ body: { data: o } });
const staffApp = (routes = {}, who = staff) =>
  setupApp(INV_PATH, { [ME]: meOk(who), ...routes }, who);

describe('invoice detail', () => {
  it('shows number, status, seller and buyer snapshots, dates, lines, totals with the VAT line and the order link', async () => {
    setupApp(INV_PATH, { [INV_URL]: ok() });
    const h = await screen.findByRole('heading', {
      name: /Invoice INV-2026-000123/,
    });
    expect(h).toHaveTextContent('Open');
    const seller = within(
      screen.getByRole('heading', { name: 'Seller' }).closest('section')!,
    );
    expect(seller.getByText('Acme Parts GmbH')).toBeInTheDocument();
    expect(seller.getByText('00000 City')).toBeInTheDocument();
    const buyer = within(
      screen.getByRole('heading', { name: 'Billed to' }).closest('section')!,
    );
    expect(buyer.getByText('Northwind Machinery Ltd')).toBeInTheDocument();
    expect(buyer.getByText('12 Example Road')).toBeInTheDocument();
    expect(buyer.getByText('Tax ID: NW-TAX-0001')).toBeInTheDocument();
    expect(screen.getByText('2026-11-26')).toBeInTheDocument();
    expect(screen.getByText('2026-12-26')).toBeInTheDocument();
    expect(screen.getByText('PO-4711/A')).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Order ORD-K7Q2M-X9PA3' }),
    ).toHaveAttribute('href', '/orders/ORD-K7Q2M-X9PA3');

    const t = within(screen.getByRole('table', { name: 'Invoice lines' }));
    expect(t.getByText('6205-2RS')).toBeInTheDocument();
    // A free-text line has no part number or brand.
    expect(t.getByText('Custom item')).toBeInTheDocument();
    expect(t.getByText('$12.50')).toBeInTheDocument();
    expect(t.getByText('$37.50')).toBeInTheDocument();

    const totals = within(screen.getByText('Subtotal').closest('dl')!);
    expect(totals.getByText('$1,070.79')).toBeInTheDocument();
    expect(totals.getByText('VAT 19%')).toBeInTheDocument();
    expect(totals.getByText('$203.45')).toBeInTheDocument();
    expect(totals.getByText('$1,274.24')).toBeInTheDocument();
    expect(
      screen.getByText('Please pay by bank transfer.'),
    ).toBeInTheDocument();
    expect(screen.getByText('DE00 TEST')).toBeInTheDocument();
  });

  it('still shows the tax line at 0% (placeholder rate)', async () => {
    setupApp(INV_PATH, {
      [INV_URL]: ok(
        detail({ vat_rate_bp: 0, tax_cents: 0, total_cents: 107079 }),
      ),
    });
    await screen.findByRole('heading', { name: /Invoice INV/ });
    const totals = within(screen.getByText('Subtotal').closest('dl')!);
    expect(totals.getByText('VAT 0%')).toBeInTheDocument();
    expect(totals.getByText('$0.00')).toBeInTheDocument();
  });

  it('shows a fractional rate as a percentage', async () => {
    setupApp(INV_PATH, { [INV_URL]: ok(detail({ vat_rate_bp: 750 })) });
    expect(await screen.findByText('VAT 7.5%')).toBeInTheDocument();
  });

  it('overdue: badge, status banner with the days and due date', async () => {
    setupApp(INV_PATH, {
      [INV_URL]: ok(detail({ status: 'overdue', days_overdue: 12 })),
    });
    const h = await screen.findByRole('heading', { name: /Invoice INV/ });
    expect(h).toHaveTextContent('Overdue');
    expect(
      screen.getByText('Payment is 12 days overdue (due 2026-12-26)'),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Payment is 12 days overdue/).querySelector('svg'),
    ).toHaveAttribute('aria-hidden', 'true');
  });

  it('paid shows the payment date and no overdue banner', async () => {
    setupApp(INV_PATH, {
      [INV_URL]: ok(detail({ status: 'paid', paid_on: '2026-12-01' })),
    });
    await screen.findByRole('heading', { name: /Invoice INV/ });
    expect(screen.getByText('Paid on')).toBeInTheDocument();
    expect(screen.getByText('2026-12-01')).toBeInTheDocument();
    expect(screen.queryByText(/days overdue/)).toBeNull();
  });

  it('renders the status timeline in order: system, staff names, masked staff for customers', async () => {
    setupApp(INV_PATH, {
      [INV_URL]: ok(
        detail({
          status: 'overdue',
          days_overdue: 3,
          history: [
            {
              from_status: null,
              to_status: 'open',
              actor: { name: null, role: 'staff' },
              note: null,
              created_at: '2026-11-26T10:15:00Z',
            },
            {
              from_status: 'open',
              to_status: 'overdue',
              actor: { name: null, role: 'system' },
              note: null,
              created_at: '2026-12-27T01:00:00Z',
            },
          ],
        }),
      ),
    });
    await screen.findByRole('heading', { name: /Invoice INV/ });
    const items = within(
      screen
        .getByRole('heading', { name: 'Status history' })
        .closest('section')!,
    ).getAllByRole('listitem');
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent('Invoice issued by Portal staff');
    expect(items[1]).toHaveTextContent('Overdue by System');
  });

  it('renders hostile API text as text, never HTML', async () => {
    const evil = '<script>alert(1)</script>';
    setupApp(INV_PATH, {
      [INV_URL]: ok(
        detail({
          po_reference: '<img src=x onerror=alert(1)>',
          buyer: { name: evil, address_lines: ['"><b>x</b>'], tax_id: null },
          items: [
            {
              position: 1,
              part_number: evil,
              name: '<img src=x onerror=alert(2)>',
              brand: null,
              quantity: 1,
              unit_price_cents: 100,
              line_total_cents: 100,
            },
          ],
        }),
      ),
    });
    await screen.findByRole('heading', { name: /Invoice INV/ });
    expect(
      screen.getByText('<img src=x onerror=alert(1)>'),
    ).toBeInTheDocument();
    expect(screen.getByText('"><b>x</b>')).toBeInTheDocument();
    expect(document.querySelector('script, img, b')).toBeNull();
  });

  describe('dates are never parsed as Date objects', () => {
    const tz = process.env.TZ;
    beforeEach(() => {
      process.env.TZ = 'America/Los_Angeles'; // UTC-8: a parsed date would show 2026-12-25
    });
    afterEach(() => {
      if (tz === undefined) delete process.env.TZ;
      else process.env.TZ = tz;
    });
    it('shows 2026-12-26 as 2026-12-26 in a negative-offset time zone', async () => {
      setupApp(INV_PATH, {
        [INV_URL]: ok(
          detail({ due_date: '2026-12-26', issue_date: '2026-11-26' }),
        ),
      });
      await screen.findByRole('heading', { name: /Invoice INV/ });
      expect(screen.getByText('2026-12-26')).toBeInTheDocument();
      expect(screen.getByText('2026-11-26')).toBeInTheDocument();
      expect(screen.queryByText('2026-12-25')).toBeNull();
    });
  });

  it('shows a loading state, then a retryable error', async () => {
    const user = userEvent.setup();
    const d = deferred<Spec>();
    let mode: 'slow' | 'fail' | 'ok' = 'slow';
    setupApp(INV_PATH, {
      [INV_URL]: () =>
        mode === 'slow'
          ? d.promise
          : mode === 'fail'
            ? { status: 500, body: { message: 'x' } }
            : ok(),
    });
    expect(
      await screen.findByRole('status', { name: 'Loading invoice' }),
    ).toBeInTheDocument();
    mode = 'fail';
    d.resolve({ status: 500, body: { message: 'x' } });
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    mode = 'ok';
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await screen.findByRole('heading', { name: /Invoice INV/ });
  });
});

describe('invoice detail routing', () => {
  it('a 404 (unknown or foreign) shows "Invoice not found" with a link back', async () => {
    setupApp(INV_PATH, {
      [INV_URL]: { status: 404, body: { message: 'Not found.' } },
    });
    expect(
      await screen.findByRole('heading', { name: 'Invoice not found' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Back to invoices' }),
    ).toHaveAttribute('href', '/invoices');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it.each([
    ['an invoice number', '/invoices/INV-2026-000001'],
    ['an uppercase UUID', `/invoices/${INV_ID.toUpperCase()}`],
    ['garbage', '/invoices/abc'],
  ])(
    'a malformed id (%s) shows the same page and sends no request',
    async (_n, path) => {
      const { api } = setupApp(path, {});
      expect(
        await screen.findByRole('heading', { name: 'Invoice not found' }),
      ).toBeInTheDocument();
      expect(api.calls.some((c) => c.url.startsWith('/api/v1/invoices/'))).toBe(
        false,
      );
    },
  );

  it('a guest is redirected to the login page with the way back', async () => {
    setupApp(INV_PATH, { [ME]: guest });
    await screen.findByRole('heading', { name: /sign in|log in/i });
    expect(loc()).toBe(`/login?next=${encodeURIComponent(INV_PATH)}`);
  });
});

describe('role-aware controls (from allowed_actions only)', () => {
  it('a customer has Download but no Mark as paid or Void', async () => {
    setupApp(INV_PATH, { [INV_URL]: ok() });
    await screen.findByRole('heading', { name: /Invoice INV/ });
    expect(
      screen.getByRole('button', { name: 'Download PDF (INV-2026-000123)' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Mark as paid' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Void invoice' })).toBeNull();
  });

  it.each([
    ['staff', staff],
    ['admin', admin],
  ])('%s sees Mark as paid and Void on an open invoice', async (_n, who) => {
    staffApp(
      { [INV_URL]: ok(detail({ allowed_actions: STAFF_ACTIONS })) },
      who,
    );
    expect(
      await screen.findByRole('button', { name: 'Mark as paid' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Void invoice' }),
    ).toBeInTheDocument();
  });

  it.each([
    [
      'paid',
      detail({
        status: 'paid',
        paid_on: '2026-12-01',
        allowed_actions: ['download'],
      }),
    ],
    ['void', detail({ status: 'void', allowed_actions: ['download'] })],
  ])(
    'staff on a %s invoice has neither action but can still download',
    async (_n, inv) => {
      staffApp({ [INV_URL]: ok(inv) });
      await screen.findByRole('heading', { name: /Invoice INV/ });
      expect(screen.queryByRole('button', { name: 'Mark as paid' })).toBeNull();
      expect(screen.queryByRole('button', { name: 'Void invoice' })).toBeNull();
      expect(
        screen.getByRole('button', { name: /Download PDF/ }),
      ).toBeInTheDocument();
    },
  );

  it('the void reason is shown to staff and never to customers, even if the API sent it', async () => {
    const withReason = detail({
      status: 'void',
      history: [
        detail().history[0],
        {
          from_status: 'open',
          to_status: 'void',
          actor: { name: 'Sam Staff', role: 'staff' },
          note: 'Wrong billing address',
          created_at: '2026-11-27T09:00:00Z',
        },
      ],
    });
    staffApp({ [INV_URL]: ok(withReason) });
    expect(
      await screen.findByText(/Reason: Wrong billing address/),
    ).toBeInTheDocument();
  });

  it('a customer view never renders a void reason', async () => {
    setupApp(INV_PATH, {
      [INV_URL]: ok(
        detail({
          status: 'void',
          history: [
            detail().history[0],
            {
              from_status: 'open',
              to_status: 'void',
              actor: { name: null, role: 'staff' },
              note: 'Internal reason',
              created_at: '2026-11-27T09:00:00Z',
            },
          ],
        }),
      ),
    });
    await screen.findByRole('heading', { name: /Invoice INV/ });
    expect(screen.queryByText(/Internal reason/)).toBeNull();
    const items = within(
      screen
        .getByRole('heading', { name: 'Status history' })
        .closest('section')!,
    ).getAllByRole('listitem');
    expect(items[1]).toHaveTextContent('Void by Portal staff');
  });
});

describe('mark as paid', () => {
  const open = () =>
    ok(detail({ issue_date: '2026-01-05', allowed_actions: STAFF_ACTIONS }));
  const paid = ok(
    detail({
      status: 'paid',
      paid_on: '2026-01-10',
      allowed_actions: ['download'],
    }),
  );

  it('opens a dialog with a labelled date input (max today), sends only { paid_on } and shows the paid invoice', async () => {
    const user = userEvent.setup();
    const { api } = staffApp({ [INV_URL]: open(), [PAY_URL]: paid });
    const opener = await screen.findByRole('button', { name: 'Mark as paid' });
    await user.click(opener);
    const dialog = screen.getByRole('dialog', {
      name: 'Mark this invoice as paid?',
    });
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    const date = within(dialog).getByLabelText('Payment received on');
    expect(date).toHaveAttribute('type', 'date');
    const today = new Date().toISOString().slice(0, 10);
    expect(date).toHaveAttribute('max', today);
    expect(date).toHaveValue(today);
    await user.clear(date);
    await user.type(date, '2026-01-10');
    await user.click(
      within(dialog).getByRole('button', { name: 'Mark as paid' }),
    );
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(api.to(PAY_URL)).toHaveLength(1);
    expect(api.to(PAY_URL)[0].body).toEqual({ paid_on: '2026-01-10' });
    expect(
      screen.getByRole('heading', { name: /Invoice INV/ }),
    ).toHaveTextContent('Paid');
    expect(screen.getByText('Invoice marked as paid.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Mark as paid' })).toBeNull();
    expect(screen.getByRole('heading', { name: /Invoice INV/ })).toHaveFocus();
  });

  it('a future or invalid date is refused on the field and nothing is sent', async () => {
    const user = userEvent.setup();
    const { api } = staffApp({ [INV_URL]: open(), [PAY_URL]: paid });
    await user.click(
      await screen.findByRole('button', { name: 'Mark as paid' }),
    );
    const dialog = screen.getByRole('dialog');
    const date = within(dialog).getByLabelText('Payment received on');
    await user.clear(date);
    await user.type(date, '2999-01-01');
    await user.click(
      within(dialog).getByRole('button', { name: 'Mark as paid' }),
    );
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'cannot be in the future',
    );
    expect(date).toHaveAttribute('aria-invalid', 'true');
    await user.clear(date);
    await user.click(
      within(dialog).getByRole('button', { name: 'Mark as paid' }),
    );
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'valid date',
    );
    expect(api.to(PAY_URL)).toHaveLength(0);
  });

  it('a date before the issue date is refused', async () => {
    const user = userEvent.setup();
    const { api } = staffApp({ [INV_URL]: open(), [PAY_URL]: paid });
    await user.click(
      await screen.findByRole('button', { name: 'Mark as paid' }),
    );
    const dialog = screen.getByRole('dialog');
    const date = within(dialog).getByLabelText('Payment received on');
    await user.clear(date);
    await user.type(date, '2026-01-01');
    await user.click(
      within(dialog).getByRole('button', { name: 'Mark as paid' }),
    );
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'before the issue date (2026-01-05)',
    );
    expect(api.to(PAY_URL)).toHaveLength(0);
  });

  it('a 422 status shows the message in the dialog and refetches the invoice', async () => {
    const user = userEvent.setup();
    const { api } = staffApp({
      [INV_URL]: open(),
      [PAY_URL]: {
        status: 422,
        body: {
          message: 'x',
          errors: { status: ['This invoice is already paid.'] },
        },
      },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Mark as paid' }),
    );
    const dialog = screen.getByRole('dialog');
    await user.click(
      within(dialog).getByRole('button', { name: 'Mark as paid' }),
    );
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'This invoice is already paid.',
    );
    await waitFor(() => expect(api.to(INV_URL).length).toBeGreaterThan(1));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('a 422 paid_on is shown on the date field', async () => {
    const user = userEvent.setup();
    staffApp({
      [INV_URL]: open(),
      [PAY_URL]: {
        status: 422,
        body: {
          message: 'x',
          errors: { paid_on: ['The paid on must not be after today.'] },
        },
      },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Mark as paid' }),
    );
    const dialog = screen.getByRole('dialog');
    await user.click(
      within(dialog).getByRole('button', { name: 'Mark as paid' }),
    );
    const alert = await within(dialog).findByRole('alert');
    expect(alert).toHaveTextContent('must not be after today');
    expect(
      within(dialog).getByLabelText('Payment received on'),
    ).toHaveAccessibleDescription('The paid on must not be after today.');
  });

  it('a 429 shows the seconds', async () => {
    const user = userEvent.setup();
    staffApp({
      [INV_URL]: open(),
      [PAY_URL]: {
        status: 429,
        headers: { 'Retry-After': '17' },
        body: { message: 'x' },
      },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Mark as paid' }),
    );
    const dialog = screen.getByRole('dialog');
    await user.click(
      within(dialog).getByRole('button', { name: 'Mark as paid' }),
    );
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      '17 seconds',
    );
  });

  it('the confirm button is disabled while pending and a double click sends once', async () => {
    const user = userEvent.setup();
    const d = deferred<Spec>();
    const { api } = staffApp({ [INV_URL]: open(), [PAY_URL]: () => d.promise });
    await user.click(
      await screen.findByRole('button', { name: 'Mark as paid' }),
    );
    const dialog = screen.getByRole('dialog');
    const confirm = within(dialog).getByRole('button', {
      name: 'Mark as paid',
    });
    await user.dblClick(confirm);
    await waitFor(() => expect(confirm).toBeDisabled());
    expect(api.to(PAY_URL)).toHaveLength(1);
    d.resolve(paid);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('Escape closes the dialog, sends nothing and returns focus to the opener; Tab is trapped', async () => {
    const user = userEvent.setup();
    const { api } = staffApp({ [INV_URL]: open() });
    const opener = await screen.findByRole('button', { name: 'Mark as paid' });
    await user.click(opener);
    const dialog = screen.getByRole('dialog');
    expect(
      within(dialog).getByRole('button', { name: 'Cancel' }),
    ).toHaveFocus();
    for (let i = 0; i < 6; i++) {
      await user.tab();
      expect(dialog).toContainElement(document.activeElement as HTMLElement);
    }
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(opener).toHaveFocus();
    expect(api.to(PAY_URL)).toHaveLength(0);
  });
});

describe('void invoice', () => {
  const open = () => ok(detail({ allowed_actions: STAFF_ACTIONS }));
  const voided = ok(detail({ status: 'void', allowed_actions: ['download'] }));
  const openDialog = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(
      await screen.findByRole('button', { name: 'Void invoice' }),
    );
    return screen.getByRole('dialog', { name: 'Void this invoice?' });
  };

  it('shows the warning, requires a reason and sends nothing when it is empty', async () => {
    const user = userEvent.setup();
    const { api } = staffApp({ [INV_URL]: open(), [VOID_URL]: voided });
    const dialog = await openDialog(user);
    expect(
      within(dialog).getByText(
        /invoice number stays reserved and this cannot be undone/,
      ),
    ).toBeInTheDocument();
    await user.click(
      within(dialog).getByRole('button', { name: 'Void invoice' }),
    );
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Enter a reason.',
    );
    expect(within(dialog).getByLabelText('Reason')).toHaveAttribute(
      'aria-invalid',
      'true',
    );
    expect(api.to(VOID_URL)).toHaveLength(0);
  });

  it('refuses more than 500 characters on the field', async () => {
    const user = userEvent.setup();
    const { api } = staffApp({ [INV_URL]: open(), [VOID_URL]: voided });
    const dialog = await openDialog(user);
    await user.click(within(dialog).getByLabelText('Reason'));
    await user.paste('x'.repeat(501));
    await user.click(
      within(dialog).getByRole('button', { name: 'Void invoice' }),
    );
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'at most 500',
    );
    expect(api.to(VOID_URL)).toHaveLength(0);
  });

  it('sends only { reason } (trimmed) and shows the void invoice', async () => {
    const user = userEvent.setup();
    const { api } = staffApp({ [INV_URL]: open(), [VOID_URL]: voided });
    const dialog = await openDialog(user);
    await user.type(
      within(dialog).getByLabelText('Reason'),
      '  Wrong billing address  ',
    );
    await user.click(
      within(dialog).getByRole('button', { name: 'Void invoice' }),
    );
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(api.to(VOID_URL)[0].body).toEqual({
      reason: 'Wrong billing address',
    });
    expect(
      screen.getByRole('heading', { name: /Invoice INV/ }),
    ).toHaveTextContent('Void');
    expect(screen.getByText('Invoice voided.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Void invoice' })).toBeNull();
  });

  it('a 422 status shows the message and refetches; a 422 reason goes on the field', async () => {
    const user = userEvent.setup();
    let body: Record<string, string[]> = {
      status: ['Only open or overdue invoices can be voided.'],
    };
    const { api } = staffApp({
      [INV_URL]: open(),
      [VOID_URL]: () => ({ status: 422, body: { message: 'x', errors: body } }),
    });
    const dialog = await openDialog(user);
    await user.type(within(dialog).getByLabelText('Reason'), 'Mistake');
    await user.click(
      within(dialog).getByRole('button', { name: 'Void invoice' }),
    );
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Only open or overdue invoices can be voided.',
    );
    await waitFor(() => expect(api.to(INV_URL).length).toBeGreaterThan(1));

    body = { reason: ['The reason field is required.'] };
    await user.click(
      within(dialog).getByRole('button', { name: 'Void invoice' }),
    );
    await waitFor(() =>
      expect(
        within(dialog).getByLabelText('Reason'),
      ).toHaveAccessibleDescription('The reason field is required.'),
    );
  });

  it('Keep invoice closes the dialog and sends nothing', async () => {
    const user = userEvent.setup();
    const { api } = staffApp({ [INV_URL]: open() });
    const dialog = await openDialog(user);
    await user.click(
      within(dialog).getByRole('button', { name: 'Keep invoice' }),
    );
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(api.to(VOID_URL)).toHaveLength(0);
  });
});

describe('download PDF', () => {
  let dl: ReturnType<typeof stubDownload>;
  beforeEach(() => {
    dl = stubDownload();
  });
  afterEach(() => dl.restore());

  const NAME = 'INV-2026-000123.pdf';
  const press = async (user: ReturnType<typeof userEvent.setup>) =>
    user.click(await screen.findByRole('button', { name: /Download PDF/ }));

  it('fetches with same-origin credentials and the PDF Accept header, saves with the server filename, revokes the object URL and announces it', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(INV_PATH, {
      [INV_URL]: ok(),
      [PDF_URL]: pdfSpec(`attachment; filename="${NAME}"`),
    });
    await press(user);
    expect(
      await screen.findByText(`Download started: ${NAME}`),
    ).toBeInTheDocument();
    const call = api.fn.mock.calls.find(([u]) => String(u).endsWith('/pdf'))!;
    expect(call[1]).toMatchObject({
      credentials: 'same-origin',
      headers: { Accept: 'application/pdf' },
    });
    expect(dl.createObjectURL).toHaveBeenCalledTimes(1);
    expect(dl.clicks).toEqual([{ download: NAME, href: 'blob:fake-url' }]);
    await waitFor(() =>
      expect(dl.revokeObjectURL).toHaveBeenCalledWith('blob:fake-url'),
    );
    expect(document.querySelector('a[download]')).toBeNull(); // temporary anchor removed
    expect(
      screen.getByText(`Download started: ${NAME}`).closest('[aria-live]'),
    ).not.toBeNull();
  });

  it.each([
    ['a missing header', null],
    ['a path traversal', 'attachment; filename="../../evil.pdf"'],
    ['a wrong extension', 'attachment; filename="INV-2026-000123.exe"'],
    ['a hostile name', 'attachment; filename="<script>.pdf"'],
    ['a free-text name', 'attachment; filename="Northwind Invoice.pdf"'],
  ])('%s falls back to <number>.pdf', async (_n, disposition) => {
    const user = userEvent.setup();
    setupApp(INV_PATH, { [INV_URL]: ok(), [PDF_URL]: pdfSpec(disposition) });
    await press(user);
    await screen.findByText(`Download started: ${NAME}`);
    expect(dl.clicks[0].download).toBe(NAME);
  });

  it('accepts a 7-digit number in the server filename', async () => {
    const user = userEvent.setup();
    setupApp(INV_PATH, {
      [INV_URL]: ok(),
      [PDF_URL]: pdfSpec('attachment; filename="INV-2026-1000000.pdf"'),
    });
    await press(user);
    await screen.findByText('Download started: INV-2026-1000000.pdf');
  });

  it('a non-PDF content type is an error and nothing is saved', async () => {
    const user = userEvent.setup();
    setupApp(INV_PATH, {
      [INV_URL]: ok(),
      [PDF_URL]: { headers: { 'Content-Type': 'text/html' }, body: '<html>' },
    });
    await press(user);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'did not send a PDF',
    );
    expect(dl.createObjectURL).not.toHaveBeenCalled();
    expect(dl.clicks).toHaveLength(0);
  });

  it('a 404 shows "Invoice not found." inline and the button recovers', async () => {
    const user = userEvent.setup();
    setupApp(INV_PATH, {
      [INV_URL]: ok(),
      [PDF_URL]: { status: 404, body: { message: 'Not found.' } },
    });
    await press(user);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Invoice not found.',
    );
    expect(screen.getByRole('button', { name: /Download PDF/ })).toBeEnabled();
  });

  it('a 429 shows the seconds from Retry-After', async () => {
    const user = userEvent.setup();
    setupApp(INV_PATH, {
      [INV_URL]: ok(),
      [PDF_URL]: {
        status: 429,
        headers: { 'Retry-After': '42' },
        body: { message: 'x' },
      },
    });
    await press(user);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Too many downloads. Try again in 42 seconds.',
    );
  });

  it('a network error shows a retry message', async () => {
    const user = userEvent.setup();
    setupApp(INV_PATH, {
      [INV_URL]: ok(),
      [PDF_URL]: () => {
        throw new TypeError('Failed to fetch');
      },
    });
    await press(user);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Could not reach the server',
    );
  });

  it('a 401 ends the session and goes to the login page with the way back', async () => {
    const user = userEvent.setup();
    setupApp(INV_PATH, {
      [INV_URL]: ok(),
      [PDF_URL]: { status: 401, body: { message: 'Unauthenticated.' } },
    });
    await press(user);
    await waitFor(() =>
      expect(loc()).toBe(`/login?next=${encodeURIComponent(INV_PATH)}`),
    );
  });

  it('the button is disabled and aria-busy while pending and a double click sends one request', async () => {
    const user = userEvent.setup();
    const d = deferred<Spec>();
    const { api } = setupApp(INV_PATH, {
      [INV_URL]: ok(),
      [PDF_URL]: () => d.promise,
    });
    const button = await screen.findByRole('button', { name: /Download PDF/ });
    await user.dblClick(button);
    await waitFor(() => expect(button).toBeDisabled());
    expect(button).toHaveAttribute('aria-busy', 'true');
    expect(api.to(PDF_URL)).toHaveLength(1);
    d.resolve(pdfSpec(`attachment; filename="${NAME}"`));
    await screen.findByText(`Download started: ${NAME}`);
    expect(button).toBeEnabled();
    expect(button).toHaveAttribute('aria-busy', 'false');
  });
});
