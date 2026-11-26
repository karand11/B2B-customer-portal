import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { meOk, staff, type Spec } from '../../../test/utils';
import { order } from '../../cart/__tests__/fixtures';
import { deferred, loc } from '../../catalog/__tests__/fixtures';
import type { QuoteAttachment, QuoteDetail } from '../api';
import {
  DETAIL,
  ME,
  NUM,
  ORDER,
  catalogItem,
  customItem,
  day,
  detail,
  forStaff,
  pdf,
  quote,
  quoted,
  setupApp,
  unprocessable,
} from './fixtures';

const PATH = `/quotes/${NUM}`;
const ACT = (name: string) => `POST /api/v1/quotes/${NUM}/${name}`;
const PRICING = `PUT /api/v1/quotes/${NUM}/pricing`;
const NOTE = `PUT /api/v1/quotes/${NUM}/internal-note`;
const ATTACH = `POST /api/v1/quotes/${NUM}/attachments`;
const ATT: QuoteAttachment = {
  id: '3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90',
  name: 'drawing-rev2.pdf',
  mime: 'application/pdf',
  size_bytes: 482113,
  uploaded_by: { name: 'Alice Customer', role: 'customer' },
  created_at: '2026-11-25T10:16:00Z',
};
const asStaff = (q: QuoteDetail) => ({
  [ME]: meOk(staff),
  [DETAIL]: detail(q),
});
const dialog = (name: string) => screen.getByRole('dialog', { name });
const heading = () => screen.findByRole('heading', { name: /Quote QUO-/ });

describe('quote detail (customer)', () => {
  it('shows lines, hides prices as Awaiting pricing, and renders customer text literally', async () => {
    setupApp(PATH, {
      [DETAIL]: detail(
        quote({
          po_reference: 'PO-4711/A',
          customer_note: '<b>urgent</b>\nsecond line',
          attachments: [ATT],
        }),
      ),
    });
    expect(await heading()).toHaveTextContent('Submitted');
    const t = within(screen.getByRole('table', { name: 'Requested items' }));
    expect(t.getByRole('link', { name: '6205-2RS' })).toHaveAttribute(
      'href',
      '/catalog/17',
    );
    expect(t.getByText('Custom item')).toBeInTheDocument();
    expect(
      t.getByText('Taper roller bearing 32208, SKF or equivalent'),
    ).toBeInTheDocument();
    expect(t.getAllByText('Awaiting pricing')).toHaveLength(4);
    expect(screen.getByText('PO reference: PO-4711/A')).toBeInTheDocument();
    // Text node, not markup.
    const note = screen.getByText(/<b>urgent<\/b>/);
    expect(note.className).toContain('whitespace-pre-wrap');
    expect(document.querySelector('b')).toBeNull();
    // Attachment goes through the API path and is a download.
    const link = screen.getByRole('link', { name: 'drawing-rev2.pdf' });
    expect(link).toHaveAttribute(
      'href',
      `/api/v1/quotes/${NUM}/attachments/${ATT.id}`,
    );
    expect(link).toHaveAttribute('download');
    // Timeline is an ordered list.
    const h = screen.getByRole('heading', { name: 'Status history' });
    const ol = h.closest('section')!.querySelector('ol');
    expect(ol).not.toBeNull();
    expect(within(ol!).getAllByRole('listitem')).toHaveLength(1);
    expect(ol).toHaveTextContent('Request submitted by Alice Customer');
  });

  it('shows buttons only for allowed actions: a submitted quote can only be withdrawn', async () => {
    setupApp(PATH, { [DETAIL]: detail(quote()) });
    await heading();
    expect(
      screen.getByRole('button', { name: 'Withdraw request' }),
    ).toBeInTheDocument();
    for (const name of [
      /accept/i,
      /take into review/i,
      /send/i,
      /reject/i,
      /reopen/i,
      /save prices/i,
    ])
      expect(screen.queryByRole('button', { name })).toBeNull();
    expect(screen.queryByLabelText(/internal note/i)).toBeNull();
  });

  it('never shows an internal note to a customer even if one were present in the payload', async () => {
    setupApp(PATH, {
      [DETAIL]: detail({ ...quote(), internal_note: 'SECRET MARGIN' }),
    });
    await heading();
    expect(screen.queryByText(/SECRET MARGIN/)).toBeNull();
    expect(screen.queryByLabelText(/internal note/i)).toBeNull();
  });

  it('a sent quote shows prices, totals, the staff note, validity and Accept/Decline', async () => {
    setupApp(PATH, { [DETAIL]: detail(quoted()) });
    await heading();
    const t = within(screen.getByRole('table', { name: 'Requested items' }));
    expect(t.getByText('$12.50')).toBeInTheDocument();
    expect(t.getByText('$155.00')).toBeInTheDocument();
    expect(screen.getAllByText('$280.00').length).toBeGreaterThan(0);
    expect(
      screen.getByText('Lead time 3 weeks, ex works.'),
    ).toBeInTheDocument();
    expect(
      screen.getByText(`Valid through ${day(30)} (end of day, UTC)`),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Accept quote' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Decline quote' }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Withdraw request' }),
    ).toBeNull();
    // Staff names are hidden from customers.
    expect(screen.getAllByText(/by Portal staff/).length).toBeGreaterThan(0);
  });

  it('Accept dialog: lines, total, validity, optional PO; sends only the revision; success opens the order with the banner', async () => {
    const user = userEvent.setup();
    const accepted = quoted({
      status: 'accepted',
      order: { number: 'ORD-K7Q2M-X9PA3' },
      allowed_actions: [],
    });
    const { api } = setupApp(PATH, {
      [DETAIL]: detail(quoted()),
      [ACT('accept')]: detail(accepted),
      [ORDER]: { body: { data: order({ quote_number: NUM }) } },
    });
    const opener = await screen.findByRole('button', { name: 'Accept quote' });
    await user.click(opener);
    const d = within(dialog('Accept this quote?'));
    expect(d.getByText(/10 x Deep groove ball bearing/)).toHaveTextContent(
      '$125.00',
    );
    expect(d.getByText('Total: $280.00')).toBeInTheDocument();
    expect(
      d.getByText(`Valid through ${day(30)} (end of day, UTC)`),
    ).toBeInTheDocument();
    await user.click(
      d.getByRole('button', { name: 'Accept quote and create order' }),
    );
    expect(
      await screen.findByText(
        /Quote accepted\. Order ORD-K7Q2M-X9PA3 was created and is pending confirmation\./,
      ),
    ).toBeInTheDocument();
    expect(loc()).toBe('/orders/ORD-K7Q2M-X9PA3');
    expect(api.to(ACT('accept'))[0].body).toStrictEqual({ revision: 1 });
    expect(screen.getByRole('link', { name: NUM })).toHaveAttribute(
      'href',
      `/quotes/${NUM}`,
    );
  });

  it('Accept sends a trimmed optional PO reference next to the revision', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(PATH, {
      [DETAIL]: detail(quoted({ revision: 3 })),
      [ACT('accept')]: detail(
        quoted({
          status: 'accepted',
          order: { number: 'ORD-K7Q2M-X9PA3' },
          allowed_actions: [],
        }),
      ),
      [ORDER]: { body: { data: order() } },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Accept quote' }),
    );
    await user.type(
      screen.getByLabelText('PO reference (optional)'),
      '  PO-9/Z ',
    );
    await user.click(
      screen.getByRole('button', { name: 'Accept quote and create order' }),
    );
    await waitFor(() => expect(api.to(ACT('accept'))).toHaveLength(1));
    expect(api.to(ACT('accept'))[0].body).toStrictEqual({
      revision: 3,
      po_reference: 'PO-9/Z',
    });
  });

  it('an invalid PO reference in the dialog is rejected inline and nothing is sent', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(PATH, { [DETAIL]: detail(quoted()) });
    await user.click(
      await screen.findByRole('button', { name: 'Accept quote' }),
    );
    await user.type(
      screen.getByLabelText('PO reference (optional)'),
      '=SUM(A1)',
    );
    await user.click(
      screen.getByRole('button', { name: 'Accept quote and create order' }),
    );
    expect(
      await within(dialog('Accept this quote?')).findByRole('alert'),
    ).toHaveTextContent('Must start with a letter or digit.');
    expect(api.to(ACT('accept'))).toHaveLength(0);
  });

  it('a 422 on revision shows the stale message, refetches and keeps the dialog open with the new prices', async () => {
    const user = userEvent.setup();
    let fresh = false;
    const { api } = setupApp(PATH, {
      [DETAIL]: () =>
        detail(
          fresh
            ? quoted({ revision: 2, total_cents: 30000, subtotal_cents: 30000 })
            : quoted(),
        ),
      [ACT('accept')]: () => {
        fresh = true;
        return unprocessable({
          revision: ['This quote was updated. Please review the new prices.'],
        });
      },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Accept quote' }),
    );
    await user.click(
      screen.getByRole('button', { name: 'Accept quote and create order' }),
    );
    const d = dialog('Accept this quote?');
    expect(await within(d).findByRole('alert')).toHaveTextContent(
      'This quote was updated. Please review the new prices.',
    );
    await waitFor(() => expect(api.to(DETAIL).length).toBeGreaterThan(1));
    expect(await within(d).findByText('Total: $300.00')).toBeInTheDocument();
    // The next attempt carries the new revision.
    fresh = true;
    await user.click(
      within(d).getByRole('button', { name: 'Accept quote and create order' }),
    );
    await waitFor(() => expect(api.to(ACT('accept'))).toHaveLength(2));
    expect(api.to(ACT('accept'))[1].body).toStrictEqual({ revision: 2 });
  });

  it.each(['This quote has already been accepted.', 'This quote has expired.'])(
    'a 422 on status (%s) is shown in the dialog and the quote is refetched',
    async (message) => {
      const user = userEvent.setup();
      const { api } = setupApp(PATH, {
        [DETAIL]: detail(quoted()),
        [ACT('accept')]: unprocessable({ status: [message] }),
      });
      await user.click(
        await screen.findByRole('button', { name: 'Accept quote' }),
      );
      await user.click(
        screen.getByRole('button', { name: 'Accept quote and create order' }),
      );
      expect(
        await within(dialog('Accept this quote?')).findByRole('alert'),
      ).toHaveTextContent(message);
      await waitFor(() => expect(api.to(DETAIL).length).toBeGreaterThan(1));
    },
  );

  it('a double click on Accept sends one request and disables the button while pending', async () => {
    const user = userEvent.setup();
    const d = deferred<Spec>();
    const { api } = setupApp(PATH, {
      [DETAIL]: detail(quoted()),
      [ACT('accept')]: () => d.promise,
      [ORDER]: { body: { data: order() } },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Accept quote' }),
    );
    await user.dblClick(
      screen.getByRole('button', { name: 'Accept quote and create order' }),
    );
    expect(api.to(ACT('accept'))).toHaveLength(1);
    expect(
      screen.getByRole('button', { name: 'Accept quote and create order' }),
    ).toBeDisabled();
    d.resolve(
      detail(
        quoted({
          status: 'accepted',
          order: { number: 'ORD-K7Q2M-X9PA3' },
          allowed_actions: [],
        }),
      ),
    );
    await waitFor(() => expect(loc()).toBe('/orders/ORD-K7Q2M-X9PA3'));
    expect(api.to(ACT('accept'))).toHaveLength(1);
  });

  it('dialogs trap focus, close on Escape without sending and return focus to the opener', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(PATH, { [DETAIL]: detail(quoted()) });
    const opener = await screen.findByRole('button', { name: 'Accept quote' });
    await user.click(opener);
    const d = within(dialog('Accept this quote?'));
    expect(dialog('Accept this quote?')).toHaveAttribute('aria-modal', 'true');
    expect(d.getByRole('button', { name: 'Cancel' })).toHaveFocus();
    await user.tab();
    await user.tab();
    await user.tab();
    expect(dialog('Accept this quote?').contains(document.activeElement)).toBe(
      true,
    );
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(opener).toHaveFocus();
    expect(api.to(ACT('accept'))).toHaveLength(0);
  });

  it('Decline sends the optional reason; the dialog is a Withdraw dialog for an open request', async () => {
    const user = userEvent.setup();
    const declined = quoted({ status: 'declined', allowed_actions: [] });
    const { api } = setupApp(PATH, {
      [DETAIL]: detail(quoted()),
      [ACT('decline')]: detail(declined),
    });
    await user.click(
      await screen.findByRole('button', { name: 'Decline quote' }),
    );
    const d = within(dialog('Decline this quote?'));
    await user.type(d.getByLabelText('Reason (optional)'), 'Price too high');
    await user.click(d.getByRole('button', { name: 'Yes, decline' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(api.to(ACT('decline'))[0].body).toStrictEqual({
      reason: 'Price too high',
    });
    expect(
      await screen.findByRole('heading', { name: /Quote QUO-/ }),
    ).toHaveTextContent('Declined');
    expect(screen.queryByRole('button', { name: 'Decline quote' })).toBeNull();
  });

  it('Withdraw without a reason sends no body', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(PATH, {
      [DETAIL]: detail(quote()),
      [ACT('decline')]: detail(
        quote({ status: 'declined', allowed_actions: [] }),
      ),
    });
    await user.click(
      await screen.findByRole('button', { name: 'Withdraw request' }),
    );
    await user.click(
      within(dialog('Withdraw this quote request?')).getByRole('button', {
        name: 'Yes, withdraw',
      }),
    );
    await waitFor(() => expect(api.to(ACT('decline'))).toHaveLength(1));
    expect(api.to(ACT('decline'))[0].body).toBeNull();
  });

  it('an expired quote shows the banner with a new-quote link and no accept or decline', async () => {
    setupApp(PATH, {
      [DETAIL]: detail(
        quoted({
          status: 'expired',
          valid_until: day(-2),
          allowed_actions: [],
        }),
      ),
    });
    const h = await heading();
    expect(h).toHaveTextContent('Expired');
    expect(h).not.toHaveTextContent('Quoted');
    expect(
      screen.getByText(new RegExp(`This quote expired on ${day(-2)}`)),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Request a new quote' }),
    ).toHaveAttribute('href', '/quotes/new');
    expect(
      screen.queryByRole('button', { name: /accept|decline/i }),
    ).toBeNull();
  });

  it('an accepted quote links to its order', async () => {
    setupApp(PATH, {
      [DETAIL]: detail(
        quoted({
          status: 'accepted',
          order: { number: 'ORD-K7Q2M-X9PA3' },
          allowed_actions: [],
        }),
      ),
    });
    await heading();
    expect(
      screen.getByRole('link', { name: 'ORD-K7Q2M-X9PA3' }),
    ).toHaveAttribute('href', '/orders/ORD-K7Q2M-X9PA3');
  });

  it('a 404 (unknown or foreign) shows Quote not found with a link back', async () => {
    setupApp(PATH, {
      [DETAIL]: { status: 404, body: { message: 'Not found.' } },
    });
    expect(
      await screen.findByRole('heading', { name: 'Quote not found' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Back to quotes' }),
    ).toHaveAttribute('href', '/quotes');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('a malformed number shows the same page and sends no request', async () => {
    const { api } = setupApp('/quotes/not%20valid!', {});
    expect(
      await screen.findByRole('heading', { name: 'Quote not found' }),
    ).toBeInTheDocument();
    expect(api.calls.some((c) => c.url.startsWith('/api/v1/quotes/'))).toBe(
      false,
    );
  });

  it('shows a loading state and a retryable error', async () => {
    const user = userEvent.setup();
    let fail = true;
    setupApp(PATH, {
      [DETAIL]: () =>
        fail ? { status: 500, body: { message: 'x' } } : detail(quote()),
    });
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    fail = false;
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await heading();
  });
});

describe('quote detail: attachments panel', () => {
  it('uploads picked files to the API and refetches; client rejections are listed and not sent', async () => {
    const user = userEvent.setup({ applyAccept: false });
    const { api } = setupApp(PATH, {
      [DETAIL]: detail(quote()),
      [ATTACH]: { status: 201, body: { data: ATT } },
    });
    await user.upload(await screen.findByLabelText('Add attachments'), [
      pdf('ok.pdf'),
      new File(['x'], 'evil.exe'),
    ]);
    await waitFor(() => expect(api.to(ATTACH)).toHaveLength(1));
    expect((api.to(ATTACH)[0].form?.get('file') as File).name).toBe('ok.pdf');
    expect(api.to(ATTACH)[0].headers.get('Content-Type')).toBeNull();
    expect(await screen.findByText(/evil\.exe: Only PDF/)).toBeInTheDocument();
    await waitFor(() => expect(api.to(DETAIL).length).toBeGreaterThan(1));
  });

  it.each([
    [
      '422',
      unprocessable({ file: ['The file must not be greater than 10 MB.'] }),
      'The file must not be greater than 10 MB.',
    ],
    [
      '413',
      { status: 413, body: { message: 'The upload is too large.' } },
      'The file is too large for the server.',
    ],
    [
      '429',
      { status: 429, headers: { 'Retry-After': '12' }, body: { message: 'x' } },
      'Too many attempts. Please try again in 12 seconds.',
    ],
  ])(
    'a %s on upload shows the message under the control',
    async (_s, response, text) => {
      const user = userEvent.setup({ applyAccept: false });
      setupApp(PATH, { [DETAIL]: detail(quote()), [ATTACH]: response });
      await user.upload(await screen.findByLabelText('Add attachments'), [
        pdf('a.pdf'),
      ]);
      expect(await screen.findByText(`a.pdf: ${text}`)).toBeInTheDocument();
    },
  );

  it('counts the files already on the quote: the 11th is rejected', async () => {
    const user = userEvent.setup({ applyAccept: false });
    const ten = Array.from({ length: 10 }, (_, i) => ({
      ...ATT,
      id: `3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b9${i}`,
      name: `f${i}.pdf`,
    }));
    const { api } = setupApp(PATH, {
      [DETAIL]: detail(quote({ attachments: ten })),
    });
    await user.upload(await screen.findByLabelText('Add attachments'), [
      pdf('eleven.pdf'),
    ]);
    expect(
      await screen.findByText('eleven.pdf: At most 10 files per quote.'),
    ).toBeInTheDocument();
    expect(api.to(ATTACH)).toHaveLength(0);
  });

  it('delete asks for confirmation and then calls the API', async () => {
    const user = userEvent.setup();
    const del = `DELETE /api/v1/quotes/${NUM}/attachments/${ATT.id}`;
    const { api } = setupApp(PATH, {
      [DETAIL]: detail(quote({ attachments: [ATT] })),
      [del]: { status: 204 },
    });
    await user.click(
      await screen.findByRole('button', { name: 'Delete drawing-rev2.pdf' }),
    );
    expect(api.to(del)).toHaveLength(0);
    await user.click(
      within(dialog('Delete this attachment?')).getByRole('button', {
        name: 'Yes, delete',
      }),
    );
    await waitFor(() => expect(api.to(del)).toHaveLength(1));
  });

  it('a closed quote shows the files without upload or delete controls', async () => {
    setupApp(PATH, {
      [DETAIL]: detail(quoted({ attachments: [ATT] })),
    });
    await heading();
    expect(
      screen.getByRole('link', { name: 'drawing-rev2.pdf' }),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText('Add attachments')).toBeNull();
    expect(screen.queryByRole('button', { name: /^Delete/ })).toBeNull();
  });
});

const inReview = (o: Partial<QuoteDetail> = {}) =>
  forStaff(quote({ status: 'in_review' }), {
    revision: 0,
    allowed_actions: ['price', 'send', 'reject', 'attach', 'note'],
    ...o,
  });

describe('quote detail (staff)', () => {
  it('shows the company, draft prices and the internal note; no customer actions', async () => {
    setupApp(
      PATH,
      asStaff(
        inReview({
          internal_note: 'Check lead time with supplier',
          items: [
            catalogItem({ unit_price_cents: 1250, line_total_cents: 12500 }),
            customItem(),
          ],
        }),
      ),
      staff,
    );
    await heading();
    expect(screen.getByText(/for Northwind Machinery Ltd/)).toBeInTheDocument();
    expect(
      screen.getByLabelText('Internal note (never shown to the customer)'),
    ).toHaveValue('Check lead time with supplier');
    expect(
      screen.queryByRole('button', { name: /accept|decline|withdraw/i }),
    ).toBeNull();
  });

  it('Take into review posts and shows the pricing form from the response', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(
      PATH,
      {
        ...asStaff(
          forStaff(quote(), {
            allowed_actions: ['review', 'reject', 'attach', 'note'],
          }),
        ),
        [ACT('review')]: detail(inReview()),
      },
      staff,
    );
    expect(screen.queryByRole('region', { name: 'Pricing' })).toBeNull();
    await user.click(
      await screen.findByRole('button', { name: 'Take into review' }),
    );
    expect(
      await screen.findByRole('heading', { name: 'Pricing' }),
    ).toBeInTheDocument();
    expect(api.to(ACT('review'))).toHaveLength(1);
    expect(
      screen.queryByRole('button', { name: 'Take into review' }),
    ).toBeNull();
  });

  it('pricing: 12.5 and 12.50 become 1250, empty means unpriced, body is { lines, valid_until }; server totals are shown after save', async () => {
    const user = userEvent.setup();
    const until = day(10);
    const saved = inReview({
      subtotal_cents: 28000,
      total_cents: 28000,
      valid_until: until,
      items: [
        catalogItem({ unit_price_cents: 1250, line_total_cents: 12500 }),
        customItem({ unit_price_cents: 3875, line_total_cents: 15500 }),
      ],
    });
    const { api } = setupApp(
      PATH,
      { ...asStaff(inReview()), [PRICING]: detail(saved) },
      staff,
    );
    const p1 = await screen.findByLabelText('Unit price for item 1');
    await user.type(p1, '12.5');
    // Preview only: 10 x 12.50.
    expect(screen.getByText('$125.00')).toBeInTheDocument();
    await user.type(screen.getByLabelText('Unit price for item 2'), '38.75');
    fireEvent.change(screen.getByLabelText(/Valid until/), {
      target: { value: until },
    });
    await user.click(screen.getByRole('button', { name: 'Save prices' }));
    expect(await screen.findByText('Prices saved.')).toBeInTheDocument();
    expect(api.to(PRICING)[0].body).toStrictEqual({
      lines: [
        { position: 1, unit_price_cents: 1250 },
        { position: 2, unit_price_cents: 3875 },
      ],
      valid_until: until,
    });
    expect(screen.getByText(/Saved total: \$280\.00/)).toBeInTheDocument();
  });

  it('an empty price is sent as null and an unchanged empty date is omitted', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(
      PATH,
      { ...asStaff(inReview()), [PRICING]: detail(inReview()) },
      staff,
    );
    await user.type(
      await screen.findByLabelText('Unit price for item 1'),
      '12.50',
    );
    await user.click(screen.getByRole('button', { name: 'Save prices' }));
    await waitFor(() => expect(api.to(PRICING)).toHaveLength(1));
    expect(api.to(PRICING)[0].body).toStrictEqual({
      lines: [
        { position: 1, unit_price_cents: 1250 },
        { position: 2, unit_price_cents: null },
      ],
    });
  });

  it.each(['-1', '1e3', '10000000.01', 'abc', '1.234', '12,5'])(
    'price %j is rejected inline and nothing is sent',
    async (bad) => {
      const user = userEvent.setup();
      const { api } = setupApp(PATH, asStaff(inReview()), staff);
      const input = await screen.findByLabelText('Unit price for item 1');
      fireEvent.change(input, { target: { value: bad } });
      await user.click(screen.getByRole('button', { name: 'Save prices' }));
      const alert = await screen.findByText(/Enter a price like 12\.50/);
      expect(alert).toHaveAttribute('role', 'alert');
      expect(input).toHaveAttribute('aria-invalid', 'true');
      expect(input).toHaveAttribute('aria-describedby', alert.id);
      expect(api.to(PRICING)).toHaveLength(0);
    },
  );

  it.each([[day(0)], [day(-3)], [day(400)]])(
    'valid_until %s is rejected (tomorrow to +365 days only)',
    async (bad) => {
      const user = userEvent.setup();
      const { api } = setupApp(PATH, asStaff(inReview()), staff);
      const input = await screen.findByLabelText(/Valid until/);
      fireEvent.change(input, { target: { value: bad } });
      await user.click(screen.getByRole('button', { name: 'Save prices' }));
      expect(
        await screen.findByText(/Choose a (valid )?date/),
      ).toBeInTheDocument();
      expect(api.to(PRICING)).toHaveLength(0);
    },
  );

  it('the date input carries min = tomorrow and max = +365 days (UTC)', async () => {
    setupApp(PATH, asStaff(inReview()), staff);
    const input = await screen.findByLabelText(/Valid until/);
    expect(input).toHaveAttribute('min', day(1));
    expect(input).toHaveAttribute('max', day(365));
  });

  it('a server 422 on a price is shown on its line', async () => {
    const user = userEvent.setup();
    setupApp(
      PATH,
      {
        ...asStaff(inReview()),
        [PRICING]: unprocessable({
          'lines.1.unit_price_cents': ['The unit price is too high.'],
        }),
      },
      staff,
    );
    await user.click(
      await screen.findByRole('button', { name: 'Save prices' }),
    );
    expect(
      await screen.findByText('The unit price is too high.'),
    ).toBeInTheDocument();
    expect(screen.getByLabelText('Unit price for item 2')).toHaveAttribute(
      'aria-invalid',
      'true',
    );
  });

  it('Send quote: optional note, server 422 messages are shown in the dialog', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(
      PATH,
      {
        ...asStaff(inReview()),
        [ACT('send')]: unprocessable({
          lines: ['All lines must be priced.'],
          valid_until: ['Choose a date after today.'],
        }),
      },
      staff,
    );
    await user.click(await screen.findByRole('button', { name: 'Send quote' }));
    const d = within(dialog('Send this quote to the customer?'));
    await user.type(
      d.getByLabelText('Note for the customer (optional)'),
      'Lead time 3 weeks',
    );
    await user.click(d.getByRole('button', { name: 'Send quote' }));
    expect(await d.findByRole('alert')).toHaveTextContent(
      'All lines must be priced. Choose a date after today.',
    );
    expect(api.to(ACT('send'))[0].body).toStrictEqual({
      staff_note: 'Lead time 3 weeks',
    });
  });

  it('Send quote succeeds and the status becomes Quoted', async () => {
    const user = userEvent.setup();
    const sent = forStaff(quoted(), {
      allowed_actions: ['reopen', 'note'],
      internal_note: null,
    });
    const { api } = setupApp(
      PATH,
      { ...asStaff(inReview()), [ACT('send')]: detail(sent) },
      staff,
    );
    await user.click(await screen.findByRole('button', { name: 'Send quote' }));
    await user.click(
      within(dialog('Send this quote to the customer?')).getByRole('button', {
        name: 'Send quote',
      }),
    );
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(api.to(ACT('send'))[0].body).toBeNull();
    expect(
      await screen.findByRole('heading', { name: /Quote QUO-/ }),
    ).toHaveTextContent('Quoted');
    expect(
      screen.getByRole('button', { name: 'Reopen for editing' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Send quote' })).toBeNull();
  });

  it('Reject needs a reason and sends it', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(
      PATH,
      {
        ...asStaff(inReview()),
        [ACT('reject')]: detail(
          forStaff(quote({ status: 'declined' }), {
            allowed_actions: ['note'],
          }),
        ),
      },
      staff,
    );
    await user.click(
      await screen.findByRole('button', { name: 'Reject request' }),
    );
    const d = within(dialog('Reject this quote request?'));
    await user.click(d.getByRole('button', { name: 'Reject request' }));
    expect(await d.findByRole('alert')).toHaveTextContent('Enter a reason.');
    expect(api.to(ACT('reject'))).toHaveLength(0);
    await user.type(d.getByLabelText('Reason'), 'We do not stock this series.');
    await user.click(d.getByRole('button', { name: 'Reject request' }));
    await waitFor(() => expect(api.to(ACT('reject'))).toHaveLength(1));
    expect(api.to(ACT('reject'))[0].body).toStrictEqual({
      reason: 'We do not stock this series.',
    });
    expect(
      await screen.findByRole('heading', { name: /Quote QUO-/ }),
    ).toHaveTextContent('Declined');
  });

  it('Reopen for editing posts and returns the quote to review', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(
      PATH,
      {
        ...asStaff(forStaff(quoted(), { allowed_actions: ['reopen', 'note'] })),
        [ACT('reopen')]: detail(inReview()),
      },
      staff,
    );
    await user.click(
      await screen.findByRole('button', { name: 'Reopen for editing' }),
    );
    expect(
      await screen.findByRole('heading', { name: 'Pricing' }),
    ).toBeInTheDocument();
    expect(api.to(ACT('reopen'))).toHaveLength(1);
  });

  it('a 422 on Take into review shows the message', async () => {
    const user = userEvent.setup();
    setupApp(
      PATH,
      {
        ...asStaff(
          forStaff(quote(), {
            allowed_actions: ['review', 'reject', 'attach', 'note'],
          }),
        ),
        [ACT('review')]: unprocessable({
          status: ['Cannot change status from in_review to in_review.'],
        }),
      },
      staff,
    );
    await user.click(
      await screen.findByRole('button', { name: 'Take into review' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /Cannot change status/,
    );
  });

  it('saves the internal note under its own label, and an empty note clears it', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(
      PATH,
      {
        ...asStaff(inReview({ internal_note: 'old' })),
        [NOTE]: detail(inReview({ internal_note: 'Margin ok' })),
      },
      staff,
    );
    const box = await screen.findByLabelText(
      'Internal note (never shown to the customer)',
    );
    await user.clear(box);
    await user.type(box, ' Margin ok ');
    await user.click(
      screen.getByRole('button', { name: 'Save internal note' }),
    );
    expect(await screen.findByText('Internal note saved.')).toBeInTheDocument();
    expect(api.to(NOTE)[0].body).toStrictEqual({ internal_note: 'Margin ok' });
    await user.clear(box);
    await user.click(
      screen.getByRole('button', { name: 'Save internal note' }),
    );
    await waitFor(() => expect(api.to(NOTE)).toHaveLength(2));
    expect(api.to(NOTE)[1].body).toStrictEqual({ internal_note: null });
  });

  it('staff can upload and delete attachments while the quote is open', async () => {
    setupApp(
      PATH,
      asStaff(
        inReview({
          attachments: [
            { ...ATT, uploaded_by: { name: 'Sam Staff', role: 'staff' } },
          ],
        }),
      ),
      staff,
    );
    await heading();
    expect(screen.getByLabelText('Add attachments')).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Delete drawing-rev2.pdf' }),
    ).toBeInTheDocument();
  });
});
