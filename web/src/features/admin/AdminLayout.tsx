import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext';
import { ADMIN_NAV_ITEMS } from '../../nav';

/** Back-office frame. Hiding Users and Audit log is UX only: the API enforces the role. */
export default function AdminLayout() {
  const { user } = useAuth();
  const items = ADMIN_NAV_ITEMS.filter(
    (i) => user && i.roles.includes(user.role),
  );
  return (
    <div className="md:flex md:gap-8">
      <nav aria-label="Back office" className="mb-6 md:w-44 md:shrink-0">
        <ul className="flex flex-wrap gap-3 md:flex-col md:gap-1">
          {items.map((i) => (
            <li key={i.to}>
              <NavLink
                to={i.to}
                end={i.to === '/admin'}
                className={({ isActive }) =>
                  `block rounded px-2 py-1 ${
                    isActive
                      ? 'bg-slate-200 font-semibold'
                      : 'hover:bg-slate-100'
                  }`
                }
              >
                {i.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
      <div className="min-w-0 flex-1">
        <Outlet />
      </div>
    </div>
  );
}
