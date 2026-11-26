import type { InvoiceStatus } from './api';
import { STATUS_LABELS } from './urlState';

const COLOURS: Record<InvoiceStatus, string> = {
  open: 'border-blue-700 bg-blue-50 text-blue-900',
  overdue: 'border-red-800 bg-red-50 text-red-900',
  paid: 'border-green-700 bg-green-50 text-green-900',
  void: 'border-slate-600 bg-slate-100 text-slate-800',
};

/** Text first, colour second; overdue also carries a decorative warning icon. */
export default function StatusBadge({ status }: { status: InvoiceStatus }) {
  return (
    <span
      data-status={status}
      className={`inline-flex items-center gap-1 rounded border px-2 py-0.5 text-xs font-semibold ${COLOURS[status] ?? ''}`}
    >
      {status === 'overdue' && (
        <svg
          aria-hidden="true"
          focusable="false"
          viewBox="0 0 20 20"
          className="h-3.5 w-3.5"
          fill="currentColor"
        >
          <path d="M10 2 1 18h18L10 2Zm-1 6h2v5H9V8Zm0 6h2v2H9v-2Z" />
        </svg>
      )}
      {STATUS_LABELS[status] ?? status}
    </span>
  );
}
