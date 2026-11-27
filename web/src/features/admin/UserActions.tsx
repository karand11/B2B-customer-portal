import { useEffect, useRef, useState } from 'react';
import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import ConfirmDialog from '../../components/ConfirmDialog';
import { TextField } from '../../components/FormParts';
import { describeError } from '../../lib/api';
import { FormDialog } from '../invoices/InvoiceDialogs';
import {
  useAssignUser,
  useInviteUser,
  useUserCommand,
  type UserAdmin,
  type UserCommand,
  type UserWithMeta,
} from './api';
import { SelectField } from './forms';
import { CompanyFilter } from './ListFilters';
import {
  assignSchema,
  inviteSchema,
  type AssignValues,
  type InviteValues,
} from './schemas';

const btn =
  'rounded border border-slate-600 px-2 py-0.5 text-sm disabled:opacity-60';

export const QUEUE_WARNING =
  'Saved, but the email could not be queued. Use Resend invitation.';

export function UserStatusBadge({ status }: { status: UserAdmin['status'] }) {
  const cls =
    status === 'pending'
      ? 'border-amber-700 bg-amber-50 text-amber-900'
      : status === 'inactive'
        ? 'border-slate-600 bg-slate-100 text-slate-800'
        : 'border-green-700 bg-green-50 text-green-900';
  return (
    <span
      className={`inline-block rounded border px-2 py-0.5 text-xs font-semibold ${cls}`}
    >
      {status === 'pending'
        ? 'Pending invitation'
        : status === 'inactive'
          ? 'Inactive'
          : 'Active'}
    </span>
  );
}

function RoleCompanyFields({ form }: { form: UseFormReturn<AssignValues> }) {
  const role = form.watch('role');
  const company = form.watch('company_id');
  return (
    <>
      <SelectField
        id="user-role"
        label="Role"
        error={form.formState.errors.role?.message}
        {...form.register('role', {
          onChange: (e: { target: { value: string } }) => {
            if (e.target.value !== 'customer') form.setValue('company_id', '');
          },
        })}
      >
        <option value="customer">Customer</option>
        <option value="staff">Staff</option>
        <option value="admin">Administrator</option>
      </SelectField>
      {role === 'customer' && (
        <>
          <CompanyFilter
            label="Company"
            removeLabel="Change company"
            value={company}
            onChange={(id) =>
              form.setValue('company_id', id, { shouldValidate: true })
            }
          />
          {form.formState.errors.company_id && (
            <p role="alert" className="-mt-3 mb-3 text-sm text-red-700">
              {form.formState.errors.company_id.message}
            </p>
          )}
        </>
      )}
    </>
  );
}

const companyId = (v: AssignValues) =>
  v.role === 'customer' ? Number(v.company_id) : null;

export function InviteDialog({
  onClose,
  onInvited,
}: {
  onClose: () => void;
  onInvited: (res: UserWithMeta) => void;
}) {
  const invite = useInviteUser();
  const form = useForm<InviteValues>({
    resolver: zodResolver(inviteSchema),
    defaultValues: { name: '', email: '', role: 'customer', company_id: '' },
  });
  return (
    <FormDialog
      title="Invite user"
      confirmLabel="Send invitation"
      form={form}
      fields={['name', 'email', 'role', 'company_id']}
      onClose={onClose}
      submit={async (v) =>
        onInvited(
          await invite.mutateAsync({
            name: v.name.trim(),
            email: v.email.trim(),
            role: v.role,
            company_id: companyId(v),
          }),
        )
      }
    >
      <TextField
        id="invite-name"
        label="Name"
        error={form.formState.errors.name?.message}
        {...form.register('name')}
      />
      <TextField
        id="invite-email"
        label="Email"
        type="email"
        autoComplete="off"
        error={form.formState.errors.email?.message}
        {...form.register('email')}
      />
      {/* InviteValues is a superset of AssignValues; RHF's form type is invariant, so cast. */}
      <RoleCompanyFields
        form={form as unknown as UseFormReturn<AssignValues>}
      />
    </FormDialog>
  );
}

function AssignDialog({
  user,
  onClose,
  onDone,
}: {
  user: UserAdmin;
  onClose: () => void;
  onDone: () => void;
}) {
  const assign = useAssignUser(user.id);
  const form = useForm<AssignValues>({
    resolver: zodResolver(assignSchema),
    defaultValues: {
      role: user.role,
      company_id: user.company ? String(user.company.id) : '',
    },
  });
  return (
    <FormDialog
      title={`Change role or company for ${user.name}`}
      confirmLabel="Save"
      form={form}
      fields={['role', 'company_id']}
      onClose={onClose}
      submit={async (v) => {
        await assign.mutateAsync({ role: v.role, company_id: companyId(v) });
        onDone();
      }}
    >
      <p className="mb-3 text-sm">
        The user is signed out and must sign in again. If they become staff or
        an administrator their cart is emptied. Existing orders and quotes stay
        with their company.
      </p>
      <RoleCompanyFields form={form} />
    </FormDialog>
  );
}

const MESSAGES: Record<UserCommand, string> = {
  deactivate: 'User deactivated.',
  reactivate: 'User reactivated.',
  'password-reset': 'A password reset email has been queued.',
  resend: 'Invitation sent again.',
  revoke: 'Invitation revoked.',
};

/**
 * Buttons strictly from `allowed_actions` (the API still re-checks every call and
 * owns the last-admin and self-action rules, which surface here as error text).
 */
export default function UserActions({
  user,
  named = false,
}: {
  user: UserAdmin;
  /** Add the user's name to each button's accessible name (for table rows). */
  named?: boolean;
}) {
  const command = useUserCommand(user.id);
  const [dialog, setDialog] = useState<
    'assign' | 'deactivate' | 'revoke' | null
  >(null);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const group = useRef<HTMLDivElement>(null);
  const can = (a: UserAdmin['allowed_actions'][number]) =>
    user.allowed_actions.includes(a);
  const name = (label: string) => (named ? `${label} ${user.name}` : undefined);

  // The clicked button may disappear (status changed): keep focus inside the actions.
  useEffect(() => {
    if (notice || error) group.current?.focus();
  }, [notice, error]);

  const run = (cmd: UserCommand) => {
    setNotice('');
    setError('');
    command.mutate(cmd, {
      onSuccess: (res) => {
        const queued = 'meta' in res ? res.meta?.email_queued : undefined;
        setNotice(queued === false ? QUEUE_WARNING : MESSAGES[cmd]);
        setDialog(null);
      },
      onError: (e) => {
        setError(describeError(e));
        setDialog(null);
      },
    });
  };

  return (
    <div ref={group} tabIndex={-1} className="outline-none">
      <div className="flex flex-wrap gap-1">
        {can('assign') && (
          <button
            type="button"
            className={btn}
            aria-label={name('Change role or company for')}
            onClick={() => setDialog('assign')}
          >
            Change role or company
          </button>
        )}
        {can('deactivate') && (
          <button
            type="button"
            className={btn}
            aria-label={name('Deactivate')}
            onClick={() => setDialog('deactivate')}
          >
            Deactivate
          </button>
        )}
        {can('reactivate') && (
          <button
            type="button"
            className={btn}
            disabled={command.isPending}
            aria-label={name('Reactivate')}
            onClick={() => run('reactivate')}
          >
            Reactivate
          </button>
        )}
        {can('reset_password') && (
          <button
            type="button"
            className={btn}
            disabled={command.isPending}
            aria-label={name('Send password reset to')}
            onClick={() => run('password-reset')}
          >
            Send password reset
          </button>
        )}
        {can('resend_invitation') && (
          <button
            type="button"
            className={btn}
            disabled={command.isPending}
            aria-label={name('Resend invitation to')}
            onClick={() => run('resend')}
          >
            Resend invitation
          </button>
        )}
        {can('revoke_invitation') && (
          <button
            type="button"
            className={btn}
            aria-label={name('Revoke invitation for')}
            onClick={() => setDialog('revoke')}
          >
            Revoke invitation
          </button>
        )}
      </div>
      <p role="status" aria-live="polite" className="mt-1 text-sm">
        {notice}
      </p>
      {error && (
        <p role="alert" className="mt-1 text-sm text-red-700">
          {error}
        </p>
      )}
      {dialog === 'assign' && (
        <AssignDialog
          user={user}
          onClose={() => setDialog(null)}
          onDone={() =>
            setNotice('Role and company saved. The user must sign in again.')
          }
        />
      )}
      {(dialog === 'deactivate' || dialog === 'revoke') && (
        <ConfirmDialog
          title={
            dialog === 'deactivate'
              ? `Deactivate ${user.name}?`
              : `Revoke the invitation for ${user.name}?`
          }
          confirmLabel={dialog === 'deactivate' ? 'Deactivate' : 'Revoke'}
          cancelLabel="Cancel"
          pending={command.isPending}
          onCancel={() => setDialog(null)}
          onConfirm={() =>
            run(dialog === 'deactivate' ? 'deactivate' : 'revoke')
          }
        >
          <p>
            {dialog === 'deactivate'
              ? 'They are signed out immediately and cannot sign in until you reactivate them.'
              : 'The invitation link stops working. You can send a new invitation later.'}
          </p>
        </ConfirmDialog>
      )}
    </div>
  );
}
