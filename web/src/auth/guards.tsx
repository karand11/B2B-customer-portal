import {
  Navigate,
  Outlet,
  useLocation,
  useSearchParams,
} from 'react-router-dom';
import { safeNext } from '../lib/api';
import { useAuth } from './AuthContext';
import type { Role } from './types';
import ForbiddenPage from '../pages/ForbiddenPage';

/** Shared loading / error handling while /me is unresolved. Returns null when ready. */
function useGate() {
  const { status, retry } = useAuth();
  if (status === 'pending')
    return (
      <p role="status" aria-label="Loading" className="p-8 text-slate-700">
        Loading...
      </p>
    );
  if (status === 'error')
    return (
      <div role="alert" className="p-8 text-red-800">
        <p>Could not load your session. Please try again.</p>
        <button
          type="button"
          onClick={retry}
          className="mt-2 rounded bg-blue-700 px-3 py-1 text-white"
        >
          Retry
        </button>
      </div>
    );
  return null;
}

// Route guards are UX only; the API enforces auth and roles on every call.
export function ProtectedRoute() {
  const { user } = useAuth();
  const location = useLocation();
  const gate = useGate();
  if (gate) return gate;
  if (!user) {
    const next = encodeURIComponent(location.pathname + location.search);
    return <Navigate to={`/login?next=${next}`} replace />;
  }
  return <Outlet />;
}

export function RoleRoute({ roles }: { roles: Role[] }) {
  const { user } = useAuth();
  if (!user || !roles.includes(user.role)) return <ForbiddenPage />;
  return <Outlet />;
}

export function GuestRoute() {
  const { user } = useAuth();
  const [params] = useSearchParams();
  const gate = useGate();
  if (gate) return gate;
  if (user) return <Navigate to={safeNext(params.get('next'))} replace />;
  return <Outlet />;
}
