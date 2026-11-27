import { Link } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext';
import DataTable, { type Column } from '../../components/DataTable';
import PageTitle from '../../components/PageTitle';
import {
  pageParam,
  sortParam,
  textParam,
  useListUrlState,
  type ListSpec,
} from '../../lib/listUrlState';
import { SearchBox } from '../catalog/FilterSidebar';
import { useAdminCompanies, type CompanyAdmin } from './api';
import { fmtDay } from './format';
import { linkCls } from './forms';

interface CompanyQuery {
  q: string;
  sort: string;
  page: number;
}
const SPEC: ListSpec<CompanyQuery> = {
  q: textParam(64),
  sort: sortParam(['name', '-name', 'created_at', '-created_at'], 'name'),
  page: pageParam,
};

const COLUMNS: Column<CompanyAdmin>[] = [
  {
    key: 'name',
    header: 'Name',
    sortKey: 'name',
    render: (c) => (
      <Link to={`/admin/companies/${c.id}`} className={linkCls}>
        {c.name}
      </Link>
    ),
  },
  { key: 'tax_id', header: 'Tax ID', render: (c) => c.tax_id ?? '-' },
  {
    key: 'billing',
    header: 'Billing address',
    render: (c) =>
      c.has_billing_address ? (
        'On file'
      ) : (
        <span className="inline-block rounded border border-amber-700 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-900">
          Billing missing
        </span>
      ),
  },
  { key: 'users', header: 'Users', render: (c) => c.users_count },
  { key: 'orders', header: 'Open orders', render: (c) => c.open_orders_count },
  { key: 'quotes', header: 'Open quotes', render: (c) => c.open_quotes_count },
  {
    key: 'invoices',
    header: 'Unpaid invoices',
    render: (c) => c.unpaid_invoices_count,
  },
  {
    key: 'created_at',
    header: 'Created',
    sortKey: 'created_at',
    render: (c) => fmtDay(c.created_at),
  },
];

export default function CompaniesPage() {
  const { user } = useAuth();
  const { values, search, set, hasFilters, clear } = useListUrlState(SPEC);
  const list = useAdminCompanies(search);
  return (
    <section>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <PageTitle>Companies</PageTitle>
        {user?.role === 'admin' && (
          <Link
            to="/admin/companies/new"
            className="mb-4 rounded bg-blue-700 px-3 py-1 text-white"
          >
            New company
          </Link>
        )}
      </div>
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <SearchBox
          id="companies-q"
          label="Company name"
          hint="Part of the name"
          value={values.q}
          onCommit={(q) => set({ q }, true)}
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
        caption="Companies"
        columns={COLUMNS}
        rows={list.data?.data}
        rowKey={(c) => c.id}
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
        emptyMessage="No companies yet."
        hasFilters={hasFilters}
        onClearFilters={clear}
        noun={['company', 'companies']}
      />
    </section>
  );
}
