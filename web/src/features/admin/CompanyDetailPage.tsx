import { useRef, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useAuth } from '../../auth/AuthContext';
import { SubmitButton, TextField } from '../../components/FormParts';
import PageTitle from '../../components/PageTitle';
import { ApiError } from '../../lib/api';
import { formatMoney } from '../../lib/format';
import CatalogError from '../catalog/CatalogError';
import {
  isConflict,
  useAdminCompany,
  useCreateCompany,
  useUpdateBilling,
  useUpdateCompany,
  type CompanyDetail,
} from './api';
import { fmtDay } from './format';
import {
  ConflictBanner,
  TextAlert,
  TextAreaField,
  linkCls,
  mapServerErrors,
} from './forms';
import { companyBody, companySchema, type CompanyValues } from './schemas';

const FIELDS = ['name', 'billing_address', 'tax_id'] as const;
const LABELS = {
  name: 'Name',
  billing_address: 'Billing address',
  tax_id: 'Tax ID',
} as const;

const fromCompany = (c?: CompanyDetail): CompanyValues => ({
  name: c?.name ?? '',
  billing_address: c?.billing_address ?? '',
  tax_id: c?.tax_id ?? '',
});

/**
 * Create (admin), edit (admin: PUT, may rename) and billing edit (staff: PUT .../billing,
 * name is read only). Echoes `version`; a 409 shows the conflict banner.
 */
function CompanyForm({
  company,
  canRename,
  onReload,
}: {
  company?: CompanyDetail;
  canRename: boolean;
  onReload?: () => Promise<CompanyDetail | undefined>;
}) {
  const navigate = useNavigate();
  const id = String(company?.id ?? '');
  const create = useCreateCompany();
  const update = useUpdateCompany(id);
  const billing = useUpdateBilling(id);
  const form = useForm<CompanyValues>({
    resolver: zodResolver(companySchema),
    defaultValues: fromCompany(company),
  });
  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, dirtyFields },
  } = form;
  const loadedVersion = useRef(company?.version ?? 0);
  const guard = useRef(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);
  const [reloading, setReloading] = useState(false);
  const [mine, setMine] = useState<{ label: string; value: string }[] | null>(
    null,
  );
  const [notice, setNotice] = useState('');

  const submit = (e: React.FormEvent) => {
    void handleSubmit(async (values) => {
      if (guard.current) return;
      guard.current = true;
      setFormError(null);
      setNotice('');
      try {
        const body = companyBody(values);
        if (!company) {
          const res = await create.mutateAsync(body);
          navigate(`/admin/companies/${res.data.id}`, {
            state: { notice: 'Company created.' },
          });
          return;
        }
        const version = loadedVersion.current;
        const res = canRename
          ? await update.mutateAsync({ ...body, version })
          : await billing.mutateAsync({
              billing_address: body.billing_address,
              tax_id: body.tax_id,
              version,
            });
        loadedVersion.current = res.data.version;
        reset(fromCompany(res.data));
        setMine(null);
        setNotice('Company saved.');
      } catch (e) {
        if (isConflict(e)) {
          setMine(
            FIELDS.filter((f) => dirtyFields[f]).map((f) => ({
              label: LABELS[f],
              value: values[f],
            })),
          );
          setConflict(true);
        } else setFormError(mapServerErrors(e, setError, FIELDS));
      } finally {
        guard.current = false;
      }
    })(e);
  };

  const reload = async () => {
    setReloading(true);
    try {
      const fresh = await onReload?.();
      if (fresh) {
        reset(fromCompany(fresh));
        loadedVersion.current = fresh.version;
        setConflict(false);
      }
    } finally {
      setReloading(false);
    }
  };

  return (
    <form
      onSubmit={submit}
      noValidate
      aria-label={company ? 'Company details' : 'New company'}
    >
      {notice && (
        <p
          role="status"
          className="mb-4 rounded border border-green-700 bg-green-50 p-3 text-green-900"
        >
          {notice}
        </p>
      )}
      {conflict && (
        <ConflictBanner onReload={() => void reload()} reloading={reloading} />
      )}
      {!conflict && mine && mine.length > 0 && (
        <details className="mb-4 rounded border border-slate-400 p-3">
          <summary className="cursor-pointer font-semibold">
            Your changes
          </summary>
          <ul className="mt-1 list-disc pl-5 text-sm">
            {mine.map((m) => (
              <li key={m.label} className="whitespace-pre-wrap">
                {m.label}: {m.value}
              </li>
            ))}
          </ul>
          <button
            type="button"
            onClick={() => setMine(null)}
            className="mt-2 rounded border border-slate-600 px-3 py-1"
          >
            Dismiss
          </button>
        </details>
      )}
      {canRename ? (
        <TextField
          id="company-name"
          label="Name"
          error={errors.name?.message}
          {...register('name')}
        />
      ) : (
        <p className="mb-4">
          <span className="block text-sm font-medium">Name</span>
          {company?.name}
        </p>
      )}
      <TextAreaField
        id="billing_address"
        label="Billing address"
        rows={6}
        hint="Up to 6 lines, 80 characters each. Printed on new invoices; issued invoices keep their own copy."
        error={errors.billing_address?.message}
        {...register('billing_address')}
      />
      <TextField
        id="tax_id"
        label="Tax ID"
        error={errors.tax_id?.message}
        {...register('tax_id')}
      />
      <TextAlert message={formError} />
      <SubmitButton
        pending={create.isPending || update.isPending || billing.isPending}
        idle={company ? 'Save company' : 'Create company'}
        busy="Saving..."
      />
    </form>
  );
}

export function CompanyNewPage() {
  const { user } = useAuth();
  if (user?.role !== 'admin')
    return (
      <section>
        <PageTitle>Not allowed</PageTitle>
        <p>Only administrators can create companies.</p>
      </section>
    );
  return (
    <section>
      <PageTitle>New company</PageTitle>
      <CompanyForm canRename />
      <p className="mt-4">
        <Link to="/admin/companies" className={linkCls}>
          Back to companies
        </Link>
      </p>
    </section>
  );
}

const th = 'px-3 py-2 text-left text-sm font-semibold';
const td = 'px-3 py-2 text-sm';

export default function CompanyDetailPage() {
  const { id = '' } = useParams();
  const { state } = useLocation();
  const { user } = useAuth();
  const valid = /^[1-9]\d{0,17}$/.test(id);
  const q = useAdminCompany(id, valid);
  const isAdmin = user?.role === 'admin';
  const created = (state as { notice?: unknown } | null)?.notice;

  if (!valid || (q.error instanceof ApiError && q.error.status === 404))
    return (
      <section>
        <PageTitle>Company not found</PageTitle>
        <Link to="/admin/companies" className={linkCls}>
          Back to companies
        </Link>
      </section>
    );
  if (q.isError && !q.data)
    return <CatalogError error={q.error} onRetry={() => void q.refetch()} />;
  const c = q.data;
  if (!c)
    return (
      <div role="status" aria-busy="true" aria-label="Loading company">
        <div className="mb-2 h-8 w-1/2 animate-pulse rounded bg-slate-200" />
        <div className="h-32 animate-pulse rounded bg-slate-200" />
      </div>
    );

  return (
    <section>
      <Link to="/admin/companies" className={linkCls}>
        Back to companies
      </Link>
      <div className="mt-2">
        <PageTitle>{c.name}</PageTitle>
      </div>
      {typeof created === 'string' && (
        <p role="status" className="mb-4 text-green-900">
          {created}
        </p>
      )}
      <dl className="mb-6 grid max-w-md grid-cols-2 gap-1 text-sm">
        <dt>Users</dt>
        <dd>{c.users_count}</dd>
        <dt>Open orders</dt>
        <dd>{c.open_orders_count}</dd>
        <dt>Open quotes</dt>
        <dd>{c.open_quotes_count}</dd>
        <dt>Unpaid invoices</dt>
        <dd>{c.unpaid_invoices_count}</dd>
        <dt>Created</dt>
        <dd>{fmtDay(c.created_at)}</dd>
      </dl>
      {!c.has_billing_address && (
        <p
          role="status"
          className="mb-4 rounded border border-amber-700 bg-amber-50 p-3 text-amber-900"
        >
          Billing missing: add a billing address before invoicing this company.
        </p>
      )}

      <section aria-labelledby="co-edit" className="mb-8 max-w-xl">
        <h2 id="co-edit" className="mb-2 text-lg font-semibold">
          {isAdmin ? 'Edit company' : 'Billing details'}
        </h2>
        <CompanyForm
          key={c.id}
          company={c}
          canRename={isAdmin}
          onReload={async () => (await q.refetch()).data}
        />
      </section>

      <section aria-labelledby="co-users" className="mb-8">
        <h2 id="co-users" className="mb-2 text-lg font-semibold">
          Users
        </h2>
        {c.users.length === 0 ? (
          <p>No users in this company.</p>
        ) : (
          <table className="w-full border-collapse">
            <caption className="sr-only">Users of {c.name}</caption>
            <thead>
              <tr className="border-b border-slate-400 bg-slate-100">
                {['Name', 'Email', 'Role', 'Status'].map((h) => (
                  <th key={h} scope="col" className={th}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {c.users.map((u) => (
                <tr key={u.id} className="border-b border-slate-300">
                  <td className={td}>
                    {isAdmin ? (
                      <Link to={`/admin/users/${u.id}`} className={linkCls}>
                        {u.name}
                      </Link>
                    ) : (
                      u.name
                    )}
                  </td>
                  <td className={td}>{u.email}</td>
                  <td className={td}>{u.role}</td>
                  <td className={td}>{u.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <section aria-labelledby="co-recent">
        <h2 id="co-recent" className="mb-2 text-lg font-semibold">
          Recent activity
        </h2>
        <div className="grid gap-6 md:grid-cols-3">
          <div>
            <h3 className="mb-1 font-semibold">Orders</h3>
            {c.recent.orders.length === 0 ? (
              <p className="text-sm">None.</p>
            ) : (
              <ul className="space-y-1 text-sm">
                {c.recent.orders.map((o) => (
                  <li key={o.number}>
                    <Link to={`/admin/orders/${o.number}`} className={linkCls}>
                      {o.number}
                    </Link>{' '}
                    {o.status}, {formatMoney(o.total_cents, o.currency)}
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <h3 className="mb-1 font-semibold">Quotes</h3>
            {c.recent.quotes.length === 0 ? (
              <p className="text-sm">None.</p>
            ) : (
              <ul className="space-y-1 text-sm">
                {c.recent.quotes.map((o) => (
                  <li key={o.number}>
                    <Link to={`/admin/quotes/${o.number}`} className={linkCls}>
                      {o.number}
                    </Link>{' '}
                    {o.status}
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <h3 className="mb-1 font-semibold">Invoices</h3>
            {c.recent.invoices.length === 0 ? (
              <p className="text-sm">None.</p>
            ) : (
              <ul className="space-y-1 text-sm">
                {c.recent.invoices.map((o) => (
                  <li key={o.id}>
                    <Link to={`/admin/invoices/${o.id}`} className={linkCls}>
                      {o.number}
                    </Link>{' '}
                    {o.status}, due {o.due_date}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </section>
    </section>
  );
}
