import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query';
import { ApiError, api } from '../../lib/api';

export interface Meta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}
export interface Paged<T> {
  data: T[];
  meta: Meta;
}

// ---- dashboard
export interface Dashboard {
  generated_at: string;
  week_start: string;
  currency: string;
  quotes: { open: number; awaiting_customer: number; expiring_soon: number };
  orders: { this_week: number; pending: number };
  invoices: {
    overdue_count: number;
    overdue_total_cents: number;
    due_soon_count: number;
    due_soon_total_cents: number;
  };
  products: { inactive: number };
}

// ---- products
export interface AdminProduct {
  id: number;
  part_number: string;
  name: string;
  /** Not in list items. */
  description?: string | null;
  brand: string;
  category: { id: number; slug: string; name: string };
  inner_diameter_mm: number;
  outer_diameter_mm: number;
  width_mm: number;
  material: string;
  seal_type: 'open' | '2rs' | 'zz';
  price_cents: number | null;
  price_on_request: boolean;
  stock_status: 'in_stock' | 'low_stock' | 'out_of_stock' | 'on_order';
  active: boolean;
  version: number;
  created_at: string;
  updated_at: string;
}
/** Body of POST and PUT /admin/products (PUT adds `version`, never `active`). */
export interface ProductInput {
  category_id: number;
  part_number: string;
  name: string;
  description: string | null;
  brand: string;
  material: string;
  inner_diameter_mm: number;
  outer_diameter_mm: number;
  width_mm: number;
  seal_type: string;
  stock_status: string;
  price_on_request: boolean;
  price_cents: number | null;
}

export interface ImportPreview {
  id: string;
  file_name: string;
  size_bytes: number;
  status: 'previewed';
  expires_at: string;
  rows_read: number;
  would_create: number;
  would_update: number;
  bad_rows: number;
  errors: { line: number; messages: string[] }[];
  errors_truncated: boolean;
  warnings: string[];
}
export interface ImportResult {
  id: string;
  status: 'applied';
  rows_read: number;
  created: number;
  updated: number;
  bad_rows: number;
}

// ---- companies
export interface CompanyAdmin {
  id: number;
  name: string;
  tax_id: string | null;
  has_billing_address: boolean;
  version: number;
  users_count: number;
  open_orders_count: number;
  open_quotes_count: number;
  unpaid_invoices_count: number;
  created_at: string;
}
export interface CompanyDetail extends CompanyAdmin {
  billing_address: string | null;
  users: {
    id: number;
    name: string;
    email: string;
    role: string;
    status: string;
  }[];
  recent: {
    orders: {
      number: string;
      status: string;
      total_cents: number;
      currency: string;
      created_at: string;
    }[];
    quotes: { number: string; status: string; created_at: string }[];
    invoices: {
      id: string;
      number: string;
      status: string;
      total_cents: number;
      currency: string;
      due_date: string;
    }[];
  };
}

// ---- users
export type UserRole = 'customer' | 'staff' | 'admin';
export type UserStatus = 'pending' | 'active' | 'inactive';
export type UserAction =
  | 'edit'
  | 'assign'
  | 'deactivate'
  | 'reactivate'
  | 'reset_password'
  | 'resend_invitation'
  | 'revoke_invitation';
export interface UserAdmin {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  company: { id: number; name: string } | null;
  status: UserStatus;
  invited_at: string | null;
  invitation_accepted_at: string | null;
  created_at: string;
  allowed_actions: UserAction[];
}

// ---- audit log
export interface AuditEntry {
  id: number;
  occurred_at: string;
  action: string;
  actor: { id: number; name: string; role: string } | null;
  subject: { type: string; id: string; label: string | null };
  company: { id: number; name: string } | null;
  changes: {
    before?: Record<string, unknown>;
    after?: Record<string, unknown>;
  };
  metadata: Record<string, unknown>;
}

export const isConflict = (e: unknown) =>
  e instanceof ApiError && e.status === 409;

// ---- queries
function useAdminList<T>(
  scope: string,
  path: string,
  search: string,
  enabled = true,
) {
  return useQuery({
    queryKey: ['admin', scope, 'list', search],
    queryFn: () => api.get<Paged<T>>(`/api/v1/admin/${path}${search}`),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export const useDashboard = () =>
  useQuery({
    queryKey: ['admin', 'dashboard'],
    queryFn: async () =>
      (await api.get<{ data: Dashboard }>('/api/v1/admin/dashboard')).data,
  });

export const useAdminProducts = (search: string) =>
  useAdminList<AdminProduct>('products', 'products', search);
export const useAdminCompanies = (search: string, enabled = true) =>
  useAdminList<CompanyAdmin>('companies', 'companies', search, enabled);
export const useAdminUsers = (search: string) =>
  useAdminList<UserAdmin>('users', 'users', search);
export const useAuditLog = (search: string, enabled = true) =>
  useAdminList<AuditEntry>('audit', 'audit-log', search, enabled);

export const useAdminProduct = (id: string, enabled: boolean) =>
  useQuery({
    queryKey: ['admin', 'products', 'detail', id],
    queryFn: async () =>
      (await api.get<{ data: AdminProduct }>(`/api/v1/admin/products/${id}`))
        .data,
    enabled,
  });
export const useAdminCompany = (id: string, enabled: boolean) =>
  useQuery({
    queryKey: ['admin', 'companies', 'detail', id],
    queryFn: async () =>
      (await api.get<{ data: CompanyDetail }>(`/api/v1/admin/companies/${id}`))
        .data,
    enabled,
  });
export const useAdminUser = (id: string, enabled: boolean) =>
  useQuery({
    queryKey: ['admin', 'users', 'detail', id],
    queryFn: async () =>
      (await api.get<{ data: UserAdmin }>(`/api/v1/admin/users/${id}`)).data,
    enabled,
  });

// ---- mutations
/** Invalidates a whole admin scope so lists and details refetch after a write. */
function useScopedMutation<A, R>(
  scope: string,
  fn: (a: A) => Promise<R>,
  extraScopes: string[] = [],
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      for (const s of [scope, ...extraScopes])
        void qc.invalidateQueries({ queryKey: ['admin', s] });
      // Catalog pages read the same rows.
      if (scope === 'products')
        void qc.invalidateQueries({ queryKey: ['catalog'] });
    },
  });
}

export const useCreateProduct = () =>
  useScopedMutation('products', (v: ProductInput) =>
    api.post<{ data: AdminProduct }>('/api/v1/admin/products', v),
  );
export const useUpdateProduct = (id: string) =>
  useScopedMutation('products', (v: ProductInput & { version: number }) =>
    api.put<{ data: AdminProduct }>(`/api/v1/admin/products/${id}`, v),
  );
export const useSetProductActive = (id: string) =>
  useScopedMutation('products', (active: boolean) =>
    api.post<{ data: AdminProduct }>(
      `/api/v1/admin/products/${id}/${active ? 'activate' : 'deactivate'}`,
    ),
  );

export const usePreviewImport = () =>
  useMutation({
    mutationFn: (file: File) => {
      const form = new FormData();
      form.append('file', file);
      return api.upload<{ data: ImportPreview }>(
        '/api/v1/admin/products/imports',
        form,
      );
    },
  });
export const useApplyImport = (id: string) =>
  useScopedMutation('products', (skip_invalid: boolean) =>
    api.post<{ data: ImportResult }>(
      `/api/v1/admin/products/imports/${id}/apply`,
      { skip_invalid },
    ),
  );

export interface CompanyInput {
  name: string;
  billing_address: string | null;
  tax_id: string | null;
}
export const useCreateCompany = () =>
  useScopedMutation('companies', (v: CompanyInput) =>
    api.post<{ data: CompanyDetail }>('/api/v1/admin/companies', v),
  );
export const useUpdateCompany = (id: string) =>
  useScopedMutation(
    'companies',
    (v: CompanyInput & { version: number }) =>
      api.put<{ data: CompanyDetail }>(`/api/v1/admin/companies/${id}`, v),
    ['users'],
  );
export const useUpdateBilling = (id: string) =>
  useScopedMutation(
    'companies',
    (v: Omit<CompanyInput, 'name'> & { version: number }) =>
      api.put<{ data: CompanyDetail }>(
        `/api/v1/admin/companies/${id}/billing`,
        v,
      ),
  );

export interface InviteInput {
  name: string;
  email: string;
  role: UserRole;
  company_id: number | null;
}
export interface UserWithMeta {
  data: UserAdmin;
  meta?: { email_queued: boolean };
}
export const useInviteUser = () =>
  useScopedMutation('users', (v: InviteInput) =>
    api.post<UserWithMeta>('/api/v1/admin/users', v),
  );
export const useRenameUser = (id: number) =>
  useScopedMutation('users', (name: string) =>
    api.patch<UserWithMeta>(`/api/v1/admin/users/${id}`, { name }),
  );
export const useAssignUser = (id: number) =>
  useScopedMutation(
    'users',
    (v: { role: UserRole; company_id: number | null }) =>
      api.put<UserWithMeta>(`/api/v1/admin/users/${id}/assignment`, v),
    ['companies'],
  );
export type UserCommand =
  'deactivate' | 'reactivate' | 'password-reset' | 'resend' | 'revoke';
export const useUserCommand = (id: number) =>
  useScopedMutation('users', (cmd: UserCommand) => {
    const base = `/api/v1/admin/users/${id}`;
    switch (cmd) {
      case 'resend':
        return api.post<UserWithMeta | { message: string }>(
          `${base}/invitation`,
        );
      case 'revoke':
        return api.delete<UserWithMeta | { message: string }>(
          `${base}/invitation`,
        );
      default:
        return api.post<UserWithMeta | { message: string }>(`${base}/${cmd}`);
    }
  });

export const useAcceptInvitation = () =>
  useMutation({
    mutationFn: (v: {
      token: string;
      email: string;
      password: string;
      password_confirmation: string;
    }) => api.post<{ message: string }>('/api/v1/auth/accept-invitation', v),
  });
