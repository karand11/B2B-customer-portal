import { useState } from 'react';
import { Link } from 'react-router-dom';
import DataTable, { type Column } from '../../components/DataTable';
import PageTitle from '../../components/PageTitle';
import {
  csvParam,
  intParam,
  pageParam,
  sortParam,
  textParam,
  useListUrlState,
  type ListSpec,
} from '../../lib/listUrlState';
import { SearchBox } from '../catalog/FilterSidebar';
import {
  useAdminUsers,
  type UserAdmin,
  type UserRole,
  type UserStatus,
  type UserWithMeta,
} from './api';
import { fmtDay } from './format';
import { linkCls } from './forms';
import { CompanyFilter } from './ListFilters';
import UserActions, {
  InviteDialog,
  QUEUE_WARNING,
  UserStatusBadge,
} from './UserActions';

const ROLES: UserRole[] = ['customer', 'staff', 'admin'];
const STATUSES: UserStatus[] = ['pending', 'active', 'inactive'];
const ROLE_LABELS: Record<UserRole, string> = {
  customer: 'Customer',
  staff: 'Staff',
  admin: 'Administrator',
};
const STATUS_LABELS: Record<UserStatus, string> = {
  pending: 'Pending invitation',
  active: 'Active',
  inactive: 'Inactive',
};

interface UserQuery {
  q: string;
  role: UserRole[];
  status: UserStatus[];
  company_id: string;
  sort: string;
  page: number;
}
const SPEC: ListSpec<UserQuery> = {
  q: textParam(64),
  role: csvParam(ROLES),
  status: csvParam(STATUSES),
  company_id: intParam,
  sort: sortParam(
    ['name', '-name', 'email', '-email', 'created_at', '-created_at'],
    'name',
  ),
  page: pageParam,
};

const COLUMNS: Column<UserAdmin>[] = [
  {
    key: 'name',
    header: 'Name',
    sortKey: 'name',
    render: (u) => (
      <Link to={`/admin/users/${u.id}`} className={linkCls}>
        {u.name}
      </Link>
    ),
  },
  { key: 'email', header: 'Email', sortKey: 'email', render: (u) => u.email },
  { key: 'role', header: 'Role', render: (u) => ROLE_LABELS[u.role] },
  {
    key: 'company',
    header: 'Company',
    render: (u) =>
      u.company ? (
        <Link to={`/admin/companies/${u.company.id}`} className={linkCls}>
          {u.company.name}
        </Link>
      ) : (
        '-'
      ),
  },
  {
    key: 'status',
    header: 'Status',
    render: (u) => <UserStatusBadge status={u.status} />,
  },
  {
    key: 'created_at',
    header: 'Created',
    sortKey: 'created_at',
    render: (u) => fmtDay(u.created_at),
  },
  {
    key: 'actions',
    header: 'Actions',
    render: (u) => <UserActions user={u} named />,
  },
];

function Facet<T extends string>({
  legend,
  options,
  labels,
  selected,
  onChange,
}: {
  legend: string;
  options: T[];
  labels: Record<T, string>;
  selected: T[];
  onChange: (v: T[]) => void;
}) {
  return (
    <fieldset className="mb-4">
      <legend className="mb-1 text-sm font-semibold">{legend}</legend>
      <div className="flex flex-wrap gap-3">
        {options.map((o) => (
          <label key={o} className="flex items-center gap-1 text-sm">
            <input
              type="checkbox"
              checked={selected.includes(o)}
              onChange={(e) =>
                onChange(
                  e.target.checked
                    ? [...selected, o]
                    : selected.filter((x) => x !== o),
                )
              }
            />
            {labels[o]}
          </label>
        ))}
      </div>
    </fieldset>
  );
}

export default function UsersPage() {
  const { values, search, set, hasFilters, clear } = useListUrlState(SPEC);
  const list = useAdminUsers(search);
  const [inviting, setInviting] = useState(false);
  const [notice, setNotice] = useState<{ text: string; warn: boolean } | null>(
    null,
  );

  const invited = (res: UserWithMeta) =>
    setNotice(
      res.meta?.email_queued === false
        ? { text: QUEUE_WARNING, warn: true }
        : { text: `Invitation sent to ${res.data.email}.`, warn: false },
    );

  return (
    <section>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <PageTitle>Users</PageTitle>
        <button
          type="button"
          onClick={() => setInviting(true)}
          className="mb-4 rounded bg-blue-700 px-3 py-1 text-white"
        >
          Invite user
        </button>
      </div>
      <div aria-live="polite">
        {notice && (
          <p
            role={notice.warn ? 'alert' : 'status'}
            className={`mb-4 rounded border p-3 ${
              notice.warn
                ? 'border-amber-700 bg-amber-50 text-amber-900'
                : 'border-green-700 bg-green-50 text-green-900'
            }`}
          >
            {notice.text}
          </p>
        )}
      </div>
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <SearchBox
          id="users-q"
          label="Name or email"
          hint="Part of the name or email"
          value={values.q}
          onCommit={(q) => set({ q }, true)}
        />
        <Facet
          legend="Role"
          options={ROLES}
          labels={ROLE_LABELS}
          selected={values.role}
          onChange={(role) => set({ role })}
        />
        <Facet
          legend="Status"
          options={STATUSES}
          labels={STATUS_LABELS}
          selected={values.status}
          onChange={(status) => set({ status })}
        />
        <CompanyFilter
          value={values.company_id}
          onChange={(company_id) => set({ company_id })}
        />
        {hasFilters && (
          <button
            type="button"
            onClick={clear}
            className="mb-4 rounded border border-slate-600 px-3 py-1"
          >
            Clear filters
          </button>
        )}
      </div>
      <DataTable
        caption="Users"
        columns={COLUMNS}
        rows={list.data?.data}
        rowKey={(u) => u.id}
        sort={values.sort}
        defaultSort={SPEC.sort.default}
        onSortChange={(sort) => set({ sort })}
        page={values.page}
        lastPage={list.data?.meta.last_page ?? 1}
        total={list.data?.meta.total}
        onPageChange={(page) => set({ page })}
        isLoading={list.isPending}
        isPlaceholder={list.isPlaceholderData}
        error={list.error}
        onRetry={() => void list.refetch()}
        emptyMessage="No users yet."
        hasFilters={hasFilters}
        onClearFilters={clear}
        noun={['user', 'users']}
      />
      {inviting && (
        <InviteDialog onClose={() => setInviting(false)} onInvited={invited} />
      )}
    </section>
  );
}
