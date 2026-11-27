import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { admin } from '../../../test/utils';
import {
  DASH_URL,
  PRODUCTS,
  USERS,
  dashOk,
  paged,
  product,
  setupAdmin,
  user,
} from './fixtures';

describe('back office accessibility', () => {
  it('moves focus to the page heading on arrival', async () => {
    setupAdmin('/admin', { [DASH_URL]: dashOk });
    const h1 = await screen.findByRole('heading', {
      name: 'Dashboard',
      level: 1,
    });
    await waitFor(() => expect(h1).toHaveFocus());
  });

  it('sort headers work with the keyboard and the focus stays on the sort button', async () => {
    const u = userEvent.setup();
    setupAdmin('/admin/products', { [PRODUCTS]: paged([product()]) });
    await screen.findByRole('table', { name: 'Products' });
    const btn = within(
      screen.getByRole('columnheader', { name: /^Name/ }),
    ).getByRole('button');
    btn.focus();
    await u.keyboard('{Enter}');
    await waitFor(() =>
      expect(
        screen.getByRole('columnheader', { name: /^Name/ }),
      ).toHaveAttribute('aria-sort', 'ascending'),
    );
    expect(
      within(screen.getByRole('columnheader', { name: /^Name/ })).getByRole(
        'button',
      ),
    ).toHaveFocus();
  });

  it('after paging, focus lands on the table instead of a button that moved or disabled', async () => {
    const u = userEvent.setup();
    setupAdmin('/admin/products', {
      [PRODUCTS]: (req) => {
        const page = Number(
          new URL(req.url, 'http://x').searchParams.get('page') ?? 1,
        );
        return paged([product()], {
          current_page: page,
          last_page: 2,
          total: 40,
        });
      },
    });
    await screen.findByRole('table', { name: 'Products' });
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() =>
      expect(screen.getByRole('table', { name: 'Products' })).toHaveFocus(),
    );
  });

  it('the invite dialog traps Tab and Shift+Tab, Escape closes it and focus returns to the opener', async () => {
    const u = userEvent.setup();
    setupAdmin('/admin/users', { [USERS]: paged([user()]) }, admin);
    await screen.findByRole('table', { name: 'Users' });
    const opener = screen.getByRole('button', { name: 'Invite user' });
    await u.click(opener);
    const dialog = screen.getByRole('dialog', { name: 'Invite user' });
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    for (let i = 0; i < 12; i++) {
      await u.tab();
      expect(dialog).toContainElement(document.activeElement as HTMLElement);
    }
    for (let i = 0; i < 12; i++) {
      await u.tab({ shift: true });
      expect(dialog).toContainElement(document.activeElement as HTMLElement);
    }
    await u.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(opener).toHaveFocus();
  });

  it('every product form field has a visible label and errors are linked with aria-describedby', async () => {
    const u = userEvent.setup();
    setupAdmin('/admin/products/new');
    await screen.findByRole('heading', { name: 'New product' });
    const form = screen.getByRole('form', { name: 'Product' });
    for (const el of within(form).getAllByRole('textbox')) {
      expect(el).toHaveAccessibleName();
    }
    for (const el of within(form).getAllByRole('combobox')) {
      expect(el).toHaveAccessibleName();
    }
    expect(
      within(form)
        .getAllByRole('group')
        .map((g) => g.tagName),
    ).toEqual(['FIELDSET', 'FIELDSET', 'FIELDSET']);
    await u.click(screen.getByRole('button', { name: 'Create product' }));
    const part = await screen.findByLabelText('Part number');
    await waitFor(() => expect(part).toHaveAttribute('aria-invalid', 'true'));
    expect(part).toHaveAccessibleDescription('Enter a part number.');
  });

  it('user action results are announced in a polite live region inside the row', async () => {
    const u = userEvent.setup();
    setupAdmin(
      '/admin/users',
      {
        [USERS]: paged([user()]),
        'POST /api/v1/admin/users/7/password-reset': {
          body: { message: 'A password reset email has been queued.' },
        },
      },
      admin,
    );
    await screen.findByRole('table', { name: 'Users' });
    await u.click(
      screen.getByRole('button', { name: /password reset to Dave/i }),
    );
    const msg = await screen.findByText(
      'A password reset email has been queued.',
    );
    expect(msg).toHaveAttribute('aria-live', 'polite');
    expect(msg).toHaveAttribute('role', 'status');
  });
});
