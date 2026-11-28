import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { admin } from '../../../test/utils';
import {
  AUDIT,
  COMPANIES,
  company,
  entry,
  loc,
  paged,
  setupAdmin,
} from './fixtures';
import { ACTIONS, actionLabel } from '../AuditPage';

const ROWS = [
  entry(),
  entry({
    id: 980,
    action: 'product.updated',
    actor: null,
    subject: { type: 'product', id: '17', label: '<b>6205-2RS</b>' },
    company: null,
    changes: { before: { price_cents: 1250 }, after: { price_cents: 1300 } },
    metadata: { description_changed: true },
  }),
  entry({
    id: 979,
    action: 'product.imported',
    subject: { type: 'import', id: '3f2b8c1e', label: null },
    changes: {},
    metadata: { rows_read: 200, created: 150 },
  }),
];
const setup = (path = '/admin/audit-log', routes = {}) =>
  setupAdmin(path, { [AUDIT]: paged(ROWS), ...routes }, admin);
const auditCalls = (api: { calls: { url: string }[] }) =>
  api.calls.filter((c) => c.url.startsWith('/api/v1/admin/audit-log'));

describe('audit log page', () => {
  it('renders a table captioned "Audit log" with time, actor, action, subject links and changes as text', async () => {
    setup();
    const t = await screen.findByRole('table', { name: 'Audit log' });
    const rows = within(t).getAllByRole('row');
    expect(rows).toHaveLength(4);
    const first = within(rows[1]);
    expect(first.getByText('2026-11-27 10:15 UTC')).toBeInTheDocument();
    expect(first.getByText('Sam Staff (staff)')).toBeInTheDocument();
    expect(first.getByText('Order: status changed')).toBeInTheDocument();
    expect(
      first.getByRole('link', { name: 'order ORD-K7Q2M-X9PA3' }),
    ).toHaveAttribute('href', '/admin/orders/ORD-K7Q2M-X9PA3');
    expect(first.getByText('Northwind Machinery Ltd')).toBeInTheDocument();
    expect(first.getByText('status: pending to confirmed')).toBeInTheDocument();
    // System rows have no actor; metadata is listed.
    const second = within(rows[2]);
    expect(second.getByText('System')).toBeInTheDocument();
    expect(second.getByText('price_cents: 1250 to 1300')).toBeInTheDocument();
    expect(second.getByText('description_changed: true')).toBeInTheDocument();
    // Imports have no page to link to.
    expect(within(rows[3]).queryByRole('link')).toBeNull();
    expect(within(rows[3]).getByText('import 3f2b8c1e')).toBeInTheDocument();
  });

  it('renders hostile subject labels as text, never HTML', async () => {
    setup();
    const link = await screen.findByRole('link', {
      name: 'product <b>6205-2RS</b>',
    });
    expect(link).toHaveAttribute('href', '/admin/products/17');
    expect(document.querySelector('table b')).toBeNull();
  });

  it('the default sort is newest first and the Time header flips it', async () => {
    const user = userEvent.setup();
    const { api } = setup();
    await screen.findByRole('table', { name: 'Audit log' });
    const h = () => screen.getByRole('columnheader', { name: /^Time/ });
    expect(h()).toHaveAttribute('aria-sort', 'descending');
    await user.click(within(h()).getByRole('button'));
    await waitFor(() => expect(loc()).toHaveTextContent('?sort=occurred_at'));
    expect(h()).toHaveAttribute('aria-sort', 'ascending');
    await waitFor(() =>
      expect(auditCalls(api).at(-1)?.url).toBe(
        '/api/v1/admin/audit-log?sort=occurred_at',
      ),
    );
    await user.click(within(h()).getByRole('button'));
    await waitFor(() =>
      expect(loc()).toHaveTextContent(/^\/admin\/audit-log$/),
    );
  });

  it('filters sync to the URL and the request', async () => {
    const user = userEvent.setup();
    const { api } = setup('/admin/audit-log', {
      [COMPANIES]: paged([company()]),
      'GET /api/v1/admin/companies/1': { body: { data: company() } },
    });
    await screen.findByRole('table', { name: 'Audit log' });
    await user.click(screen.getByLabelText('Order: status changed'));
    await waitFor(() =>
      expect(loc()).toHaveTextContent('?action=order.status_changed'),
    );
    await user.selectOptions(screen.getByLabelText('Subject type'), 'order');
    // One input event per field (paste / change), never keystroke by keystroke: the page debounces the URL and the request,
    // so typing 15 characters on a slow runner could commit a partial value in between and make this test flaky.
    await user.click(screen.getByLabelText('Subject ID'));
    await user.paste('ORD-K7Q2M-X9PA3');
    await user.click(screen.getByLabelText('Actor (user ID)'));
    await user.paste('2');
    await user.click(screen.getByLabelText('Company'));
    await user.paste('north');
    await user.click(
      await screen.findByRole('button', { name: 'Northwind Machinery Ltd' }),
    );
    fireEvent.change(screen.getByLabelText('From date'), {
      target: { value: '2026-11-01' },
    });
    fireEvent.change(screen.getByLabelText('To date'), {
      target: { value: '2026-11-30' },
    });
    await waitFor(
      () =>
        expect(auditCalls(api).at(-1)?.url).toBe(
          '/api/v1/admin/audit-log?action=order.status_changed&subject_type=order&subject_id=ORD-K7Q2M-X9PA3&company_id=1&actor_id=2&from=2026-11-01&to=2026-11-30',
        ),
      { timeout: 10_000 },
    );
    expect(loc()).toHaveTextContent('from=2026-11-01&to=2026-11-30');
  });

  it('restores filters from the URL, drops invalid values and never sends per_page', async () => {
    const { api } = setup(
      '/admin/audit-log?action=order.status_changed,bogus&subject_type=nope&from=2026-02-30&actor_id=x&page=3',
    );
    await screen.findByRole('table', { name: 'Audit log' });
    expect(screen.getByLabelText('Order: status changed')).toBeChecked();
    expect(auditCalls(api)[0].url).toBe(
      '/api/v1/admin/audit-log?action=order.status_changed&page=3',
    );
  });

  it('a From date after the To date shows an alert and sends no request', async () => {
    const { api } = setup('/admin/audit-log?from=2026-12-01&to=2026-11-01');
    expect(await screen.findByRole('alert')).toHaveTextContent(
      '"From date" must not be after "To date".',
    );
    expect(auditCalls(api)).toHaveLength(0);
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('paginates through the shared table', async () => {
    const user = userEvent.setup();
    setup('/admin/audit-log', {
      [AUDIT]: paged(ROWS, { last_page: 3, total: 60 }),
    });
    await screen.findByRole('table', { name: 'Audit log' });
    await user.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(loc()).toHaveTextContent('?page=2'));
  });

  it('shows empty, error with retry and 429 states', async () => {
    const user = userEvent.setup();
    let n = 0;
    setup('/admin/audit-log', {
      [AUDIT]: () =>
        n++ === 0
          ? { status: 429, body: {}, headers: { 'Retry-After': '9' } }
          : paged([]),
    });
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'try again in 9 seconds',
    );
    await user.click(screen.getByRole('button', { name: /try again/i }));
    expect(
      await screen.findByText('No audit entries yet.'),
    ).toBeInTheDocument();
  });
});

// The vocabulary of docs/api-contract.md (and AuditLog::ACTIONS on the API side).
const CONTRACT_ACTIONS = [
  'order.status_changed',
  'quote.reviewed',
  'quote.sent',
  'quote.reopened',
  'quote.rejected',
  'quote.accepted',
  'quote.declined',
  'quote.priced',
  'quote.attachment_purged',
  'invoice.created',
  'invoice.paid',
  'invoice.voided',
  'product.created',
  'product.updated',
  'product.activated',
  'product.deactivated',
  'product.imported',
  'product.import_updated',
  'company.created',
  'company.updated',
  'user.invited',
  'user.invitation_resent',
  'user.invitation_revoked',
  'user.invitation_accepted',
  'user.updated',
  'user.assignment_changed',
  'user.deactivated',
  'user.reactivated',
  'user.password_reset_requested',
];

describe('audit action vocabulary', () => {
  it('offers exactly the contract actions', () => {
    expect([...ACTIONS].sort()).toEqual([...CONTRACT_ACTIONS].sort());
  });

  it.each(CONTRACT_ACTIONS)(
    '?action=%s is kept, sent to the API and ticked',
    async (action) => {
      const { api } = setup(`/admin/audit-log?action=${action}`);
      await screen.findByRole('table', { name: 'Audit log' });
      expect(auditCalls(api)[0].url).toBe(
        `/api/v1/admin/audit-log?action=${action}`,
      );
      expect(screen.getByLabelText(actionLabel(action))).toBeChecked();
      expect(loc()).toHaveTextContent(`?action=${action}`);
    },
  );
});
