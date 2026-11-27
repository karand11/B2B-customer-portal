import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { admin } from '../../../test/utils';
import { COMPANIES, company, loc, paged, setupAdmin } from './fixtures';

const GET1 = 'GET /api/v1/admin/companies/1';
const PUT1 = 'PUT /api/v1/admin/companies/1';
const BILL1 = 'PUT /api/v1/admin/companies/1/billing';

describe('companies list', () => {
  it('shows the counts, a text Billing missing badge, and links to the detail', async () => {
    setupAdmin('/admin/companies', {
      [COMPANIES]: paged([
        company(),
        company({
          id: 2,
          name: 'Initech Bearings',
          has_billing_address: false,
          tax_id: null,
        }),
      ]),
    });
    const t = await screen.findByRole('table', { name: 'Companies' });
    expect(
      within(t).getByRole('link', { name: 'Northwind Machinery Ltd' }),
    ).toHaveAttribute('href', '/admin/companies/1');
    expect(within(t).getAllByText('Billing missing')).toHaveLength(1);
    expect(within(t).getByText('On file')).toBeInTheDocument();
    expect(
      within(t).getByRole('columnheader', { name: /^Name/ }),
    ).toHaveAttribute('aria-sort', 'ascending');
    expect(screen.queryByRole('link', { name: 'New company' })).toBeNull();
  });

  it('admin sees New company; the search sets q in the URL', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin(
      '/admin/companies',
      { [COMPANIES]: paged([company()]) },
      admin,
    );
    expect(
      await screen.findByRole('link', { name: 'New company' }),
    ).toHaveAttribute('href', '/admin/companies/new');
    await user.type(screen.getByLabelText('Company name'), 'north');
    await waitFor(() => expect(loc()).toHaveTextContent('?q=north'));
    await waitFor(() =>
      expect(api.calls.at(-1)?.url).toBe('/api/v1/admin/companies?q=north'),
    );
  });
});

describe('company detail (staff)', () => {
  it('shows counts, users as text, recent activity linked into the back office', async () => {
    setupAdmin('/admin/companies/1', { [GET1]: { body: { data: company() } } });
    await screen.findByRole('heading', { name: 'Northwind Machinery Ltd' });
    expect(
      screen.getByRole('link', { name: 'ORD-K7Q2M-X9PA3' }),
    ).toHaveAttribute('href', '/admin/orders/ORD-K7Q2M-X9PA3');
    expect(
      screen.getByRole('link', { name: 'QUO-K7Q2M-X9PA3' }),
    ).toHaveAttribute('href', '/admin/quotes/QUO-K7Q2M-X9PA3');
    expect(
      screen.getByRole('link', { name: 'INV-2026-000001' }),
    ).toHaveAttribute(
      'href',
      '/admin/invoices/3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90',
    );
    // Staff cannot open user pages: the name is plain text.
    expect(screen.queryByRole('link', { name: 'Dave Dealer' })).toBeNull();
    expect(screen.getByText('Dave Dealer')).toBeInTheDocument();
  });

  it('edits only billing: the name is read only, the body has billing_address, tax_id and version', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/companies/1', {
      [GET1]: { body: { data: company() } },
      [BILL1]: {
        body: {
          data: company({ version: 3, billing_address: 'A\nB', tax_id: 'X-1' }),
        },
      },
    });
    await screen.findByRole('heading', { name: 'Northwind Machinery Ltd' });
    expect(screen.queryByLabelText('Name')).toBeNull();
    const address = screen.getByLabelText('Billing address');
    expect(address).toHaveValue('1 Main St\nSpringfield');
    await user.clear(address);
    await user.type(address, '  A  {Enter}{Enter}   B  ');
    await user.clear(screen.getByLabelText('Tax ID'));
    await user.type(screen.getByLabelText('Tax ID'), 'X-1');
    await user.click(screen.getByRole('button', { name: 'Save company' }));
    await waitFor(() => expect(api.to(BILL1)).toHaveLength(1));
    expect(api.to(BILL1)[0].body).toEqual({
      billing_address: 'A\nB',
      tax_id: 'X-1',
      version: 2,
    });
    expect(api.to(PUT1)).toHaveLength(0);
    expect(await screen.findByText('Company saved.')).toBeInTheDocument();
  });

  it('empty billing and tax id are sent as null', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/companies/1', {
      [GET1]: { body: { data: company() } },
      [BILL1]: {
        body: {
          data: company({ version: 3, billing_address: null, tax_id: null }),
        },
      },
    });
    await screen.findByRole('heading', { name: 'Northwind Machinery Ltd' });
    await user.clear(screen.getByLabelText('Billing address'));
    await user.clear(screen.getByLabelText('Tax ID'));
    await user.click(screen.getByRole('button', { name: 'Save company' }));
    await waitFor(() => expect(api.to(BILL1)).toHaveLength(1));
    expect(api.to(BILL1)[0].body).toMatchObject({
      billing_address: null,
      tax_id: null,
    });
  });

  it('validates up to 6 lines of 80 characters and the tax id before sending', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/companies/1', {
      [GET1]: { body: { data: company() } },
    });
    await screen.findByRole('heading', { name: 'Northwind Machinery Ltd' });
    const address = screen.getByLabelText('Billing address');
    await user.clear(address);
    await user.type(
      address,
      '1{Enter}2{Enter}3{Enter}4{Enter}5{Enter}6{Enter}7',
    );
    await user.click(screen.getByRole('button', { name: 'Save company' }));
    expect(await screen.findByText('Use at most 6 lines.')).toBeInTheDocument();
    await user.clear(address);
    await user.type(address, 'x'.repeat(81));
    await user.clear(screen.getByLabelText('Tax ID'));
    await user.type(screen.getByLabelText('Tax ID'), '!!');
    await user.click(screen.getByRole('button', { name: 'Save company' }));
    expect(
      await screen.findByText('Each line may have at most 80 characters.'),
    ).toBeInTheDocument();
    expect(screen.getByText(/Use letters, digits, spaces/)).toBeInTheDocument();
    expect(api.to(BILL1)).toHaveLength(0);
  });

  it('a 409 shows the conflict banner and Reload latest refetches and resets the form', async () => {
    const user = userEvent.setup();
    let reads = 0;
    setupAdmin('/admin/companies/1', {
      [GET1]: () => ({
        body: {
          data:
            reads++ === 0
              ? company()
              : company({ version: 5, billing_address: 'Fresh address' }),
        },
      }),
      [BILL1]: {
        status: 409,
        body: { message: 'x', errors: { version: ['x'] } },
      },
    });
    await screen.findByRole('heading', { name: 'Northwind Machinery Ltd' });
    const address = screen.getByLabelText('Billing address');
    await user.clear(address);
    await user.type(address, 'Mine');
    await user.click(screen.getByRole('button', { name: 'Save company' }));
    const banner = await screen.findByRole('alert');
    expect(banner).toHaveTextContent('changed by someone else');
    await user.click(
      within(banner).getByRole('button', { name: 'Reload latest' }),
    );
    await waitFor(() =>
      expect(screen.getByLabelText('Billing address')).toHaveValue(
        'Fresh address',
      ),
    );
    expect(
      screen.getByText('Your changes').closest('details'),
    ).toHaveTextContent('Billing address: Mine');
  });

  it('warns when the billing address is missing', async () => {
    setupAdmin('/admin/companies/1', {
      [GET1]: {
        body: {
          data: company({ has_billing_address: false, billing_address: null }),
        },
      },
    });
    expect(
      await screen.findByText(/Billing missing: add a billing address/),
    ).toBeInTheDocument();
  });

  it('shows Company not found for a 404', async () => {
    setupAdmin('/admin/companies/1', {
      [GET1]: { status: 404, body: { message: 'Not found.' } },
    });
    expect(
      await screen.findByRole('heading', { name: 'Company not found' }),
    ).toBeInTheDocument();
  });
});

describe('company detail and creation (admin)', () => {
  it('admin can rename: PUT sends name, billing_address, tax_id and version', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin(
      '/admin/companies/1',
      {
        [GET1]: { body: { data: company() } },
        [PUT1]: {
          body: { data: company({ version: 3, name: 'Northwind 2' }) },
        },
      },
      admin,
    );
    await screen.findByRole('heading', { name: 'Northwind Machinery Ltd' });
    const name = screen.getByLabelText('Name');
    await user.clear(name);
    await user.type(name, 'Northwind 2');
    await user.click(screen.getByRole('button', { name: 'Save company' }));
    await waitFor(() => expect(api.to(PUT1)).toHaveLength(1));
    expect(api.to(PUT1)[0].body).toEqual({
      name: 'Northwind 2',
      billing_address: '1 Main St\nSpringfield',
      tax_id: 'NW-TAX-0001',
      version: 2,
    });
    // Admins link to the user pages.
    expect(screen.getByRole('link', { name: 'Dave Dealer' })).toHaveAttribute(
      'href',
      '/admin/users/7',
    );
  });

  it('creates a company and maps 422 errors onto fields', async () => {
    const user = userEvent.setup();
    let n = 0;
    const { api } = setupAdmin(
      '/admin/companies/new',
      {
        'POST /api/v1/admin/companies': () =>
          n++ === 0
            ? {
                status: 422,
                body: {
                  message: 'x',
                  errors: { name: ['The name is invalid.'] },
                },
              }
            : {
                status: 201,
                body: { data: company({ id: 9, name: 'New Co' }) },
              },
        'GET /api/v1/admin/companies/9': {
          body: { data: company({ id: 9, name: 'New Co' }) },
        },
      },
      admin,
    );
    await screen.findByRole('heading', { name: 'New company' });
    await user.click(screen.getByRole('button', { name: 'Create company' }));
    expect(await screen.findByText('Enter a name.')).toBeInTheDocument();
    await user.type(screen.getByLabelText('Name'), 'New Co');
    await user.click(screen.getByRole('button', { name: 'Create company' }));
    expect(await screen.findByText('The name is invalid.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Create company' }));
    await waitFor(() => expect(loc()).toHaveTextContent('/admin/companies/9'));
    expect(api.to('POST /api/v1/admin/companies')[1].body).toEqual({
      name: 'New Co',
      billing_address: null,
      tax_id: null,
    });
  });

  it('staff opening /admin/companies/new sees Not allowed and nothing is posted', async () => {
    const { api } = setupAdmin('/admin/companies/new');
    expect(
      await screen.findByRole('heading', { name: 'Not allowed' }),
    ).toBeInTheDocument();
    expect(api.calls.some((c) => c.method === 'POST')).toBe(false);
  });
});
