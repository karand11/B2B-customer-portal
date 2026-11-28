import { screen } from '@testing-library/react';
import {
  ME,
  ORDERS,
  P1,
  cartOf,
  line,
  listOf as orderList,
  order,
  setupApp,
} from '../features/cart/__tests__/fixtures';
import {
  COMPANIES,
  PRODUCTS,
  company,
  paged,
  product,
  setupAdmin,
} from '../features/admin/__tests__/fixtures';
import {
  DETAIL as QUOTE,
  LIST as QUOTES,
  NUM,
  detail as quoteDetail,
  forStaff,
  listOf as quoteList,
  quote,
  quoted,
} from '../features/quotes/__tests__/fixtures';
import {
  INVOICES,
  INV_PATH,
  INV_URL,
  allStatuses,
  detail as invoiceDetail,
  listOfInvoices,
} from '../features/invoices/__tests__/fixtures';
import { meOk, staff } from '../test/utils';

/** jsdom cannot measure layout, so check the structure that makes wide tables safe. */
function expectTablesScrollable() {
  const tables = screen.getAllByRole('table');
  expect(tables.length).toBeGreaterThan(0);
  for (const table of tables) {
    const region = table.closest('[role="region"]');
    expect(
      region,
      `table "${table.querySelector('caption')?.textContent}"`,
    ).not.toBeNull();
    expect(region).toHaveAttribute('tabindex', '0'); // keyboard-scrollable (axe scrollable-region-focusable)
    expect(region).toHaveAccessibleName();
    expect(region).toHaveClass('overflow-x-auto');
  }
}

describe('wide tables sit in a keyboard-accessible scroll region', () => {
  it('orders list', async () => {
    setupApp('/orders', { [ORDERS]: { body: orderList([order()]) } });
    await screen.findByRole('table', { name: 'Orders' });
    expectTablesScrollable();
  });

  it('order detail', async () => {
    setupApp('/orders/ORD-K7Q2M-X9PA3', {
      'GET /api/v1/orders/ORD-K7Q2M-X9PA3': { body: { data: order() } },
    });
    await screen.findByRole('table');
    expectTablesScrollable();
  });

  it('quotes list', async () => {
    setupApp('/quotes', { [QUOTES]: { body: quoteList([quote()]) } });
    await screen.findByRole('table', { name: 'Quotes' });
    expectTablesScrollable();
  });

  it('quote detail', async () => {
    setupApp(`/quotes/${NUM}`, { [QUOTE]: quoteDetail(quoted()) });
    await screen.findByRole('table', { name: 'Requested items' });
    expectTablesScrollable();
  });

  it('back office quote pricing', async () => {
    setupApp(
      `/admin/quotes/${NUM}`,
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
    await screen.findByRole('table', { name: 'Unit prices' });
    expectTablesScrollable();
  });

  it('invoices list', async () => {
    setupApp('/invoices', {
      [INVOICES]: { body: listOfInvoices(allStatuses()) },
    });
    await screen.findByRole('table', { name: 'Invoices' });
    expectTablesScrollable();
  });

  it('invoice detail', async () => {
    setupApp(INV_PATH, { [INV_URL]: { body: { data: invoiceDetail() } } });
    await screen.findByRole('table', { name: 'Invoice lines' });
    expectTablesScrollable();
  });

  it('cart', async () => {
    setupApp('/cart', { 'GET /api/v1/cart': { body: cartOf([line(P1, 2)]) } });
    await screen.findByRole('table', { name: 'Items in your cart' });
    expectTablesScrollable();
  });

  it('checkout', async () => {
    setupApp('/checkout', {
      'GET /api/v1/cart': { body: cartOf([line(P1, 2)]) },
    });
    await screen.findByRole('table', { name: 'Order review' });
    expectTablesScrollable();
  });

  it('back office orders (shared DataTable-style list)', async () => {
    setupAdmin('/admin/orders?status=pending', {
      [ORDERS]: { body: orderList([order()]) },
    });
    await screen.findByRole('table', { name: 'Orders' });
    expectTablesScrollable();
  });

  it('DataTable lists (products, companies)', async () => {
    setupAdmin('/admin/products', { [PRODUCTS]: paged([product()]) });
    await screen.findByRole('table', { name: 'Products' });
    expectTablesScrollable();
  });

  it('company detail users', async () => {
    setupAdmin('/admin/companies/1', {
      'GET /api/v1/admin/companies/1': { body: { data: company() } },
      [COMPANIES]: paged([company()]),
    });
    await screen.findByRole('heading', { name: 'Northwind Machinery Ltd' });
    expectTablesScrollable();
  });
});

describe('phone layout structure', () => {
  it('the main navigation wraps instead of overflowing', async () => {
    setupApp('/cart', { 'GET /api/v1/cart': { body: cartOf([line(P1, 1)]) } });
    await screen.findByRole('table', { name: 'Items in your cart' });
    const list = screen
      .getByRole('navigation', { name: 'Main' })
      .querySelector('ul');
    expect(list).toHaveClass('flex-wrap');
  });
});
