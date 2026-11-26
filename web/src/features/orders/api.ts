import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query';
import { api } from '../../lib/api';
import { CART_KEY, type Cart } from '../cart/api';
import { toSearch, type OrderQuery } from './urlState';

export type OrderStatus =
  'pending' | 'confirmed' | 'shipped' | 'delivered' | 'cancelled';

export interface Order {
  number: string;
  status: OrderStatus;
  currency: string;
  subtotal_cents: number;
  tax_cents: number;
  shipping_cents: number;
  total_cents: number;
  line_count: number;
  po_reference: string | null;
  /** Set when the order was created by accepting a quote. */
  quote_number: string | null;
  company: { id: number; name: string };
  placed_by: { name: string };
  created_at: string;
  updated_at: string;
}
/** Custom quote lines have no product: all three catalog fields are null. */
export interface OrderItem {
  product_id: number | null;
  part_number: string | null;
  name: string;
  brand: string | null;
  unit_price_cents: number;
  quantity: number;
  line_total_cents: number;
}
export interface HistoryEntry {
  from_status: OrderStatus | null;
  to_status: OrderStatus;
  actor: { name: string | null; role: string };
  created_at: string;
}
export interface OrderDetail extends Order {
  items: OrderItem[];
  history: HistoryEntry[];
  allowed_transitions: OrderStatus[];
}
export interface OrderList {
  data: Order[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}
export interface ReorderResult {
  cart: Cart;
  added: { product_id: number; part_number: string; quantity: number }[];
  skipped: {
    product_id: number | null;
    part_number: string | null;
    name: string;
    reason: string;
  }[];
}

export const NUMBER_RE = /^[A-Za-z0-9-]{1,32}$/;
const detailKey = (number: string) => ['orders', 'detail', number] as const;

export const useOrders = (query: OrderQuery, enabled: boolean) => {
  const search = toSearch(query); // per_page omitted: API default is 20
  return useQuery({
    queryKey: ['orders', 'list', search],
    queryFn: () => api.get<OrderList>(`/api/v1/orders${search}`),
    placeholderData: keepPreviousData,
    enabled,
  });
};

export const useOrder = (number: string, enabled: boolean) =>
  useQuery({
    queryKey: detailKey(number),
    queryFn: async () =>
      (await api.get<{ data: OrderDetail }>(`/api/v1/orders/${number}`)).data,
    enabled,
  });

export function usePlaceOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (po_reference: string | undefined) =>
      api.post<{ data: OrderDetail }>(
        '/api/v1/orders',
        po_reference ? { po_reference } : undefined,
      ),
    onSuccess: (res) => {
      qc.setQueryData(detailKey(res.data.number), res.data);
      void qc.invalidateQueries({ queryKey: ['orders', 'list'] });
    },
    // A 422 means the server's cart differs from what the user saw.
    onError: () => void qc.invalidateQueries({ queryKey: CART_KEY }),
  });
}

export function useCancelOrder(number: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () =>
      api.post<{ data: OrderDetail }>(`/api/v1/orders/${number}/cancel`),
    onSuccess: (res) => {
      qc.setQueryData(detailKey(number), res.data);
      void qc.invalidateQueries({ queryKey: ['orders', 'list'] });
    },
    onError: () => void qc.invalidateQueries({ queryKey: detailKey(number) }),
  });
}

export function useReorder(number: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () =>
      api.post<{ data: ReorderResult }>(`/api/v1/orders/${number}/reorder`),
    onSuccess: (res) => qc.setQueryData(CART_KEY, res.data.cart),
  });
}
