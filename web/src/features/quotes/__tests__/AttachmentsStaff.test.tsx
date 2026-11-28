import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { admin, meOk, staff } from '../../../test/utils';
import type { QuoteAttachment, QuoteDetail } from '../api';
import {
  DETAIL,
  ME,
  NUM,
  detail,
  forStaff,
  quote,
  quoted,
  setupApp,
} from './fixtures';

const PATH = `/quotes/${NUM}`;
const PDF: QuoteAttachment = {
  id: '3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90',
  name: 'drawing-rev2.pdf',
  mime: 'application/pdf',
  size_bytes: 482113,
  uploaded_by: { name: 'Alice Customer', role: 'customer' },
  created_at: '2026-11-25T10:16:00Z',
};
const CSV: QuoteAttachment = {
  ...PDF,
  id: '9a1b2c3d-0000-4000-8000-0123456789ab',
  name: 'parts-list.CSV',
  mime: 'text/csv',
  size_bytes: 1200,
};
const WARNING =
  'CSV files can contain spreadsheet formulas. Open it in a text editor, or disable formulas and links in your spreadsheet.';
const DEL = (id: string) => `DELETE /api/v1/quotes/${NUM}/attachments/${id}`;
const heading = () => screen.findByRole('heading', { name: /Quote QUO-/ });
const open = (o: Partial<QuoteDetail> = {}) =>
  forStaff(quote({ status: 'in_review', attachments: [PDF, CSV] }), {
    allowed_actions: ['attach', 'note'],
    ...o,
  });
const closed = () =>
  forStaff(quoted({ attachments: [PDF, CSV] }), { allowed_actions: ['note'] });

describe('staff CSV warning', () => {
  it.each([
    ['staff', staff],
    ['admin', admin],
  ])('%s sees the warning next to a .csv attachment only', async (_n, user) => {
    setupApp(PATH, { [ME]: meOk(user), [DETAIL]: detail(open()) }, user);
    await heading();
    const warnings = screen.getAllByText(WARNING);
    expect(warnings).toHaveLength(1); // the PDF has none
    const link = screen.getByRole('link', { name: 'parts-list.CSV' });
    expect(link).toHaveAccessibleDescription(WARNING);
    expect(
      screen.getByRole('link', { name: 'drawing-rev2.pdf' }),
    ).not.toHaveAccessibleDescription(WARNING);
  });

  it('customers never see it', async () => {
    setupApp(PATH, { [DETAIL]: detail(quote({ attachments: [PDF, CSV] })) });
    await heading();
    expect(
      screen.getByRole('link', { name: 'parts-list.CSV' }),
    ).toBeInTheDocument();
    expect(screen.queryByText(WARNING)).toBeNull();
  });
});

describe('staff purge on a closed quote', () => {
  it('staff get a Purge button per file with a confirm dialog, then DELETE', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(
      PATH,
      {
        [ME]: meOk(staff),
        [DETAIL]: detail(closed()),
        [DEL(PDF.id)]: { status: 204 },
      },
      staff,
    );
    await heading();
    expect(screen.queryByLabelText('Add attachments')).toBeNull();
    await user.click(
      screen.getByRole('button', { name: 'Purge drawing-rev2.pdf' }),
    );
    const dlg = screen.getByRole('dialog', { name: 'Purge this attachment?' });
    expect(dlg).toHaveTextContent(/permanently deleted/);
    expect(dlg).toHaveTextContent(/audit log/);
    expect(api.to(DEL(PDF.id))).toHaveLength(0);
    await user.click(within(dlg).getByRole('button', { name: 'Yes, purge' }));
    await waitFor(() => expect(api.to(DEL(PDF.id))).toHaveLength(1));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('cancel keeps the file and sends nothing', async () => {
    const user = userEvent.setup();
    const { api } = setupApp(
      PATH,
      { [ME]: meOk(admin), [DETAIL]: detail(closed()) },
      admin,
    );
    await heading();
    await user.click(
      screen.getByRole('button', { name: 'Purge drawing-rev2.pdf' }),
    );
    await user.click(screen.getByRole('button', { name: 'Keep file' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(api.calls.filter((c) => c.method === 'DELETE')).toHaveLength(0);
  });

  it('a 404 is explained in the dialog (the file is already gone)', async () => {
    const user = userEvent.setup();
    setupApp(
      PATH,
      {
        [ME]: meOk(staff),
        [DETAIL]: detail(closed()),
        [DEL(PDF.id)]: { status: 404, body: { message: 'Not found.' } },
      },
      staff,
    );
    await heading();
    await user.click(
      screen.getByRole('button', { name: 'Purge drawing-rev2.pdf' }),
    );
    await user.click(screen.getByRole('button', { name: 'Yes, purge' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
      /no longer exists/,
    );
  });

  it('customers get no purge button on a closed quote', async () => {
    setupApp(PATH, { [DETAIL]: detail(quoted({ attachments: [PDF] })) });
    await heading();
    expect(screen.queryByRole('button', { name: /Purge|Delete/ })).toBeNull();
  });

  it('on an open quote staff keep the normal Delete button, not Purge', async () => {
    setupApp(PATH, { [ME]: meOk(staff), [DETAIL]: detail(open()) }, staff);
    await heading();
    expect(
      screen.getByRole('button', { name: 'Delete drawing-rev2.pdf' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Purge/ })).toBeNull();
  });
});
