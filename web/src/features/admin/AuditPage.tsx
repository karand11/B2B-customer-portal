import { Link } from 'react-router-dom';
import DataTable, { type Column } from '../../components/DataTable';
import PageTitle from '../../components/PageTitle';
import {
  csvParam,
  dateParam,
  enumParam,
  intParam,
  pageParam,
  sortParam,
  textParam,
  useListUrlState,
  type ListSpec,
} from '../../lib/listUrlState';
import { SearchBox } from '../catalog/FilterSidebar';
import { useAuditLog, type AuditEntry } from './api';
import { fmtUtc } from './format';
import { linkCls } from './forms';
import { CompanyFilter } from './ListFilters';

// The stable action vocabulary of the contract.
export const ACTIONS = [
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
] as const;
const SUBJECT_TYPES = [
  'order',
  'quote',
  'invoice',
  'product',
  'company',
  'user',
  'import',
] as const;

export const actionLabel = (a: string) => {
  const t = a.replace('.', ': ').replace(/_/g, ' ');
  return t.charAt(0).toUpperCase() + t.slice(1);
};

interface AuditQuery {
  action: (typeof ACTIONS)[number][];
  subject_type: '' | (typeof SUBJECT_TYPES)[number];
  subject_id: string;
  company_id: string;
  actor_id: string;
  from: string;
  to: string;
  sort: string;
  page: number;
}
const SPEC: ListSpec<AuditQuery> = {
  action: csvParam(ACTIONS),
  subject_type: enumParam(SUBJECT_TYPES),
  subject_id: textParam(64),
  company_id: intParam,
  actor_id: intParam,
  from: dateParam,
  to: dateParam,
  sort: sortParam(['occurred_at', '-occurred_at'], '-occurred_at'),
  page: pageParam,
};

const SUBJECT_PATH: Record<string, string> = {
  order: 'orders',
  quote: 'quotes',
  invoice: 'invoices',
  product: 'products',
  company: 'companies',
  user: 'users',
};

function Subject({ s }: { s: AuditEntry['subject'] }) {
  const text = `${s.type} ${s.label ?? s.id}`;
  const path = SUBJECT_PATH[s.type];
  return path ? (
    <Link to={`/admin/${path}/${encodeURIComponent(s.id)}`} className={linkCls}>
      {text}
    </Link>
  ) : (
    <>{text}</>
  );
}

const show = (v: unknown) =>
  v === null || v === undefined ? 'none' : String(v);

/** Allowlisted scalars only (the API filters), rendered as plain text. */
function Details({ e }: { e: AuditEntry }) {
  const before = e.changes.before ?? {};
  const after = e.changes.after ?? {};
  const keys = [...new Set([...Object.keys(before), ...Object.keys(after)])];
  const meta = Object.entries(e.metadata);
  if (keys.length === 0 && meta.length === 0) return <>No details</>;
  return (
    <details>
      <summary className="cursor-pointer">Details</summary>
      {keys.length > 0 && (
        <ul className="mt-1 list-disc pl-4">
          {keys.map((k) => (
            <li key={k}>
              {k}: {k in before ? show(before[k]) : 'none'} to{' '}
              {k in after ? show(after[k]) : 'none'}
            </li>
          ))}
        </ul>
      )}
      {meta.length > 0 && (
        <ul className="mt-1 list-disc pl-4">
          {meta.map(([k, v]) => (
            <li key={k}>
              {k}: {show(v)}
            </li>
          ))}
        </ul>
      )}
    </details>
  );
}

const COLUMNS: Column<AuditEntry>[] = [
  {
    key: 'time',
    header: 'Time',
    sortKey: 'occurred_at',
    render: (e) => fmtUtc(e.occurred_at),
  },
  {
    key: 'actor',
    header: 'Actor',
    render: (e) => (e.actor ? `${e.actor.name} (${e.actor.role})` : 'System'),
  },
  { key: 'action', header: 'Action', render: (e) => actionLabel(e.action) },
  {
    key: 'subject',
    header: 'Subject',
    render: (e) => <Subject s={e.subject} />,
  },
  {
    key: 'company',
    header: 'Company',
    render: (e) => e.company?.name ?? '-',
  },
  { key: 'details', header: 'Changes', render: (e) => <Details e={e} /> },
];

const input = 'rounded border border-slate-500 px-2 py-1';
const lbl = 'mb-1 block text-sm font-semibold';

export default function AuditPage() {
  const { values, search, set, hasFilters, clear } = useListUrlState(SPEC);
  const rangeError =
    values.from && values.to && values.from > values.to
      ? '"From date" must not be after "To date".'
      : null;
  const list = useAuditLog(search, !rangeError);
  return (
    <section>
      <PageTitle>Audit log</PageTitle>
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <details className="mb-4">
          <summary className="cursor-pointer text-sm font-semibold">
            Actions ({values.action.length} selected)
          </summary>
          <fieldset className="mt-1 max-h-64 overflow-y-auto">
            <legend className="sr-only">Action</legend>
            {ACTIONS.map((a) => (
              <label key={a} className="flex items-center gap-1 text-sm">
                <input
                  type="checkbox"
                  checked={values.action.includes(a)}
                  onChange={(e) =>
                    set({
                      action: e.target.checked
                        ? [...values.action, a]
                        : values.action.filter((x) => x !== a),
                    })
                  }
                />
                {actionLabel(a)}
              </label>
            ))}
          </fieldset>
        </details>
        <div className="mb-4">
          <label htmlFor="audit-type" className={lbl}>
            Subject type
          </label>
          <select
            id="audit-type"
            value={values.subject_type}
            onChange={(e) =>
              set({
                subject_type: e.target.value as AuditQuery['subject_type'],
              })
            }
            className={input}
          >
            <option value="">Any</option>
            {SUBJECT_TYPES.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        </div>
        <SearchBox
          id="audit-subject"
          label="Subject ID"
          hint="Order, quote or invoice number, or a numeric id"
          value={values.subject_id}
          onCommit={(subject_id) => set({ subject_id }, true)}
        />
        <SearchBox
          id="audit-actor"
          label="Actor (user ID)"
          hint="Numeric user id"
          value={values.actor_id}
          onCommit={(v) =>
            set({ actor_id: intParam.parse(v || null) ?? '' }, true)
          }
        />
        <CompanyFilter
          value={values.company_id}
          onChange={(company_id) => set({ company_id })}
        />
        {(['from', 'to'] as const).map((k) => (
          <div key={k} className="mb-4">
            <label htmlFor={`audit-${k}`} className={lbl}>
              {k === 'from' ? 'From date' : 'To date'}
            </label>
            <input
              id={`audit-${k}`}
              type="date"
              value={values[k]}
              aria-invalid={rangeError ? true : undefined}
              aria-describedby={rangeError ? 'audit-range-error' : undefined}
              onChange={(e) => set({ [k]: e.target.value }, true)}
              className={input}
            />
          </div>
        ))}
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
      {rangeError && (
        <p id="audit-range-error" role="alert" className="mb-3 text-red-700">
          {rangeError}
        </p>
      )}
      {!rangeError && (
        <DataTable
          caption="Audit log"
          columns={COLUMNS}
          rows={list.data?.data}
          rowKey={(e) => e.id}
          sort={values.sort}
          defaultSort={SPEC.sort.default}
          onSortChange={(sort) => set({ sort })}
          page={values.page}
          lastPage={list.data?.meta.last_page ?? 1}
          perPage={list.data?.meta.per_page}
          total={list.data?.meta.total}
          onPageChange={(page) => set({ page })}
          isLoading={list.isPending}
          isPlaceholder={list.isPlaceholderData}
          error={list.error}
          onRetry={() => void list.refetch()}
          emptyMessage="No audit entries yet."
          hasFilters={hasFilters}
          onClearFilters={clear}
          noun={['entry', 'entries']}
        />
      )}
    </section>
  );
}
