/** Cart unavailable reasons and reorder skip reasons share one label table. */
export const REASON_LABELS: Record<string, string> = {
  inactive: 'No longer available',
  price_on_request: 'Price on request',
  out_of_stock: 'Out of stock',
  cart_full: 'Cart is full',
  quantity_limit: 'Quantity limit reached',
};
