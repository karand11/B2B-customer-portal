import { screen } from '@testing-library/react';
import {
  ME,
  P1,
  cartOf,
  line,
  order,
  setupApp,
} from '../features/cart/__tests__/fixtures';
import { setupAdmin, company } from '../features/admin/__tests__/fixtures';
import {
  DETAIL as QUOTE,
  NUM,
  detail as quoteDetail,
  forStaff,
  quote,
  quoted,
} from '../features/quotes/__tests__/fixtures';
import {
  INV_PATH,
  INV_URL,
  detail as invoiceDetail,
} from '../features/invoices/__tests__/fixtures';
import { meOk, staff } from '../test/utils';

/** Heading levels in document order, dialogs included. */
const levels = () =>
  screen.getAllByRole('heading').map((h) => Number(h.tagName.slice(1)));

function expectStructure() {
  // Tables: a caption (accessible name) and scope on every header cell.
  for (const t of screen.queryAllByRole('table'))
    expect(t).toHaveAccessibleName();
  document
    .querySelectorAll('th')
    .forEach((th) => expect(th, th.textContent ?? '').toHaveAttribute('scope'));
  const l = levels();
  expect(
    l.filter((n) => n === 1),
    'exactly one h1',
  ).toHaveLength(1);
  expect(l[0], 'the h1 comes first').toBe(1);
  l.forEach((n, i) => {
    if (i > 0)
      expect(
        n,
        `heading ${i} jumps from h${l[i - 1]} to h${n}`,
      ).toBeLessThanOrEqual(l[i - 1] + 1);
  });
}

describe('page structure (axe heading-order, one h1, table captions and header scopes)', () => {
  it('quote request form', async () => {
    setupApp('/quotes/new');
    await screen.findByRole('heading', { name: 'Request a quote' });
    expectStructure();
  });

  it('quote detail (customer, with items and history)', async () => {
    setupApp(`/quotes/${NUM}`, {
      [QUOTE]: quoteDetail(quoted({ attachments: [] })),
    });
    await screen.findByRole('heading', { name: /Quote QUO-/ });
    expectStructure();
  });

  it('quote detail (staff: pricing form and notes)', async () => {
    setupApp(
      `/quotes/${NUM}`,
      {
        [ME]: meOk(staff),
        [QUOTE]: quoteDetail(
          forStaff(quote({ status: 'in_review' }), {
            revision: 0,
            allowed_actions: ['price', 'send', 'reject', 'attach', 'note'],
          }),
        ),
      },
      staff,
    );
    await screen.findByRole('heading', { name: /Quote QUO-/ });
    expectStructure();
  });

  it('order detail', async () => {
    setupApp('/orders/ORD-K7Q2M-X9PA3', {
      'GET /api/v1/orders/ORD-K7Q2M-X9PA3': { body: { data: order() } },
    });
    await screen.findByRole('heading', { name: /Order ORD-/ });
    expectStructure();
  });

  it('invoice detail', async () => {
    setupApp(INV_PATH, { [INV_URL]: { body: { data: invoiceDetail() } } });
    await screen.findByRole('heading', { name: /Invoice INV/ });
    expectStructure();
  });

  it('checkout', async () => {
    setupApp('/checkout', {
      'GET /api/v1/cart': { body: cartOf([line(P1, 2)]) },
    });
    await screen.findByRole('heading', { name: 'Checkout' });
    expectStructure();
  });

  it('back office company detail', async () => {
    setupAdmin('/admin/companies/1', {
      'GET /api/v1/admin/companies/1': { body: { data: company() } },
    });
    await screen.findByRole('heading', { name: 'Northwind Machinery Ltd' });
    expectStructure();
  });
});
