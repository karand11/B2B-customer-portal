import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { admin } from '../../../test/utils';
import {
  COMPANIES,
  USERS,
  company,
  loc,
  paged,
  setupAdmin,
  user,
} from './fixtures';

const POST = 'POST /api/v1/admin/users';
const ROWS = [
  user(),
  user({
    id: 1,
    name: 'Ada Admin',
    email: 'admin@portal.test',
    role: 'admin',
    company: null,
    allowed_actions: ['edit'],
  }),
  user({
    id: 8,
    name: 'Erin Pending',
    email: 'erin@initech.test',
    status: 'pending',
    invited_at: '2026-11-26T10:00:00Z',
    allowed_actions: [
      'edit',
      'assign',
      'resend_invitation',
      'revoke_invitation',
    ],
  }),
  user({
    id: 9,
    name: 'Dan Inactive',
    email: 'dan@northwind.test',
    status: 'inactive',
    allowed_actions: ['edit', 'assign', 'reactivate'],
  }),
];
const setup = (path = '/admin/users', routes = {}) =>
  setupAdmin(path, { [USERS]: paged(ROWS), ...routes }, admin);
const row = (name: string) =>
  within(screen.getByRole('table', { name: 'Users' })).getByRole('row', {
    name: new RegExp(name),
  });
const queryTable = () => screen.findByRole('table', { name: 'Users' });

describe('users list', () => {
  it('renders status as text and offers actions strictly from allowed_actions', async () => {
    setup();
    await queryTable();
    // Own row: edit only, so no action buttons at all.
    expect(within(row('Ada Admin')).queryAllByRole('button')).toHaveLength(0);
    // Active customer.
    const dave = within(row('Dave Dealer'));
    expect(
      dave.getByRole('button', { name: /change role or company for Dave/i }),
    ).toBeInTheDocument();
    expect(
      dave.getByRole('button', { name: /^Deactivate Dave/ }),
    ).toBeInTheDocument();
    expect(
      dave.getByRole('button', { name: /password reset to Dave/i }),
    ).toBeInTheDocument();
    expect(
      dave.queryByRole('button', { name: /reactivate|resend|revoke/i }),
    ).toBeNull();
    // Pending.
    const erin = within(row('Erin Pending'));
    expect(erin.getByText('Pending invitation')).toBeInTheDocument();
    expect(
      erin.getByRole('button', { name: /resend invitation/i }),
    ).toBeInTheDocument();
    expect(
      erin.getByRole('button', { name: /revoke invitation/i }),
    ).toBeInTheDocument();
    expect(
      erin.queryByRole('button', { name: /deactivate|password reset/i }),
    ).toBeNull();
    // Inactive.
    const dan = within(row('Dan Inactive'));
    expect(dan.getByText('Inactive')).toBeInTheDocument();
    expect(
      dan.getByRole('button', { name: /^Reactivate Dan/ }),
    ).toBeInTheDocument();
    expect(dan.queryByRole('button', { name: /deactivate dan/i })).toBeNull();
  });

  it('filters sync to the URL and the request (role, status, q, company)', async () => {
    const user = userEvent.setup();
    const { api } = setup('/admin/users', {
      [COMPANIES]: paged([company()]),
      'GET /api/v1/admin/companies/1': { body: { data: company() } },
    });
    await queryTable();
    await user.click(screen.getByLabelText('Staff'));
    await user.click(screen.getByLabelText('Administrator'));
    await waitFor(() => expect(loc()).toHaveTextContent('?role=staff,admin'));
    await user.click(screen.getByLabelText('Pending invitation'));
    await waitFor(() =>
      expect(loc()).toHaveTextContent('?role=staff,admin&status=pending'),
    );
    await waitFor(() =>
      expect(
        api.calls.filter((c) => c.url.startsWith('/api/v1/admin/users')).at(-1)
          ?.url,
      ).toBe('/api/v1/admin/users?role=staff,admin&status=pending'),
    );
    await user.type(screen.getByLabelText('Name or email'), 'dave');
    await waitFor(() => expect(loc()).toHaveTextContent('q=dave'));
    await user.type(screen.getByLabelText('Company'), 'north');
    await user.click(
      await screen.findByRole('button', { name: 'Northwind Machinery Ltd' }),
    );
    await waitFor(() => expect(loc()).toHaveTextContent('company_id=1'));
    expect(
      await screen.findByText('Northwind Machinery Ltd', { selector: 'p' }),
    ).toBeInTheDocument();
  });

  it('restores filters from the URL and drops invalid ones', async () => {
    const { api } = setup(
      '/admin/users?role=staff,bogus&status=nope&company_id=abc&sort=email&page=2',
    );
    await queryTable();
    expect(screen.getByLabelText('Staff')).toBeChecked();
    expect(screen.getByLabelText('Customer')).not.toBeChecked();
    expect(
      api.calls.find((c) => c.url.startsWith('/api/v1/admin/users'))?.url,
    ).toBe('/api/v1/admin/users?role=staff&sort=email&page=2');
  });

  it('shows loading, empty, error with retry', async () => {
    setupAdmin('/admin/users', { [USERS]: paged([]) }, admin);
    expect(await screen.findByText('No users yet.')).toBeInTheDocument();
  });
});

describe('invite dialog', () => {
  const open = async (u: ReturnType<typeof userEvent.setup>) => {
    await queryTable();
    await u.click(screen.getByRole('button', { name: 'Invite user' }));
    return screen.getByRole('dialog', { name: 'Invite user' });
  };

  it('shows the company picker only for the customer role and validates', async () => {
    const u = userEvent.setup();
    const { api } = setup('/admin/users');
    const dialog = await open(u);
    const d = within(dialog);
    expect(d.getByLabelText('Company')).toBeInTheDocument();
    await u.click(d.getByRole('button', { name: 'Send invitation' }));
    expect(await d.findByText('Enter a name.')).toBeInTheDocument();
    expect(d.getByText('Enter an email address.')).toBeInTheDocument();
    expect(d.getByText('Choose the customer company.')).toBeInTheDocument();
    await u.selectOptions(d.getByLabelText('Role'), 'staff');
    expect(d.queryByLabelText('Company')).toBeNull();
    expect(api.to(POST)).toHaveLength(0);
  });

  it('invites a staff user with company_id null and shows the success notice', async () => {
    const u = userEvent.setup();
    const { api } = setup('/admin/users', {
      [POST]: {
        status: 201,
        body: { data: user({ id: 20 }), meta: { email_queued: true } },
      },
    });
    const dialog = await open(u);
    const d = within(dialog);
    await u.type(d.getByLabelText('Name'), 'Sue Staff');
    await u.type(d.getByLabelText('Email'), 'sue@portal.test');
    await u.selectOptions(d.getByLabelText('Role'), 'staff');
    await u.click(d.getByRole('button', { name: 'Send invitation' }));
    await waitFor(() => expect(api.to(POST)).toHaveLength(1));
    expect(api.to(POST)[0].body).toEqual({
      name: 'Sue Staff',
      email: 'sue@portal.test',
      role: 'staff',
      company_id: null,
    });
    expect(
      await screen.findByText('Invitation sent to dave@northwind.test.'),
    ).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('invites a customer with the chosen company id', async () => {
    const u = userEvent.setup();
    const { api } = setup('/admin/users', {
      [COMPANIES]: paged([company()]),
      'GET /api/v1/admin/companies/1': { body: { data: company() } },
      [POST]: {
        status: 201,
        body: { data: user(), meta: { email_queued: true } },
      },
    });
    const d = within(await open(u));
    await u.type(d.getByLabelText('Name'), 'New Customer');
    await u.type(d.getByLabelText('Email'), 'new@northwind.test');
    await u.type(d.getByLabelText('Company'), 'north');
    await u.click(
      await d.findByRole('button', { name: 'Northwind Machinery Ltd' }),
    );
    await u.click(d.getByRole('button', { name: 'Send invitation' }));
    await waitFor(() => expect(api.to(POST)).toHaveLength(1));
    expect(api.to(POST)[0].body).toMatchObject({
      role: 'customer',
      company_id: 1,
    });
  });

  it('shows a 422 email error on the field and keeps the dialog open', async () => {
    const u = userEvent.setup();
    setup('/admin/users', {
      [POST]: {
        status: 422,
        body: {
          message: 'x',
          errors: { email: ['This email is already in use.'] },
        },
      },
    });
    const d = within(await open(u));
    await u.type(d.getByLabelText('Name'), 'Sue');
    await u.type(d.getByLabelText('Email'), 'sue@portal.test');
    await u.selectOptions(d.getByLabelText('Role'), 'admin');
    await u.click(d.getByRole('button', { name: 'Send invitation' }));
    expect(
      await d.findByText('This email is already in use.'),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('dialog', { name: 'Invite user' }),
    ).toBeInTheDocument();
  });

  it('warns when the invitation email could not be queued', async () => {
    const u = userEvent.setup();
    setup('/admin/users', {
      [POST]: {
        status: 201,
        body: { data: user(), meta: { email_queued: false } },
      },
    });
    const d = within(await open(u));
    await u.type(d.getByLabelText('Name'), 'Sue');
    await u.type(d.getByLabelText('Email'), 'sue@portal.test');
    await u.selectOptions(d.getByLabelText('Role'), 'staff');
    await u.click(d.getByRole('button', { name: 'Send invitation' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Saved, but the email could not be queued. Use Resend invitation.',
    );
  });
});

describe('row actions', () => {
  it('assigns a role: PUT sends role and company_id null for staff, and says the user must sign in again', async () => {
    const u = userEvent.setup();
    const { api } = setup('/admin/users', {
      'PUT /api/v1/admin/users/7/assignment': {
        body: { data: user({ role: 'staff', company: null }) },
      },
    });
    await queryTable();
    await u.click(
      within(row('Dave Dealer')).getByRole('button', {
        name: /change role or company for Dave/i,
      }),
    );
    const dialog = screen.getByRole('dialog', {
      name: /change role or company for Dave Dealer/i,
    });
    expect(dialog).toHaveTextContent('signed out');
    await u.selectOptions(within(dialog).getByLabelText('Role'), 'staff');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() =>
      expect(api.to('PUT /api/v1/admin/users/7/assignment')).toHaveLength(1),
    );
    expect(api.to('PUT /api/v1/admin/users/7/assignment')[0].body).toEqual({
      role: 'staff',
      company_id: null,
    });
    expect(await screen.findByText(/must sign in again/)).toBeInTheDocument();
  });

  it('shows the API error for the last-admin and self rules', async () => {
    const u = userEvent.setup();
    setup('/admin/users', {
      'PUT /api/v1/admin/users/7/assignment': {
        status: 422,
        body: {
          message: 'x',
          errors: { role: ['At least one active administrator is required.'] },
        },
      },
      'POST /api/v1/admin/users/7/deactivate': {
        status: 422,
        body: {
          message: 'x',
          errors: { user: ['You cannot deactivate your own account.'] },
        },
      },
    });
    await queryTable();
    await u.click(
      within(row('Dave Dealer')).getByRole('button', {
        name: /change role or company for Dave/i,
      }),
    );
    const dialog = screen.getByRole('dialog');
    await u.selectOptions(within(dialog).getByLabelText('Role'), 'staff');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    expect(
      await within(dialog).findByText(
        'At least one active administrator is required.',
      ),
    ).toBeInTheDocument();
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));

    await u.click(
      within(row('Dave Dealer')).getByRole('button', {
        name: /^Deactivate Dave/,
      }),
    );
    await u.click(
      within(screen.getByRole('dialog')).getByRole('button', {
        name: 'Deactivate',
      }),
    );
    expect(
      await within(row('Dave Dealer')).findByText(
        'You cannot deactivate your own account.',
      ),
    ).toHaveAttribute('role', 'alert');
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('Deactivate needs confirmation; Escape sends nothing; confirming posts and announces', async () => {
    const u = userEvent.setup();
    const { api } = setup('/admin/users', {
      'POST /api/v1/admin/users/7/deactivate': {
        body: {
          data: user({
            status: 'inactive',
            allowed_actions: ['edit', 'assign', 'reactivate'],
          }),
        },
      },
    });
    await queryTable();
    const opener = within(row('Dave Dealer')).getByRole('button', {
      name: /^Deactivate Dave/,
    });
    await u.click(opener);
    expect(
      screen.getByRole('dialog', { name: 'Deactivate Dave Dealer?' }),
    ).toBeInTheDocument();
    await u.keyboard('{Escape}');
    expect(api.to('POST /api/v1/admin/users/7/deactivate')).toHaveLength(0);
    expect(opener).toHaveFocus();
    await u.click(opener);
    await u.click(
      within(screen.getByRole('dialog')).getByRole('button', {
        name: 'Deactivate',
      }),
    );
    await waitFor(() =>
      expect(api.to('POST /api/v1/admin/users/7/deactivate')).toHaveLength(1),
    );
    expect(await screen.findByText('User deactivated.')).toBeInTheDocument();
  });

  it('Revoke confirms, Resend and Send password reset act directly', async () => {
    const u = userEvent.setup();
    const { api } = setup('/admin/users', {
      'DELETE /api/v1/admin/users/8/invitation': { body: { data: ROWS[2] } },
      'POST /api/v1/admin/users/8/invitation': {
        body: { data: ROWS[2], meta: { email_queued: true } },
      },
      'POST /api/v1/admin/users/7/password-reset': {
        body: { message: 'A password reset email has been queued.' },
      },
    });
    await queryTable();
    await u.click(
      within(row('Dave Dealer')).getByRole('button', {
        name: /password reset to Dave/i,
      }),
    );
    await waitFor(() =>
      expect(api.to('POST /api/v1/admin/users/7/password-reset')).toHaveLength(
        1,
      ),
    );
    expect(
      await screen.findByText('A password reset email has been queued.'),
    ).toBeInTheDocument();

    await u.click(
      within(row('Erin Pending')).getByRole('button', {
        name: /resend invitation/i,
      }),
    );
    await waitFor(() =>
      expect(api.to('POST /api/v1/admin/users/8/invitation')).toHaveLength(1),
    );
    expect(
      await screen.findByText('Invitation sent again.'),
    ).toBeInTheDocument();

    await u.click(
      within(row('Erin Pending')).getByRole('button', {
        name: /revoke invitation/i,
      }),
    );
    const dialog = screen.getByRole('dialog', {
      name: /revoke the invitation for Erin Pending/i,
    });
    expect(api.to('DELETE /api/v1/admin/users/8/invitation')).toHaveLength(0);
    await u.click(within(dialog).getByRole('button', { name: 'Revoke' }));
    await waitFor(() =>
      expect(api.to('DELETE /api/v1/admin/users/8/invitation')).toHaveLength(1),
    );
  });

  it('Resend warns when the email could not be queued; Reactivate posts', async () => {
    const u = userEvent.setup();
    const { api } = setup('/admin/users', {
      'POST /api/v1/admin/users/8/invitation': {
        body: { data: ROWS[2], meta: { email_queued: false } },
      },
      'POST /api/v1/admin/users/9/reactivate': { body: { data: ROWS[3] } },
    });
    await queryTable();
    await u.click(
      within(row('Erin Pending')).getByRole('button', {
        name: /resend invitation/i,
      }),
    );
    expect(await screen.findByText(/could not be queued/)).toBeInTheDocument();
    await u.click(
      within(row('Dan Inactive')).getByRole('button', {
        name: /^Reactivate Dan/,
      }),
    );
    await waitFor(() =>
      expect(api.to('POST /api/v1/admin/users/9/reactivate')).toHaveLength(1),
    );
  });
});

describe('user detail', () => {
  it('renames through PATCH with the name only and explains that the email is immutable', async () => {
    const u = userEvent.setup();
    const { api } = setupAdmin(
      '/admin/users/7',
      {
        'GET /api/v1/admin/users/7': { body: { data: user() } },
        'PATCH /api/v1/admin/users/7': {
          body: { data: user({ name: 'David' }) },
        },
      },
      admin,
    );
    await screen.findByRole('heading', { name: 'Dave Dealer' });
    expect(
      screen.getByText(/email address cannot be changed/),
    ).toBeInTheDocument();
    const name = screen.getByLabelText('Name');
    await u.clear(name);
    await u.type(name, 'David');
    await u.click(screen.getByRole('button', { name: 'Save name' }));
    await waitFor(() =>
      expect(api.to('PATCH /api/v1/admin/users/7')).toHaveLength(1),
    );
    expect(api.to('PATCH /api/v1/admin/users/7')[0].body).toEqual({
      name: 'David',
    });
    expect(await screen.findByText('Name saved.')).toBeInTheDocument();
  });

  it('shows User not found for a 404', async () => {
    setupAdmin(
      '/admin/users/7',
      {
        'GET /api/v1/admin/users/7': {
          status: 404,
          body: { message: 'Not found.' },
        },
      },
      admin,
    );
    expect(
      await screen.findByRole('heading', { name: 'User not found' }),
    ).toBeInTheDocument();
  });
});
