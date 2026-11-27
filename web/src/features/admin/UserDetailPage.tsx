import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { SubmitButton, TextField } from '../../components/FormParts';
import PageTitle from '../../components/PageTitle';
import { ApiError } from '../../lib/api';
import CatalogError from '../catalog/CatalogError';
import { useAdminUser, useRenameUser, type UserAdmin } from './api';
import { fmtUtc } from './format';
import { TextAlert, linkCls, mapServerErrors } from './forms';
import UserActions, { UserStatusBadge } from './UserActions';

const nameSchema = z.object({
  name: z
    .string()
    .trim()
    .min(1, 'Enter a name.')
    .max(255, 'Use at most 255 characters.'),
});
type NameValues = z.infer<typeof nameSchema>;

function RenameForm({ user }: { user: UserAdmin }) {
  const rename = useRenameUser(user.id);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const form = useForm<NameValues>({
    resolver: zodResolver(nameSchema),
    defaultValues: { name: user.name },
  });
  return (
    <form
      noValidate
      aria-label="Rename user"
      onSubmit={form.handleSubmit(async (v) => {
        setError(null);
        setSaved(false);
        try {
          await rename.mutateAsync(v.name);
          setSaved(true);
        } catch (e) {
          setError(mapServerErrors(e, form.setError, ['name']));
        }
      })}
    >
      <TextField
        id="user-name"
        label="Name"
        error={form.formState.errors.name?.message}
        {...form.register('name')}
      />
      <TextAlert message={error} />
      <SubmitButton
        pending={rename.isPending}
        idle="Save name"
        busy="Saving..."
      />
      <p role="status" aria-live="polite" className="mt-2 text-sm">
        {saved ? 'Name saved.' : ''}
      </p>
    </form>
  );
}

export default function UserDetailPage() {
  const { id = '' } = useParams();
  const valid = /^[1-9]\d{0,17}$/.test(id);
  const q = useAdminUser(id, valid);

  if (!valid || (q.error instanceof ApiError && q.error.status === 404))
    return (
      <section>
        <PageTitle>User not found</PageTitle>
        <Link to="/admin/users" className={linkCls}>
          Back to users
        </Link>
      </section>
    );
  if (q.isError && !q.data)
    return <CatalogError error={q.error} onRetry={() => void q.refetch()} />;
  const u = q.data;
  if (!u)
    return (
      <div role="status" aria-busy="true" aria-label="Loading user">
        <div className="mb-2 h-8 w-1/2 animate-pulse rounded bg-slate-200" />
        <div className="h-32 animate-pulse rounded bg-slate-200" />
      </div>
    );

  return (
    <section>
      <Link to="/admin/users" className={linkCls}>
        Back to users
      </Link>
      <div className="mt-2">
        <PageTitle>{u.name}</PageTitle>
      </div>
      <dl className="mb-6 grid max-w-md grid-cols-2 gap-1 text-sm">
        <dt>Email</dt>
        <dd>{u.email}</dd>
        <dt>Role</dt>
        <dd>{u.role}</dd>
        <dt>Company</dt>
        <dd>
          {u.company ? (
            <Link to={`/admin/companies/${u.company.id}`} className={linkCls}>
              {u.company.name}
            </Link>
          ) : (
            'None'
          )}
        </dd>
        <dt>Status</dt>
        <dd>
          <UserStatusBadge status={u.status} />
        </dd>
        {u.invited_at && (
          <>
            <dt>Invited</dt>
            <dd>{fmtUtc(u.invited_at)}</dd>
          </>
        )}
        {u.invitation_accepted_at && (
          <>
            <dt>Invitation accepted</dt>
            <dd>{fmtUtc(u.invitation_accepted_at)}</dd>
          </>
        )}
        <dt>Created</dt>
        <dd>{fmtUtc(u.created_at)}</dd>
      </dl>
      <section aria-labelledby="user-actions" className="mb-6">
        <h2 id="user-actions" className="mb-2 text-lg font-semibold">
          Actions
        </h2>
        <UserActions user={u} />
      </section>
      {u.allowed_actions.includes('edit') && (
        <section aria-labelledby="user-edit" className="max-w-sm">
          <h2 id="user-edit" className="mb-2 text-lg font-semibold">
            Edit user
          </h2>
          <p className="mb-2 text-sm">
            The email address cannot be changed. To fix a typo, revoke the
            invitation and invite the right address.
          </p>
          <RenameForm key={u.id} user={u} />
        </section>
      )}
    </section>
  );
}
