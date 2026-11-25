import type { OrderStatus } from './api';
import { STATUS_LABELS } from './urlState';

const COLOURS: Record<OrderStatus, string> = {
  pending: 'border-amber-700 bg-amber-50 text-amber-900',
  confirmed: 'border-blue-700 bg-blue-50 text-blue-900',
  shipped: 'border-indigo-700 bg-indigo-50 text-indigo-900',
  delivered: 'border-green-700 bg-green-50 text-green-900',
  cancelled: 'border-slate-600 bg-slate-100 text-slate-800',
};

/** The text carries the meaning; colour is decoration. */
export default function StatusBadge({ status }: { status: OrderStatus }) {
  return (
    <span
      className={`inline-block rounded border px-2 py-0.5 text-xs font-semibold ${COLOURS[status] ?? ''}`}
    >
      {STATUS_LABELS[status] ?? status}
    </span>
  );
}
