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
  { label: 'Orders', to: '/orders', roles: ['customer', 'staff', 'admin'] },
  { label: 'Quotes', to: '/quotes', roles: ['customer', 'staff', 'admin'] },
  { label: 'Cart', to: '/cart', roles: ['customer'] },
  { label: 'Staff area', to: '/staff', roles: ['staff', 'admin'] },
  { label: 'Admin area', to: '/admin', roles: ['admin'] },
];
