import { Link } from 'react-router-dom';
import { useLinkParams } from '../lib/useLinkParams';
import { useMutation } from '@tanstack/react-query';
import { api } from '../lib/api';
import NewPasswordForm, {
  type NewPasswordValues,
} from '../components/NewPasswordForm';

const INVALID = 'This password reset link is invalid or has expired.';

export default function ResetPasswordPage() {
  const { token, email } = useLinkParams();
  const mutation = useMutation({
    mutationFn: (v: NewPasswordValues) =>
      api.post('/api/v1/auth/reset-password', { token, email, ...v }),
  });

  if (!token || !email)
    return (
      <section>
        <h1 className="mb-4 text-2xl font-semibold">Reset password</h1>
        <p role="alert" className="mb-4 text-red-800">
          {INVALID}
        </p>
        <Link to="/forgot-password" className="text-blue-800 underline">
          Request a new link
        </Link>
      </section>
    );

  if (mutation.isSuccess)
    return (
      <section>
        <h1 className="mb-4 text-2xl font-semibold">Reset password</h1>
        <p role="status" aria-label="Password reset" className="mb-4">
          Your password has been reset. You can now sign in.
        </p>
        <Link to="/login" className="text-blue-800 underline">
          Sign in
        </Link>
      </section>
    );

  return (
    <section>
      <h1 className="mb-4 text-2xl font-semibold">Reset password</h1>
      <NewPasswordForm
        onSubmit={(v) => mutation.mutate(v)}
        pending={mutation.isPending}
        error={mutation.error}
        idle="Reset password"
        busy="Resetting..."
      />
    </section>
  );
}
