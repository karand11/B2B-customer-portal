import type { Role } from './auth/types';

export interface NavItem {
  label: string;
  to: string;
  roles: Role[];
}

// Single source for navigation; filtered by role. Hiding is UX only.
export const NAV_ITEMS: NavItem[] = [
  { label: 'Home', to: '/', roles: ['customer', 'staff', 'admin'] },
  { label: 'Catalog', to: '/catalog', roles: ['customer', 'staff', 'admin'] },
  { label: 'Orders', to: '/orders', roles: ['customer'] },
  { label: 'Quotes', to: '/quotes', roles: ['customer'] },
  { label: 'Invoices', to: '/invoices', roles: ['customer'] },
  { label: 'Cart', to: '/cart', roles: ['customer'] },
  { label: 'Back office', to: '/admin', roles: ['staff', 'admin'] },
];

// Back-office navigation. Users and Audit log are admin-only (the API enforces it).
export const ADMIN_NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', to: '/admin', roles: ['staff', 'admin'] },
  { label: 'Orders', to: '/admin/orders', roles: ['staff', 'admin'] },
  { label: 'Quotes', to: '/admin/quotes', roles: ['staff', 'admin'] },
  { label: 'Invoices', to: '/admin/invoices', roles: ['staff', 'admin'] },
  { label: 'Products', to: '/admin/products', roles: ['staff', 'admin'] },
  { label: 'Companies', to: '/admin/companies', roles: ['staff', 'admin'] },
  { label: 'Users', to: '/admin/users', roles: ['admin'] },
  { label: 'Audit log', to: '/admin/audit-log', roles: ['admin'] },
];
