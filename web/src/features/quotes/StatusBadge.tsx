import { STATUS_LABELS } from './urlState';

const COLOURS: Record<string, string> = {
  submitted: 'border-amber-700 bg-amber-50 text-amber-900',
  in_review: 'border-blue-700 bg-blue-50 text-blue-900',
  quoted: 'border-indigo-700 bg-indigo-50 text-indigo-900',
  accepted: 'border-green-700 bg-green-50 text-green-900',
  declined: 'border-red-700 bg-red-50 text-red-900',
  expired: 'border-slate-600 bg-slate-100 text-slate-800',
};

/**
 * The text carries the meaning; colour is decoration. The API already returns
 * the EFFECTIVE status (a quoted quote past its date arrives as "expired").
 * An unknown status shows its raw text in neutral colours.
 */
export default function StatusBadge({ status }: { status: string }) {
  const known = Object.hasOwn(STATUS_LABELS, status);
  return (
    <span
      data-status={known ? status : 'unknown'}
      className={`inline-block rounded border px-2 py-0.5 text-xs font-semibold ${
        (known && COLOURS[status]) || 'border-slate-600 bg-white text-slate-800'
      }`}
    >
      {known ? STATUS_LABELS[status as keyof typeof STATUS_LABELS] : status}
    </span>
  );
}
