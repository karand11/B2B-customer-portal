import { useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { useCart } from '../features/cart/api';
import { NAV_ITEMS, type NavItem } from '../nav';
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

function NavEntry({ item }: { item: NavItem }) {
  // Only the customer-only Cart item reads the cart (the query is disabled for other roles).
  const isCart = item.to === '/cart';
  const count = useCart().data?.line_count ?? 0;
  const badge = isCart && count > 0;
  return (
    <NavLink
      to={item.to}
      end={item.to === '/'}
      aria-label={
        badge ? `Cart, ${count} ${count === 1 ? 'item' : 'items'}` : undefined
      }
      className={({ isActive }) =>
        isActive ? 'font-semibold underline' : 'hover:underline'
      }
    >
      {item.label}
      {badge && (
        <span className="ml-1 rounded-full bg-blue-700 px-2 text-xs text-white">
          {count}
        </span>
      )}
    </NavLink>
  );
}

export default function Layout() {
  const { user } = useAuth();
  const wide = /^\/(catalog|orders|cart|checkout)/.test(useLocation().pathname);
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
                  <NavEntry item={i} />
                </li>
              ))}
            </ul>
          </nav>
        )}
        <UserBar />
      </header>
      <main
        className={`mx-auto w-full flex-1 p-8 ${wide ? 'max-w-7xl' : 'max-w-xl'}`}
      >
        <Outlet />
      </main>
      <footer className="border-t border-slate-300 px-6 py-3 text-sm">
        <HealthStatus />
      </footer>
    </div>
  );
}
