import {
  act,
  fireEvent,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Spec } from '../../../test/utils';
import { P1, P2, deferred } from '../../catalog/__tests__/fixtures';
import {
  CREATE,
  DETAIL,
  NUM,
  detail,
  pdf,
  quote,
  setupApp,
  unprocessable,
} from './fixtures';

afterEach(() => vi.useRealTimers());

const fakeTimers = () => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  return userEvent.setup({
    advanceTimers: vi.advanceTimersByTime.bind(vi),
    applyAccept: false,
  });
};
const created = { status: 201, body: { data: quote() } };
const base = { [CREATE]: created, [DETAIL]: detail(quote()) };
const searchUrls = (api: { calls: { url: string }[] }) =>
  api.calls
    .map((c) => c.url)
    .filter((u) => u.startsWith('/api/v1/products?q='));
const submit = (u: ReturnType<typeof userEvent.setup>) =>
  u.click(screen.getByRole('button', { name: 'Submit quote request' }));
const groupOf = (n: number) => screen.getByRole('group', { name: `Item ${n}` });

async function open(path = '/quotes/new', routes = base) {
  const user = userEvent.setup({ applyAccept: false });
  const app = setupApp(path, routes);
  await screen.findByRole('heading', { name: 'Request a quote' });
  return { user, ...app };
}

/** Adds a custom line and fills it. */
async function custom(
  user: ReturnType<typeof userEvent.setup>,
  description: string,
  qty?: string,
) {
  await user.click(screen.getByRole('button', { name: 'Add custom item' }));
  const group = within(
    screen.getAllByRole('group', { name: /^Item \d+$/ }).at(-1)!,
  );
  if (description)
    await user.type(group.getByLabelText('Description'), description);
  if (qty !== undefined) {
    const q = group.getByLabelText('Quantity');
    await user.clear(q);
    if (qty) await user.type(q, qty);
  }
}

describe('quote form: lines', () => {
  it('starts with no lines and blocks submit with a focused error summary', async () => {
    const { user, api } = await open();
    expect(screen.queryByRole('group', { name: /^Item/ })).toBeNull();
    await submit(user);
    expect(
      (await screen.findAllByText('Add at least one item.')).length,
    ).toBeGreaterThan(0);
    expect(
      screen.getByRole('group', { name: 'Please fix the following' }),
    ).toHaveFocus();
    expect(api.to(CREATE)).toHaveLength(0);
  });

  it('adds a catalog line from the picker: one debounced search after 300 ms, already added is disabled', async () => {
    const user = fakeTimers();
    const { api } = setupApp('/quotes/new', base);
    await user.click(
      await screen.findByRole('button', { name: 'Add catalog item' }),
    );
    const box = screen.getByLabelText('Search the catalog');
    expect(box).toHaveFocus();
    await user.type(box, '62');
    expect(searchUrls(api)).toHaveLength(0);
    await act(() => vi.advanceTimersByTimeAsync(300));
    await waitFor(() =>
      expect(searchUrls(api)).toEqual(['/api/v1/products?q=62&per_page=10']),
    );
    await user.click(
      await screen.findByRole('button', { name: 'Add 6205-2RS' }),
    );
    expect(within(groupOf(1)).getByText('6205-2RS')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add 6205-2RS' })).toBeDisabled();
    expect(screen.getByText('Already in this quote')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add NU206' })).toBeEnabled();
  });

  it('adds custom lines and removing one moves focus to the next line, then to the add button', async () => {
    const { user } = await open();
    await custom(user, 'First');
    await custom(user, 'Second');
    expect(within(groupOf(2)).getByLabelText('Description')).toHaveFocus();
    await user.click(screen.getByRole('button', { name: 'Remove item 1' }));
    expect(screen.queryByRole('group', { name: 'Item 2' })).toBeNull();
    expect(within(groupOf(1)).getByLabelText('Description')).toHaveValue(
      'Second',
    );
    expect(within(groupOf(1)).getByLabelText('Description')).toHaveFocus();
    await user.click(screen.getByRole('button', { name: 'Remove item 1' }));
    expect(
      screen.getByRole('button', { name: 'Add custom item' }),
    ).toHaveFocus();
  });

  it.each(['0', '10000', '1.5', '', '-2', 'abc'])(
    'quantity %j is rejected inline with aria wiring and nothing is sent',
    async (qty) => {
      const { user, api } = await open();
      await custom(user, 'Bearing', qty);
      await submit(user);
      const input = within(groupOf(1)).getByLabelText('Quantity');
      const alert = await within(groupOf(1)).findByRole('alert');
      expect(alert).toHaveTextContent('Enter a whole number from 1 to 9999.');
      expect(input).toHaveAttribute('aria-invalid', 'true');
      expect(input).toHaveAttribute('aria-describedby', alert.id);
      expect(api.to(CREATE)).toHaveLength(0);
    },
  );

  it.each([
    ['', 'Enter a description.'],
    ['x'.repeat(256), 'Use at most 255 characters.'],
    ['ab​c', 'Remove special characters.'],
  ])('custom description %j is rejected', async (text, message) => {
    const { user, api } = await open();
    await user.click(screen.getByRole('button', { name: 'Add custom item' }));
    const input = within(groupOf(1)).getByLabelText('Description');
    if (text) fireEvent.change(input, { target: { value: text } });
    await submit(user);
    const alert = await within(groupOf(1)).findByRole('alert');
    expect(alert).toHaveTextContent(message);
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveAttribute('aria-describedby', alert.id);
    expect(api.to(CREATE)).toHaveLength(0);
  });

  it('accepts exactly 255 characters', async () => {
    const { user, api } = await open();
    await user.click(screen.getByRole('button', { name: 'Add custom item' }));
    fireEvent.change(within(groupOf(1)).getByLabelText('Description'), {
      target: { value: 'x'.repeat(255) },
    });
    await submit(user);
    await waitFor(() => expect(api.to(CREATE)).toHaveLength(1));
  });

  it('blocks a 51st line', async () => {
    await open();
    const add = screen.getByRole('button', { name: 'Add custom item' });
    for (let i = 0; i < 50; i += 1) fireEvent.click(add);
    expect(screen.getAllByRole('group', { name: /^Item \d+$/ })).toHaveLength(
      50,
    );
    expect(add).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Add catalog item' }),
    ).toBeDisabled();
    expect(
      screen.getByText('You can request at most 50 items.'),
    ).toBeInTheDocument();
  });
});

describe('quote form: PO reference and note', () => {
  it.each([
    ['-lead', 'Must start with a letter or digit.'],
    ['=SUM(A1)', 'Must start with a letter or digit.'],
    ['AB%', /Only letters, digits, spaces/],
    ['x'.repeat(65), 'Use at most 64 characters.'],
    ['AB​C', /Only letters, digits, spaces/],
  ])(
    'PO reference %j is rejected like checkout and nothing is sent',
    async (bad, msg) => {
      const { user, api } = await open();
      await custom(user, 'Bearing');
      const po = screen.getByLabelText('PO reference (optional)');
      fireEvent.change(po, { target: { value: bad } });
      await submit(user);
      const alert = await screen.findByText(msg, {
        selector: 'p[role="alert"]',
      });
      expect(po).toHaveAttribute('aria-invalid', 'true');
      expect(po).toHaveAttribute('aria-describedby', alert.id);
      expect(api.to(CREATE)).toHaveLength(0);
    },
  );

  it('rejects a note over 2000 characters and shows the counter', async () => {
    const { user, api } = await open();
    await custom(user, 'Bearing');
    fireEvent.change(screen.getByLabelText('Note for our team (optional)'), {
      target: { value: 'n'.repeat(2001) },
    });
    expect(screen.getByText('2001 / 2000')).toBeInTheDocument();
    await submit(user);
    expect(
      await screen.findByText('Use at most 2000 characters.', {
        selector: 'p[role="alert"]',
      }),
    ).toBeInTheDocument();
    expect(api.to(CREATE)).toHaveLength(0);
  });
});

describe('quote form: submit', () => {
  it('sends exactly po_reference, customer_note and lines (no prices or names), then opens the detail', async () => {
    const user = userEvent.setup({ applyAccept: false });
    const { api } = setupApp('/quotes/new?product=17', {
      ...base,
      'GET /api/v1/products/17': {
        body: { data: { ...P1, description: null } },
      },
    });
    await screen.findByRole('heading', { name: 'Request a quote' });
    expect(
      await within(
        await screen.findByRole('group', { name: 'Item 1' }),
      ).findByText('6205-2RS'),
    ).toBeInTheDocument();
    const qty = within(groupOf(1)).getByLabelText('Quantity');
    expect(qty).toHaveValue('1');
    await user.clear(qty);
    await user.type(qty, '10');
    await custom(user, '  Taper roller 32208  ', '4');
    await user.type(
      screen.getByLabelText('PO reference (optional)'),
      '  PO-4711/A  ',
    );
    await user.type(
      screen.getByLabelText('Note for our team (optional)'),
      ' Need CE ',
    );
    await submit(user);

    expect(
      await screen.findByText(/Quote request QUO-K7Q2M-X9PA3 was submitted/),
    ).toBeInTheDocument();
    expect(screen.getByTestId('loc')).toHaveTextContent(`/quotes/${NUM}`);
    expect(api.to(CREATE)[0].body).toStrictEqual({
      po_reference: 'PO-4711/A',
      customer_note: 'Need CE',
      lines: [
        { product_id: 17, quantity: 10 },
        { description: 'Taper roller 32208', quantity: 4 },
      ],
    });
  });

  it('omits an empty PO reference and note', async () => {
    const { user, api } = await open();
    await custom(user, 'Bearing');
    await submit(user);
    await waitFor(() => expect(api.to(CREATE)).toHaveLength(1));
    expect(api.to(CREATE)[0].body).toStrictEqual({
      lines: [{ description: 'Bearing', quantity: 1 }],
    });
  });

  it('a server 422 on lines.1.description shows on line 2 and the summary takes focus', async () => {
    const { user } = await open('/quotes/new', {
      ...base,
      [CREATE]: unprocessable({
        'lines.1.description': ['The description is not allowed.'],
      }),
    } as unknown as typeof base);
    await custom(user, 'First');
    await custom(user, 'Second');
    await submit(user);
    expect(
      await within(groupOf(2)).findByText('The description is not allowed.'),
    ).toBeInTheDocument();
    expect(within(groupOf(1)).queryByRole('alert')).toBeNull();
    const summary = screen.getByRole('group', {
      name: 'Please fix the following',
    });
    expect(summary).toHaveTextContent(
      'Item 2: The description is not allowed.',
    );
    expect(summary).toHaveFocus();
  });

  it('a 429 shows the seconds to wait', async () => {
    const { user } = await open('/quotes/new', {
      ...base,
      [CREATE]: {
        status: 429,
        body: { message: 'Too Many Attempts.' },
        headers: { 'Retry-After': '30' },
      },
    } as unknown as typeof base);
    await custom(user, 'Bearing');
    await submit(user);
    expect(
      await screen.findByText(/try again in 30 seconds/),
    ).toBeInTheDocument();
  });

  it('a double click sends one request and the button is disabled while pending', async () => {
    const d = deferred<Spec>();
    const { user, api } = await open('/quotes/new', {
      ...base,
      [CREATE]: () => d.promise,
    } as unknown as typeof base);
    await custom(user, 'Bearing');
    await user.dblClick(
      screen.getByRole('button', { name: 'Submit quote request' }),
    );
    expect(api.to(CREATE)).toHaveLength(1);
    expect(
      await screen.findByRole('button', { name: 'Submitting...' }),
    ).toBeDisabled();
    d.resolve(created);
    await screen.findByText(/was submitted/);
    expect(api.to(CREATE)).toHaveLength(1);
  });
});

describe('quote form: attachments', () => {
  it('rejects wrong extension, 10 MB + 1 byte, empty files and the 11th file on the client', async () => {
    const { user, api } = await open();
    const input = screen.getByLabelText('Add files');
    const valid = Array.from({ length: 11 }, (_, i) => pdf(`ok-${i}.pdf`));
    await user.upload(input, [
      new File(['MZ'], 'evil.exe'),
      pdf('big.pdf', 10 * 1024 * 1024 + 1),
      pdf('empty.pdf', 0),
      ...valid,
    ]);
    const list = await screen.findByRole('list', { name: 'Files to upload' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(10);
    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent(
      'evil.exe: Only PDF, PNG, JPG, TXT and CSV files are allowed.',
    );
    expect(alert).toHaveTextContent('big.pdf: The file is larger than 10 MB.');
    expect(alert).toHaveTextContent('empty.pdf: The file is empty.');
    expect(alert).toHaveTextContent('ok-10.pdf: At most 10 files per quote.');
    expect(api.calls.some((c) => c.url.includes('attachments'))).toBe(false);
  });

  it('uploads valid files one at a time after the create: FormData field file, XSRF header, no manual Content-Type', async () => {
    const up = `POST /api/v1/quotes/${NUM}/attachments`;
    let running = 0;
    let overlap = false;
    const upload = async (): Promise<Spec> => {
      running += 1;
      overlap ||= running > 1;
      await new Promise((r) => setTimeout(r, 10));
      running -= 1;
      return { status: 201, body: { data: { id: 'a' } } };
    };
    const { user, api } = await open('/quotes/new', {
      ...base,
      [up]: upload,
    } as unknown as typeof base);
    await custom(user, 'Bearing');
    await user.upload(screen.getByLabelText('Add files'), [
      pdf('a.pdf'),
      pdf('b.pdf'),
    ]);
    await submit(user);
    await screen.findByText(/was submitted/);
    const calls = api.calls
      .filter((c) => c.method === 'POST')
      .map((c) => c.url);
    expect(calls).toEqual([
      '/api/v1/quotes',
      `/api/v1/quotes/${NUM}/attachments`,
      `/api/v1/quotes/${NUM}/attachments`,
    ]);
    const uploads = api.to(up);
    expect(uploads.map((c) => (c.form?.get('file') as File).name)).toEqual([
      'a.pdf',
      'b.pdf',
    ]);
    for (const c of uploads) {
      expect(c.headers.get('Content-Type')).toBeNull();
      expect(c.headers.get('X-XSRF-TOKEN')).toBe('tok=123');
    }
    expect(overlap).toBe(false);
  });

  it.each([
    [
      '422',
      unprocessable({ file: ['The file must be a PDF.'] }),
      'The file must be a PDF.',
    ],
    [
      '413',
      { status: 413, body: { message: 'The upload is too large.' } },
      'The file is too large for the server.',
    ],
    [
      '429',
      { status: 429, body: { message: 'x' }, headers: { 'Retry-After': '5' } },
      /try again in 5 seconds/,
    ],
  ])(
    'a failing upload (%s) leaves the user on the detail page with a notice, and the quote is created once',
    async (_s, response, text) => {
      const up = `POST /api/v1/quotes/${NUM}/attachments`;
      const { user, api } = await open('/quotes/new', {
        ...base,
        [up]: response,
      } as unknown as typeof base);
      await custom(user, 'Bearing');
      await user.upload(screen.getByLabelText('Add files'), [
        pdf('drawing.pdf'),
      ]);
      await submit(user);
      const notice = await screen.findByText(
        /Some files could not be uploaded/,
      );
      const box = notice.closest('[role="alert"]') as HTMLElement;
      expect(box).toHaveTextContent(/drawing\.pdf:/);
      expect(within(box).getByText(text, { exact: false })).toBeInTheDocument();
      expect(screen.getByTestId('loc')).toHaveTextContent(`/quotes/${NUM}`);
      expect(screen.getByLabelText('Add attachments')).toBeInTheDocument(); // retry here
      expect(api.to(CREATE)).toHaveLength(1);
    },
  );
});

describe('quote form: prefill from the catalog', () => {
  it('creates a quantity-1 line per valid product id, drops bad and 404 ids with a notice, ignores URL prices', async () => {
    const { api } = setupApp(
      '/quotes/new?product=17&product=18&product=22&product=abc&price=1',
      {
        ...base,
        'GET /api/v1/products/17': {
          body: { data: { ...P1, description: null } },
        },
        'GET /api/v1/products/18': {
          body: { data: { ...P2, description: null } },
        },
        'GET /api/v1/products/22': {
          status: 404,
          body: { message: 'Not found.' },
        },
      },
    );
    await within(
      await screen.findByRole('group', { name: 'Item 1' }),
    ).findByText('6205-2RS');
    expect(within(groupOf(2)).getByText('NU206')).toBeInTheDocument();
    expect(within(groupOf(1)).getByLabelText('Quantity')).toHaveValue('1');
    expect(within(groupOf(2)).getByLabelText('Quantity')).toHaveValue('1');
    expect(screen.getByText('2 items could not be added.')).toBeInTheDocument();
    expect(api.calls.some((c) => c.url.endsWith('/abc'))).toBe(false);
  });
});
