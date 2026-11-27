import { Link, useNavigate } from 'react-router-dom';
import { useLinkParams } from '../lib/useLinkParams';
import NewPasswordForm from '../components/NewPasswordForm';
import { useAcceptInvitation } from '../features/admin/api';

const INVALID = 'This invitation link is invalid or has expired.';

/** Public page: the token and email come from the emailed link and are only ever posted. */
export default function AcceptInvitationPage() {
  const { token, email } = useLinkParams();
  const navigate = useNavigate();
  const accept = useAcceptInvitation();

  if (!token || !email)
    return (
      <section>
        <h1 className="mb-4 text-2xl font-semibold">Accept invitation</h1>
        <p role="alert" className="mb-4 text-red-800">
          {INVALID}
        </p>
        <Link to="/login" className="text-blue-800 underline">
          Go to sign in
        </Link>
      </section>
    );

  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">Accept invitation</h1>
      <p className="mb-4">
        Choose a password to finish setting up your account.
      </p>
      <NewPasswordForm
        onSubmit={(v) =>
          accept.mutate(
            { token, email, ...v },
            {
              onSuccess: (res) =>
                navigate('/login', {
                  replace: true,
                  state: { notice: res.message },
                }),
            },
          )
        }
        pending={accept.isPending}
        error={accept.error}
        idle="Set password"
        busy="Saving..."
      />
    </section>
  );
}
