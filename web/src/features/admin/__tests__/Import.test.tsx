import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { loc, preview, setupAdmin } from './fixtures';

const UP = 'POST /api/v1/admin/products/imports';
const APPLY = `POST /api/v1/admin/products/imports/${preview().id}/apply`;
const csv = (name = 'catalog.csv') =>
  new File(['part_number,name\n'], name, { type: 'text/csv' });

async function upload(user: ReturnType<typeof userEvent.setup>) {
  await screen.findByRole('heading', { name: /step 1 of 3/i });
  await user.upload(screen.getByLabelText('CSV file'), csv());
  await user.click(screen.getByRole('button', { name: 'Upload and check' }));
}

describe('import wizard', () => {
  it('step 1: a labelled file input with a size hint; upload sends FormData with file and no manual content type', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/products/import', {
      [UP]: { status: 201, body: { data: preview() } },
    });
    const input = await screen.findByLabelText('CSV file');
    expect(input).toHaveAttribute('accept', '.csv,text/csv');
    expect(input).toHaveAccessibleDescription(/5 MiB and 5,000 rows/);
    await upload(user);
    await screen.findByRole('heading', { name: /step 2 of 3/i });
    const req = api.to(UP)[0];
    expect(req.form).toBeInstanceOf(FormData);
    expect((req.form!.get('file') as File).name).toBe('catalog.csv');
    expect(req.headers.get('Content-Type')).toBeNull();
    expect(req.headers.get('X-XSRF-TOKEN')).toBe('tok=123');
  });

  it('step 2 shows counts, line-numbered errors as text, "and N more", warnings and the expiry', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/import', {
      [UP]: {
        status: 201,
        body: {
          data: preview({
            bad_rows: 103,
            errors: [
              {
                line: 14,
                messages: [
                  'outer_diameter_mm: must be greater than inner_diameter_mm',
                ],
              },
              {
                line: 20,
                messages: ['name: <img src=x onerror=alert(1)>', '=cmd|calc'],
              },
            ],
            errors_truncated: true,
            warnings: ['unknown column "foo" ignored'],
          }),
        },
      },
    });
    await upload(user);
    await screen.findByRole('heading', { name: /step 2 of 3/i });
    const dl = screen.getByText('Would create').closest('dl')!;
    expect(dl).toHaveTextContent('Rows read200');
    expect(dl).toHaveTextContent('Would create150');
    expect(dl).toHaveTextContent('Would update47');
    expect(dl).toHaveTextContent('Invalid rows103');
    const table = screen.getByRole('table', { name: 'Invalid rows' });
    const rows = within(table).getAllByRole('row');
    expect(rows[1]).toHaveTextContent('14');
    expect(rows[2]).toHaveTextContent('20');
    // Hostile content stays text.
    expect(
      within(table).getByText('name: <img src=x onerror=alert(1)>'),
    ).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
    expect(screen.getByText('and 101 more')).toBeInTheDocument();
    expect(
      screen.getByText('unknown column "foo" ignored'),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/expires at 2026-11-27 11:15 UTC/),
    ).toBeInTheDocument();
  });

  it('moves focus to the heading on every step change', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/import', {
      [UP]: { status: 201, body: { data: preview() } },
    });
    await upload(user);
    const h = await screen.findByRole('heading', { name: /step 2 of 3/i });
    await waitFor(() => expect(h).toHaveFocus());
  });

  it('with invalid rows, Apply stays disabled until "Skip the N invalid rows" is ticked, then confirms and applies', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/products/import', {
      [UP]: {
        status: 201,
        body: {
          data: preview({
            bad_rows: 3,
            errors: [{ line: 4, messages: ['bad'] }],
          }),
        },
      },
      [APPLY]: {
        body: {
          data: {
            id: preview().id,
            status: 'applied',
            rows_read: 200,
            created: 148,
            updated: 49,
            bad_rows: 3,
          },
        },
      },
    });
    await upload(user);
    const apply = await screen.findByRole('button', { name: 'Apply import' });
    expect(apply).toBeDisabled();
    await user.click(screen.getByLabelText('Skip the 3 invalid rows'));
    expect(apply).toBeEnabled();
    await user.click(apply);
    const dialog = screen.getByRole('dialog', { name: 'Apply this import?' });
    expect(dialog).toHaveTextContent(
      '150 products will be created and 47 updated',
    );
    expect(dialog).toHaveTextContent('3 invalid rows will be skipped');
    expect(api.to(APPLY)).toHaveLength(0);
    await user.click(within(dialog).getByRole('button', { name: 'Confirm' }));
    await screen.findByRole('heading', { name: /step 3 of 3/i });
    expect(api.to(APPLY)).toHaveLength(1);
    expect(api.to(APPLY)[0].body).toEqual({ skip_invalid: true });
    const result = screen.getByText('Created').closest('dl')!;
    expect(result).toHaveTextContent('Created148');
    expect(result).toHaveTextContent('Updated49');
    expect(result).toHaveTextContent('Invalid rows skipped3');
    expect(
      screen.getByRole('link', { name: 'Back to products' }),
    ).toHaveAttribute('href', '/admin/products');
  });

  it('with no invalid rows there is no skip checkbox and Apply is enabled', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/import', {
      [UP]: { status: 201, body: { data: preview() } },
    });
    await upload(user);
    expect(
      await screen.findByRole('button', { name: 'Apply import' }),
    ).toBeEnabled();
    expect(screen.queryByRole('checkbox')).toBeNull();
  });

  it('Escape in the confirm dialog sends nothing', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/products/import', {
      [UP]: { status: 201, body: { data: preview() } },
    });
    await upload(user);
    await user.click(
      await screen.findByRole('button', { name: 'Apply import' }),
    );
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(api.to(APPLY)).toHaveLength(0);
  });

  it('a double click on Confirm sends one apply request', async () => {
    const user = userEvent.setup();
    const { api } = setupAdmin('/admin/products/import', {
      [UP]: { status: 201, body: { data: preview() } },
      [APPLY]: async () => {
        await new Promise((r) => setTimeout(r, 50));
        return {
          body: {
            data: {
              id: preview().id,
              status: 'applied',
              rows_read: 1,
              created: 1,
              updated: 0,
              bad_rows: 0,
            },
          },
        };
      },
    });
    await upload(user);
    await user.click(
      await screen.findByRole('button', { name: 'Apply import' }),
    );
    await user.dblClick(
      within(screen.getByRole('dialog')).getByRole('button', {
        name: 'Confirm',
      }),
    );
    await screen.findByRole('heading', { name: /step 3 of 3/i });
    expect(api.to(APPLY)).toHaveLength(1);
  });

  it.each([
    ['expired', 'This preview has expired. Upload the file again.'],
    ['already applied', 'This import was already applied.'],
  ])(
    'a 422 import error (%s) shows the message with Upload again, which returns to step 1',
    async (_n, message) => {
      const user = userEvent.setup();
      setupAdmin('/admin/products/import', {
        [UP]: { status: 201, body: { data: preview() } },
        [APPLY]: {
          status: 422,
          body: { message, errors: { import: [message] } },
        },
      });
      await upload(user);
      await user.click(
        await screen.findByRole('button', { name: 'Apply import' }),
      );
      await user.click(
        within(screen.getByRole('dialog')).getByRole('button', {
          name: 'Confirm',
        }),
      );
      const alert = await screen.findByRole('alert');
      expect(alert).toHaveTextContent(message);
      expect(screen.queryByRole('dialog')).toBeNull();
      await user.click(
        within(alert).getByRole('button', { name: 'Upload again' }),
      );
      expect(
        await screen.findByRole('heading', { name: /step 1 of 3/i }),
      ).toBeInTheDocument();
      expect(screen.getByLabelText('CSV file')).toBeInTheDocument();
    },
  );

  it('a 422 skip_invalid keeps the preview and shows the message', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/import', {
      [UP]: { status: 201, body: { data: preview() } },
      [APPLY]: {
        status: 422,
        body: {
          message: 'x',
          errors: { skip_invalid: ['Skip the invalid rows to continue.'] },
        },
      },
    });
    await upload(user);
    await user.click(
      await screen.findByRole('button', { name: 'Apply import' }),
    );
    await user.click(
      within(screen.getByRole('dialog')).getByRole('button', {
        name: 'Confirm',
      }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Skip the invalid rows to continue.',
    );
    expect(screen.queryByRole('button', { name: 'Upload again' })).toBeNull();
    expect(
      screen.getByRole('button', { name: 'Apply import' }),
    ).toBeInTheDocument();
  });

  it('an apply 429 shows the wait time', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/import', {
      [UP]: { status: 201, body: { data: preview() } },
      [APPLY]: { status: 429, body: {}, headers: { 'Retry-After': '30' } },
    });
    await upload(user);
    await user.click(
      await screen.findByRole('button', { name: 'Apply import' }),
    );
    await user.click(
      within(screen.getByRole('dialog')).getByRole('button', {
        name: 'Confirm',
      }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'try again in 30 seconds',
    );
  });

  it('413 on upload shows the server size message', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/import', {
      [UP]: { status: 413, body: { message: 'Payload too large' } },
    });
    await upload(user);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The file is too large for the server.',
    );
    expect(
      screen.getByRole('heading', { name: /step 1 of 3/i }),
    ).toBeInTheDocument();
  });

  it('422 file errors from the server are shown', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/import', {
      [UP]: {
        status: 422,
        body: {
          message: 'x',
          errors: { file: ['The file is missing the "brand" column.'] },
        },
      },
    });
    await upload(user);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The file is missing the "brand" column.',
    );
  });

  it('429 on upload shows the wait time', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/import', {
      [UP]: { status: 429, body: {}, headers: { 'Retry-After': '45' } },
    });
    await upload(user);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'try again in 45 seconds',
    );
  });

  it('checks the file client side before sending anything', async () => {
    const user = userEvent.setup({ applyAccept: false });
    const { api } = setupAdmin('/admin/products/import');
    await screen.findByRole('heading', { name: /step 1 of 3/i });
    await user.click(screen.getByRole('button', { name: 'Upload and check' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Choose a CSV file.',
    );
    await user.upload(
      screen.getByLabelText('CSV file'),
      new File(['x'], 'data.txt', { type: 'text/plain' }),
    );
    await user.click(screen.getByRole('button', { name: 'Upload and check' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'must be a .csv file',
    );
    expect(api.to(UP)).toHaveLength(0);
  });

  it('Upload a different file returns to step 1 and the route is reachable from the products page link', async () => {
    const user = userEvent.setup();
    setupAdmin('/admin/products/import', {
      [UP]: { status: 201, body: { data: preview() } },
    });
    await upload(user);
    await user.click(
      await screen.findByRole('button', { name: 'Upload a different file' }),
    );
    expect(await screen.findByLabelText('CSV file')).toBeInTheDocument();
    expect(loc()).toHaveTextContent('/admin/products/import');
  });
});
