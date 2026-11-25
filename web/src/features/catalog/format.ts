import type { Product } from './api';

export const STOCK_LABELS: Record<string, string> = {
  in_stock: 'In stock',
  low_stock: 'Low stock',
  out_of_stock: 'Out of stock',
  on_order: 'On order',
};

export const SEAL_LABELS: Record<string, string> = {
  open: 'Open',
  '2rs': '2RS (rubber sealed)',
  zz: 'ZZ (metal shielded)',
};

export const SORT_LABELS: Record<string, string> = {
  part_number: 'Part number',
  brand: 'Brand',
  inner_diameter: 'Inner diameter',
  outer_diameter: 'Outer diameter',
  width: 'Width',
  price: 'Price',
};

// Fixed en-US until the market is decided (docs/phase-2-design.md section 7.3).
export function formatPrice(p: Product, currency: string): string {
  if (p.price_on_request || p.price_cents === null) return 'Price on request';
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(
    p.price_cents / 100,
  );
}

export const formatSize = (p: Product) =>
  `${p.inner_diameter_mm} x ${p.outer_diameter_mm} x ${p.width_mm} mm`;
