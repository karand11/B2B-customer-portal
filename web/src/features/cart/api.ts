import {
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query';
import { z } from 'zod';
import { useAuth } from '../../auth/AuthContext';
import { api } from '../../lib/api';

export type UnavailableReason =
  'inactive' | 'price_on_request' | 'out_of_stock';

export interface CartLine {
  product_id: number;
  part_number: string;
  name: string;
  brand: string;
  stock_status: string;
  unit_price_cents: number | null;
  quantity: number;
  line_total_cents: number | null;
  available: boolean;
  unavailable_reason: UnavailableReason | null;
}
export interface Cart {
  currency: string;
  items: CartLine[];
  line_count: number;
  unit_count: number;
  subtotal_cents: number;
  can_checkout: boolean;
}

export const CART_KEY = ['cart'] as const;

export const emptyCart = (currency: string): Cart => ({
  currency,
  items: [],
  line_count: 0,
  unit_count: 0,
  subtotal_cents: 0,
  can_checkout: false,
});

/** Quantity rules mirror the API (integer 1 to 9999); the server stays the authority. */
const quantitySchema = z
  .string()
  .trim()
  .regex(/^\d{1,4}$/)
  .transform(Number)
  .pipe(z.number().int().min(1).max(9999));
export const QUANTITY_ERROR = 'Enter a whole number from 1 to 9999.';
export function parseQuantity(text: string): number | null {
  const r = quantitySchema.safeParse(text);
  return r.success ? r.data : null;
}

/** The cart exists for customers only; other roles never call the API. */
export function useCart() {
  const { user } = useAuth();
  return useQuery({
    queryKey: CART_KEY,
    queryFn: async () => (await api.get<{ data: Cart }>('/api/v1/cart')).data,
    enabled: user?.role === 'customer',
  });
}

/** Every cart endpoint returns the whole cart: store it as is, no optimistic updates. */
function useCartMutation<V>(fn: (v: V) => Promise<{ data: Cart }>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: (res) => qc.setQueryData(CART_KEY, res.data),
  });
}

export const useAddToCart = () =>
  useCartMutation((v: { product_id: number; quantity: number }) =>
    api.post('/api/v1/cart/items', v),
  );
export const useUpdateCartItem = () =>
  useCartMutation((v: { product_id: number; quantity: number }) =>
    api.patch(`/api/v1/cart/items/${v.product_id}`, { quantity: v.quantity }),
  );
export const useRemoveCartItem = () =>
  useCartMutation((product_id: number) =>
    api.delete(`/api/v1/cart/items/${product_id}`),
  );
export const useClearCart = () =>
  useCartMutation<void>(() => api.delete('/api/v1/cart'));

export function resetCart(qc: QueryClient, currency: string) {
  qc.setQueryData(CART_KEY, emptyCart(currency));
}
