import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query';
import { api } from '../../lib/api';
import { detailKey as orderDetailKey } from '../orders/api';
import { toSearch, type InvoiceQuery } from './urlState';

export type InvoiceStatus = 'open' | 'overdue' | 'paid' | 'void';

export interface Invoice {
  id: string; // random UUID, the only URL identifier
  number: string;
  status: InvoiceStatus; // effective status
  currency: string;
  subtotal_cents: number;
  vat_rate_bp: number;
  tax_cents: number;
  total_cents: number;
  issue_date: string; // YYYY-MM-DD, never parsed as a Date
  due_date: string;
  paid_on: string | null;
  days_overdue: number;
  order_number: string;
  po_reference: string | null;
  line_count: number;
  company: { id: number; name: string };
  created_at: string;
}
/** Free-text quote lines have null part number and brand. */
export interface InvoiceItem {
  position: number;
  part_number: string | null;
  name: string;
  brand: string | null;
  quantity: number;
  unit_price_cents: number;
  line_total_cents: number;
}
export interface InvoiceHistoryEntry {
  from_status: InvoiceStatus | null;
  to_status: InvoiceStatus;
  actor: { name: string | null; role: string };
  note: string | null; // void reason: staff only (null for customers)
  created_at: string;
}
export interface Seller {
  name: string;
  address_lines: string[];
  tax_id: string | null;
  email: string | null;
  phone: string | null;
  bank: {
    account_holder: string | null;
    bank_name: string | null;
    iban: string | null;
    bic: string | null;
  };
  payment_terms_text: string | null;
  footer: string | null;
  vat_label: string;
}
export interface Buyer {
  name: string;
  address_lines: string[];
  tax_id: string | null;
}
export interface InvoiceDetail extends Invoice {
  seller: Seller;
  buyer: Buyer;
  items: InvoiceItem[];
  history: InvoiceHistoryEntry[];
  allowed_actions: string[];
}
export interface InvoiceList {
  data: Invoice[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}

/** Lowercase UUID v4 shape, as enforced by the API route pattern. */
export const ID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
export const detailKey = (id: string) => ['invoices', 'detail', id] as const;

export const useInvoices = (query: InvoiceQuery, enabled: boolean) => {
  const search = toSearch(query); // per_page omitted: API default is 20
  return useQuery({
    queryKey: ['invoices', 'list', search],
    queryFn: () => api.get<InvoiceList>(`/api/v1/invoices${search}`),
    placeholderData: keepPreviousData,
    enabled,
  });
};

export const useInvoice = (id: string, enabled: boolean) =>
  useQuery({
    queryKey: detailKey(id),
    queryFn: async () =>
      (await api.get<{ data: InvoiceDetail }>(`/api/v1/invoices/${id}`)).data,
    enabled,
  });

/** After any write: show the fresh invoice, refresh lists and the order page. */
function useAfterWrite() {
  const qc = useQueryClient();
  return {
    ok: (inv: InvoiceDetail) => {
      qc.setQueryData(detailKey(inv.id), inv);
      void qc.invalidateQueries({ queryKey: ['invoices', 'list'] });
      void qc.invalidateQueries({ queryKey: orderDetailKey(inv.order_number) });
      void qc.invalidateQueries({ queryKey: ['orders', 'list'] });
    },
    // A refused write usually means the server state differs from the screen.
    stale: (key: readonly unknown[]) =>
      void qc.invalidateQueries({ queryKey: key }),
  };
}

export function useCreateInvoice(orderNumber: string) {
  const w = useAfterWrite();
  return useMutation({
    mutationFn: (body: { due_date?: string }) =>
      api.post<{ data: InvoiceDetail }>(
        `/api/v1/orders/${orderNumber}/invoice`,
        body,
      ),
    onSuccess: (res) => w.ok(res.data),
    onError: () => w.stale(orderDetailKey(orderNumber)),
  });
}

export function useMarkPaid(id: string) {
  const w = useAfterWrite();
  return useMutation({
    mutationFn: (body: { paid_on: string }) =>
      api.post<{ data: InvoiceDetail }>(
        `/api/v1/invoices/${id}/mark-paid`,
        body,
      ),
    onSuccess: (res) => w.ok(res.data),
    onError: () => w.stale(detailKey(id)),
  });
}

export function useVoidInvoice(id: string) {
  const w = useAfterWrite();
  return useMutation({
    mutationFn: (body: { reason: string }) =>
      api.post<{ data: InvoiceDetail }>(`/api/v1/invoices/${id}/void`, body),
    onSuccess: (res) => w.ok(res.data),
    onError: () => w.stale(detailKey(id)),
  });
}
