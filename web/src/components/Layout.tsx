import { useState } from 'react';
import { Link, NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { NAV_ITEMS } from '../nav';
import HealthStatus from '../HealthStatus';

function UserBar() {
  const { user, logout } = useAuth();
  const [failed, setFailed] = useState(false);
  if (!user) return null;
  return (
    <div className="flex items-center gap-3 text-sm">
      <span className="font-medium">{user.name}</span>
      <span>{user.role}</span>
      {user.company && <span data-testid="company">{user.company.name}</span>}
      <button
        type="button"
        onClick={() => {
          setFailed(false);
          logout().catch(() => setFailed(true));
        }}
        className="rounded border border-slate-600 px-2 py-1"
      >
        Log out
      </button>
      {failed && (
        <span role="alert" className="text-red-700">
          Could not log out. Try again.
        </span>
      )}
    </div>
  );
}

export default function Layout() {
  const { user } = useAuth();
  const items = user
    ? NAV_ITEMS.filter((i) => i.roles.includes(user.role))
    : [];
  return (
    <div className="flex min-h-screen flex-col">
      <header className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-300 px-6 py-3">
        <Link to="/" className="text-lg font-semibold">
          B2B Customer Portal
        </Link>
        {items.length > 0 && (
          <nav aria-label="Main">
            <ul className="flex gap-4">
              {items.map((i) => (
                <li key={i.to}>
                  <NavLink
                    to={i.to}
                    end
                    className={({ isActive }) =>
                      isActive ? 'font-semibold underline' : 'hover:underline'
                    }
                  >
                    {i.label}
                  </NavLink>
                </li>
              ))}
            </ul>
          </nav>
        )}
        <UserBar />
      </header>
      <main className="mx-auto w-full max-w-xl flex-1 p-8">
        <Outlet />
      </main>
      <footer className="border-t border-slate-300 px-6 py-3 text-sm">
        <HealthStatus />
      </footer>
    </div>
  );
}
