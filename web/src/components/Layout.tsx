import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { useCart } from '../features/cart/api';
import { NAV_ITEMS, type NavItem } from '../nav';
import HealthStatus from '../HealthStatus';
import RouteBoundary from './RouteBoundary';
import { PageSettledContext } from './pageFocus';

const APP_NAME = 'B2B Customer Portal';

/**
 * Per-route accessibility chores for every page, whatever it renders:
 * - document.title follows the page's h1 ("Orders - B2B Customer Portal");
 * - after a client-side navigation the new title is announced in a polite live region
 *   and, if focus is still on the old link (outside main), moves to the h1.
 * The first load is not announced (the browser does that) and focus is left alone.
 */
function useRouteEffects() {
  const mainRef = useRef<HTMLElement>(null);
  const announcerRef = useRef<HTMLDivElement>(null);
  const { pathname } = useLocation();
  const last = useRef<string | null>(null);
  // False until the first page has rendered: no focus moves during a full page load.
  const settled = useRef(false);
  const [settledState, setSettledState] = useState(false);
  useEffect(() => {
    const root = mainRef.current;
    if (!root) return;
    // Announce and focus once per navigation (also immune to StrictMode's effect re-run).
    let pending =
      settled.current && last.current !== null && last.current !== pathname;
    last.current = pathname;
    const sync = () => {
      const h1 = root.querySelector('h1');
      const text = h1?.textContent?.trim();
      document.title = text ? `${text} - ${APP_NAME}` : APP_NAME;
      if (h1 && text && !settled.current) {
        settled.current = true;
        setSettledState(true);
      }
      if (!pending || !h1 || !text) return;
      pending = false;
      if (announcerRef.current) announcerRef.current.textContent = text;
      const active = document.activeElement;
      if (
        !active ||
        active === document.body ||
        !root.contains(active) ||
        active === root
      ) {
        h1.setAttribute('tabindex', '-1');
        h1.focus();
      }
    };
    sync();
    // Lazy routes and data loading add the h1 later.
    const observer = new MutationObserver(sync);
    observer.observe(root, {
      childList: true,
      subtree: true,
      characterData: true,
    });
    return () => observer.disconnect();
  }, [pathname]);
  return { mainRef, announcerRef, settled: settledState };
}

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
      className={({ isActive }) =>
        isActive ? 'font-semibold underline' : 'hover:underline'
      }
    >
      {item.label}
      {badge && (
        <>
          {' '}
          <span className="ml-1 rounded-full bg-blue-700 px-2 text-xs text-white">
            {count}{' '}
            <span className="sr-only">{count === 1 ? 'item' : 'items'}</span>
          </span>
        </>
      )}
    </NavLink>
  );
}

export default function Layout() {
  const { user } = useAuth();
  const wide = /^\/(catalog|orders|quotes|invoices|cart|checkout|admin)/.test(
    useLocation().pathname,
  );
  const items = user
    ? NAV_ITEMS.filter((i) => i.roles.includes(user.role))
    : [];
  const { mainRef, announcerRef, settled } = useRouteEffects();
  return (
    <div className="flex min-h-screen flex-col">
      <a
        href="#main-content"
        onClick={(e) => {
          e.preventDefault(); // a hash link would add a history entry and may not move focus
          mainRef.current?.focus();
        }}
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-20 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:text-blue-900"
      >
        Skip to main content
      </a>
      <header className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-300 px-6 py-3">
        <Link to="/" className="text-lg font-semibold">
          B2B Customer Portal
        </Link>
        {items.length > 0 && (
          <nav aria-label="Main">
            <ul className="flex flex-wrap gap-x-4 gap-y-1">
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
        id="main-content"
        ref={mainRef}
        tabIndex={-1}
        className={`mx-auto w-full flex-1 p-8 outline-none ${wide ? 'max-w-7xl' : 'max-w-xl'}`}
      >
        <PageSettledContext value={settled}>
          <RouteBoundary>
            <Outlet />
          </RouteBoundary>
        </PageSettledContext>
      </main>
      <div
        ref={announcerRef}
        role="status"
        aria-live="polite"
        aria-label="Page change announcement"
        className="sr-only"
      />
      <footer className="border-t border-slate-300 px-6 py-3 text-sm">
        <HealthStatus />
      </footer>
    </div>
  );
}
