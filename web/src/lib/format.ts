// Fixed en-US until the market is decided (docs/phase-2-design.md section 7.3).
/** Money from integer cents; the currency always comes from the API body. */
export function formatMoney(cents: number | null, currency: string): string {
  if (cents === null) return '-';
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(
    cents / 100,
  );
}

export const formatDate = (iso: string) =>
  new Date(iso).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });

export const formatDateTime = (iso: string) =>
  new Date(iso).toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
