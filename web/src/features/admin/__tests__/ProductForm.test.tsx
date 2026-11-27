import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { loc, lastCall, product, setupAdmin } from './fixtures';

const POST = 'POST /api/v1/admin/products';
const GET17 = 'GET /api/v1/admin/products/17';
const PUT17 = 'PUT /api/v1/admin/products/17';

type User = ReturnType<typeof userEvent.setup>;

async function fillCreate(user: User) {
  await screen.findByRole('option', { name: 'Deep groove ball bearings' });
  await user.selectOptions(screen.getByLabelText('Category'), '1');
  await user.type(screen.getByLabelText('Part number'), ' 6205-2rs ');
  await user.type(screen.getByLabelText('Name'), 'Deep groove ball bearing');
  await user.type(screen.getByLabelText('Brand'), 'SKF');
  await user.type(screen.getByLabelText('Material'), 'Chrome steel');
  await user.type(screen.getByLabelText('Inner diameter (mm)'), '25');
  await user.type(screen.getByLabelText('Outer diameter (mm)'), '52');
  await user.type(screen.getByLabelText('Width (mm)'), '15');
  await user.selectOptions(screen.getByLabelText('Seal type'), '2rs');
}

describe('product form (create)', () => {
  it('uppercases the part number on blur and sends the documented body with integer cents', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/products/new', {
      [POST]: { status: 201, body: { data: product({ id: 99 }) } },
      'GET /api/v1/admin/products/99': { body: { data: product({ id: 99 }) } },
    });
    await screen.findByRole('heading', { name: 'New product' });
    await fillCreate(user);
    await user.type(screen.getByLabelText('Price (USD)'), '12.50');
    // Blur is what normalises the part number.
    await user.click(screen.getByLabelText('Part number'));
    await user.tab();
    expect(screen.getByLabelText('Part number')).toHaveValue('6205-2RS');
    await user.click(screen.getByRole('button', { name: 'Create product' }));
    await waitFor(() => expect(api.to(POST)).toHaveLength(1));
    expect(api.to(POST)[0].body).toEqual({
      category_id: 1,
      part_number: '6205-2RS',
      name: 'Deep groove ball bearing',
      description: null,
      brand: 'SKF',
      material: 'Chrome steel',
      inner_diameter_mm: 25,
      outer_diameter_mm: 52,
      width_mm: 15,
      seal_type: '2rs',
      stock_status: 'in_stock',
      price_on_request: false,
      price_cents: 1250,
    });
    expect(Object.keys(api.to(POST)[0].body ?? {})).not.toContain('active');
    // Created: goes to the new product's page.
    await waitFor(() => expect(loc()).toHaveTextContent('/admin/products/99'));
    expect(await screen.findByText('Product created.')).toBeInTheDocument();
  });

  it('Price on request clears and disables the price and sends a null price', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/products/new', {
      [POST]: { status: 201, body: { data: product({ id: 99 }) } },
      'GET /api/v1/admin/products/99': { body: { data: product({ id: 99 }) } },
    });
    await screen.findByRole('heading', { name: 'New product' });
    await fillCreate(user);
    await user.type(screen.getByLabelText('Price (USD)'), '12.50');
    await user.click(screen.getByLabelText('Price on request'));
    expect(screen.getByLabelText('Price (USD)')).toBeDisabled();
    expect(screen.getByLabelText('Price (USD)')).toHaveValue('');
    await user.click(screen.getByRole('button', { name: 'Create product' }));
    await waitFor(() => expect(api.to(POST)).toHaveLength(1));
    expect(api.to(POST)[0].body).toMatchObject({
      price_on_request: true,
      price_cents: null,
    });
  });

  it('mirrors the server rules and sends nothing while invalid', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/products/new');
    await screen.findByRole('heading', { name: 'New product' });
    await user.click(screen.getByRole('button', { name: 'Create product' }));
    expect(await screen.findByText('Choose a category.')).toBeInTheDocument();
    expect(screen.getByText('Enter a part number.')).toBeInTheDocument();
    expect(screen.getByText(/Enter a price like 12.50/)).toBeInTheDocument();

    await user.type(screen.getByLabelText('Part number'), '-bad');
    await user.type(screen.getByLabelText('Name'), '=SUM(A1)');
    await user.type(screen.getByLabelText('Inner diameter (mm)'), '52');
    await user.type(screen.getByLabelText('Outer diameter (mm)'), '52');
    await user.type(screen.getByLabelText('Width (mm)'), '5000.01');
    await user.type(screen.getByLabelText('Price (USD)'), '12.505');
    await user.click(screen.getByRole('button', { name: 'Create product' }));
    expect(
      await screen.findByText(/start with a letter or digit/),
    ).toBeInTheDocument();
    expect(
      screen.getByText('The name must not start with = + - @.'),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        'The outer diameter must be greater than the inner diameter.',
      ),
    ).toBeInTheDocument();
    expect(screen.getByText(/at most 5000 mm/)).toBeInTheDocument();
    expect(api.to(POST)).toHaveLength(0);
  });

  it('maps 422 errors to their fields, including part_number and price_cents', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/new', {
      [POST]: {
        status: 422,
        body: {
          message: 'The given data was invalid.',
          errors: {
            part_number: ['This part number is already in use.'],
            price_cents: ['The price is too high.'],
            category_id: ['The selected category is invalid.'],
          },
        },
      },
    });
    await screen.findByRole('heading', { name: 'New product' });
    await fillCreate(user);
    await user.type(screen.getByLabelText('Price (USD)'), '12.50');
    await user.click(screen.getByRole('button', { name: 'Create product' }));
    const part = await screen.findByText('This part number is already in use.');
    expect(part).toHaveAttribute('role', 'alert');
    expect(screen.getByLabelText('Part number')).toHaveAttribute(
      'aria-describedby',
      'part_number-error',
    );
    expect(screen.getByText('The price is too high.')).toBeInTheDocument();
    expect(
      screen.getByText('The selected category is invalid.'),
    ).toBeInTheDocument();
  });

  it('shows unmapped server errors as one alert', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/new', {
      [POST]: { status: 500, body: {} },
    });
    await screen.findByRole('heading', { name: 'New product' });
    await fillCreate(user);
    await user.type(screen.getByLabelText('Price (USD)'), '12.50');
    await user.click(screen.getByRole('button', { name: 'Create product' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /something went wrong/i,
    );
  });
});

describe('product form (edit)', () => {
  const routes = (p = product(), extra = {}) => ({
    [GET17]: { body: { data: p } },
    ...extra,
  });

  it('loads the product and submits only documented fields plus version, never active', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin(
      '/admin/products/17',
      routes(product(), {
        [PUT17]: { body: { data: product({ version: 4, name: 'Renamed' }) } },
      }),
    );
    await screen.findByRole('heading', { name: 'Product 6205-2RS' });
    expect(screen.getByLabelText('Name')).toHaveValue(
      'Deep groove ball bearing 6205-2RS 25x52x15',
    );
    expect(screen.getByLabelText('Price (USD)')).toHaveValue('12.50');
    await user.clear(screen.getByLabelText('Name'));
    await user.type(screen.getByLabelText('Name'), 'Renamed');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(api.to(PUT17)).toHaveLength(1));
    const body = api.to(PUT17)[0].body!;
    expect(body.version).toBe(3);
    expect(body).not.toHaveProperty('active');
    expect(Object.keys(body).sort()).toEqual(
      [
        'brand',
        'category_id',
        'description',
        'inner_diameter_mm',
        'material',
        'name',
        'outer_diameter_mm',
        'part_number',
        'price_cents',
        'price_on_request',
        'seal_type',
        'stock_status',
        'version',
        'width_mm',
      ].sort(),
    );
    expect(await screen.findByText('Product saved.')).toBeInTheDocument();
    // The next save carries the new version.
    await user.clear(screen.getByLabelText('Name'));
    await user.type(screen.getByLabelText('Name'), 'Again');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(api.to(PUT17)).toHaveLength(2));
    expect(api.to(PUT17)[1].body!.version).toBe(4);
  });

  it('warns about a price change and says existing documents keep their prices', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/17', routes());
    await screen.findByRole('heading', { name: 'Product 6205-2RS' });
    const price = screen.getByLabelText('Price (USD)');
    await user.clear(price);
    await user.type(price, '13');
    expect(
      await screen.findByText(
        /change the price from \$12\.50 to \$13\.00.*keep their original prices/,
      ),
    ).toBeInTheDocument();
    await user.click(screen.getByLabelText('Price on request'));
    expect(
      await screen.findByText(/switch this product to "Price on request"/),
    ).toBeInTheDocument();
  });

  it('a 409 shows the conflict banner; Reload latest refetches, resets the form and keeps my values listed', async () => {
    const user = userEvent.setup();
    let reads = 0;
    const { api } = setupAdmin('/admin/products/17', {
      [GET17]: () => ({
        body: {
          data:
            reads++ === 0
              ? product()
              : product({ version: 5, name: 'Changed by Sam' }),
        },
      }),
      [PUT17]: {
        status: 409,
        body: {
          message:
            'This record was changed by someone else. Reload it and try again.',
          errors: {
            version: [
              'This record was changed by someone else. Reload it and try again.',
            ],
          },
        },
      },
    });
    await screen.findByRole('heading', { name: 'Product 6205-2RS' });
    const name = screen.getByLabelText('Name');
    await user.clear(name);
    await user.type(name, 'My name');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    const banner = await screen.findByRole('alert');
    expect(banner).toHaveTextContent('changed by someone else');
    expect(screen.getByLabelText('Name')).toHaveValue('My name');

    await user.click(
      within(banner).getByRole('button', { name: 'Reload latest' }),
    );
    await waitFor(() =>
      expect(screen.getByLabelText('Name')).toHaveValue('Changed by Sam'),
    );
    expect(screen.queryByText(/changed by someone else/)).toBeNull();
    // My unsaved value is still listed in a collapsed disclosure.
    const mine = screen.getByText('Your changes').closest('details')!;
    expect(mine).not.toHaveAttribute('open');
    expect(mine).toHaveTextContent('Name: My name');
    expect(api.to(GET17).length).toBeGreaterThanOrEqual(2);
  });

  it('a successful reload then save uses the fresh version', async () => {
    const user = userEvent.setup();
    let reads = 0;
    let puts = 0;
    const { api } = setupAdmin('/admin/products/17', {
      [GET17]: () => ({
        body: { data: reads++ === 0 ? product() : product({ version: 5 }) },
      }),
      [PUT17]: () =>
        puts++ === 0
          ? { status: 409, body: { message: 'x', errors: { version: ['x'] } } }
          : { body: { data: product({ version: 6 }) } },
    });
    await screen.findByRole('heading', { name: 'Product 6205-2RS' });
    await user.type(screen.getByLabelText('Brand'), 'X');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await user.click(
      await screen.findByRole('button', { name: 'Reload latest' }),
    );
    await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
    await user.type(screen.getByLabelText('Brand'), 'Y');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(api.to(PUT17)).toHaveLength(2));
    expect(api.to(PUT17)[1].body!.version).toBe(5);
  });

  it('Deactivate asks for confirmation, explains the effect and posts without a body of its own', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin(
      '/admin/products/17',
      routes(product(), {
        'POST /api/v1/admin/products/17/deactivate': {
          body: { data: product({ active: false, version: 4 }) },
        },
      }),
    );
    await user.click(
      await screen.findByRole('button', { name: 'Deactivate product' }),
    );
    const dialog = screen.getByRole('dialog', {
      name: 'Deactivate this product?',
    });
    expect(dialog).toHaveTextContent(/no longer find or order this product/);
    // Escape cancels and sends nothing.
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(api.to('POST /api/v1/admin/products/17/deactivate')).toHaveLength(0);

    await user.click(
      screen.getByRole('button', { name: 'Deactivate product' }),
    );
    await user.click(
      within(screen.getByRole('dialog')).getByRole('button', {
        name: 'Deactivate',
      }),
    );
    await waitFor(() =>
      expect(api.to('POST /api/v1/admin/products/17/deactivate')).toHaveLength(
        1,
      ),
    );
    expect(await screen.findByText('Product deactivated.')).toBeInTheDocument();
  });

  it('shows Activate for an inactive product', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin(
      '/admin/products/17',
      routes(product({ active: false }), {
        'POST /api/v1/admin/products/17/activate': {
          body: { data: product({ version: 4 }) },
        },
      }),
    );
    await screen.findByRole('heading', { name: 'Product 6205-2RS' });
    expect(screen.getByText('Inactive')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Activate product' }));
    await user.click(
      within(screen.getByRole('dialog')).getByRole('button', {
        name: 'Activate',
      }),
    );
    await waitFor(() =>
      expect(api.to('POST /api/v1/admin/products/17/activate')).toHaveLength(1),
    );
  });

  it('shows Product not found for a 404 and for a malformed id without a request', async () => {
    setupAdmin('/admin/products/17', {
      [GET17]: { status: 404, body: { message: 'Not found.' } },
    });
    expect(
      await screen.findByRole('heading', { name: 'Product not found' }),
    ).toBeInTheDocument();
  });

  it('a malformed id sends no request', async () => {
    const { api } = setupAdmin('/admin/products/abc');
    expect(
      await screen.findByRole('heading', { name: 'Product not found' }),
    ).toBeInTheDocument();
    expect(lastCall(api, 'GET /api/v1/admin/products')).toBeUndefined();
  });
});
