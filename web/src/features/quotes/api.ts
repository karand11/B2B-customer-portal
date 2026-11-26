import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query';
import { ApiError, api, describeError } from '../../lib/api';
import { toSearch, type QuoteQuery } from './urlState';

export type QuoteStatus =
  'submitted' | 'in_review' | 'quoted' | 'accepted' | 'declined' | 'expired';

export type QuoteAction =
  | 'accept'
  | 'decline'
  | 'attach'
  | 'review'
  | 'reject'
  | 'price'
  | 'send'
  | 'reopen'
  | 'note';

export interface Quote {
  number: string;
  status: QuoteStatus;
  currency: string;
  line_count: number;
  po_reference: string | null;
  subtotal_cents: number | null;
  tax_cents: number;
  shipping_cents: number;
  total_cents: number | null;
  valid_until: string | null;
  company: { id: number; name: string };
  requested_by: { name: string };
  created_at: string;
  updated_at: string;
}
export interface QuoteItem {
  position: number;
  kind: 'catalog' | 'custom';
  product_id: number | null;
  part_number: string | null;
  name: string;
  brand: string | null;
  quantity: number;
  unit_price_cents: number | null;
  line_total_cents: number | null;
}
export interface QuoteHistoryEntry {
  from_status: QuoteStatus | null;
  to_status: QuoteStatus;
  actor: { name: string | null; role: string };
  note: string | null;
  created_at: string;
}
export interface QuoteAttachment {
  id: string;
  name: string;
  mime: string;
  size_bytes: number;
  uploaded_by: { name: string | null; role: string };
  created_at: string;
}
export interface QuoteDetail extends Quote {
  customer_note: string | null;
  staff_note: string | null;
  /** Staff and admin only: the key does not exist in customer responses. */
  internal_note?: string | null;
  revision: number | null;
  expires_at: string | null;
  items: QuoteItem[];
  history: QuoteHistoryEntry[];
  attachments: QuoteAttachment[];
  order: { number: string } | null;
  allowed_actions: QuoteAction[];
}
export interface QuoteList {
  data: Quote[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}

export interface CreateQuoteBody {
  po_reference?: string;
  customer_note?: string;
  lines: (
    | { product_id: number; quantity: number }
    | { description: string; quantity: number }
  )[];
}

export const NUMBER_RE = /^[A-Za-z0-9-]{1,32}$/;
export const detailKey = (number: string) =>
  ['quotes', 'detail', number] as const;
const base = (number: string) => `/api/v1/quotes/${number}`;

export const STALE_REVISION =
  'This quote was updated. Please review the new prices.';

/** User-facing text for a failed quote call (422 messages, 413, 429, network). */
export function quoteErrorText(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 413) return 'The file is too large for the server.';
    if (err.status === 422) {
      if (err.errors.revision) return STALE_REVISION;
      const all = [...new Set(Object.values(err.errors).flat())];
      if (all.length) return all.join(' ');
    }
  }
  return describeError(err);
}

export const useQuotes = (query: QuoteQuery, enabled: boolean) => {
  const search = toSearch(query); // per_page omitted: API default is 20
  return useQuery({
    queryKey: ['quotes', 'list', search],
    queryFn: () => api.get<QuoteList>(`/api/v1/quotes${search}`),
    placeholderData: keepPreviousData,
    enabled,
  });
};

export const useQuote = (number: string, enabled: boolean) =>
  useQuery({
    queryKey: detailKey(number),
    queryFn: async () =>
      (await api.get<{ data: QuoteDetail }>(base(number))).data,
    enabled,
  });

export function useCreateQuote() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateQuoteBody) =>
      api.post<{ data: QuoteDetail }>('/api/v1/quotes', body),
    onSuccess: (res) => {
      qc.setQueryData(detailKey(res.data.number), res.data);
      void qc.invalidateQueries({ queryKey: ['quotes', 'list'] });
    },
  });
}

/** Every action returns the whole detail: store it as is. A 422 means our copy is stale. */
function useQuoteAction<V = void>(
  number: string,
  fn: (v: V) => Promise<{ data: QuoteDetail }>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: (res) => {
      qc.setQueryData(detailKey(number), res.data);
      void qc.invalidateQueries({ queryKey: ['quotes', 'list'] });
    },
    onError: (e) => {
      if (e instanceof ApiError && e.status === 422)
        void qc.invalidateQueries({ queryKey: detailKey(number) });
    },
  });
}

export const useReview = (n: string) =>
  useQuoteAction(n, () => api.post(`${base(n)}/review`));
export const useReopen = (n: string) =>
  useQuoteAction(n, () => api.post(`${base(n)}/reopen`));
export const useReject = (n: string) =>
  useQuoteAction(n, (reason: string) =>
    api.post(`${base(n)}/reject`, { reason }),
  );
export const useSend = (n: string) =>
  useQuoteAction(n, (staff_note: string | undefined) =>
    api.post(`${base(n)}/send`, staff_note ? { staff_note } : undefined),
  );
export const useSavePricing = (n: string) =>
  useQuoteAction(
    n,
    (body: {
      lines: { position: number; unit_price_cents: number | null }[];
      valid_until?: string | null;
    }) => api.put(`${base(n)}/pricing`, body),
  );
export const useSaveInternalNote = (n: string) =>
  useQuoteAction(n, (internal_note: string | null) =>
    api.put(`${base(n)}/internal-note`, { internal_note }),
  );
export const useDecline = (n: string) =>
  useQuoteAction(n, (reason: string | undefined) =>
    api.post(`${base(n)}/decline`, reason ? { reason } : undefined),
  );

export function useAccept(n: string) {
  const qc = useQueryClient();
  const m = useQuoteAction(
    n,
    (v: { revision: number; po_reference?: string }) =>
      api.post(`${base(n)}/accept`, v),
  );
  return {
    ...m,
    // The new order must show up in the orders list.
    mutateAsync: async (v: Parameters<typeof m.mutateAsync>[0]) => {
      const res = await m.mutateAsync(v);
      void qc.invalidateQueries({ queryKey: ['orders', 'list'] });
      return res;
    },
  };
}

/** One file per request, field `file`; never retried automatically. */
export function uploadAttachment(number: string, file: File) {
  const form = new FormData();
  form.append('file', file);
  return api.upload<{ data: QuoteAttachment }>(
    `${base(number)}/attachments`,
    form,
  );
}

export function useDeleteAttachment(number: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => api.delete(`${base(number)}/attachments/${id}`),
    onSettled: () => void qc.invalidateQueries({ queryKey: detailKey(number) }),
  });
}

export const attachmentUrl = (number: string, id: string) =>
  `${base(number)}/attachments/${id}`;
