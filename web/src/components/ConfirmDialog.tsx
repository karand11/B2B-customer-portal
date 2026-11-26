import { useEffect, useId, useRef, type ReactNode } from 'react';

const FOCUSABLE =
  'button:not([disabled]), a[href], input:not([disabled]), textarea:not([disabled]), select:not([disabled])';

/**
 * Modal confirm: focus moves in (to the safe "keep" button), Tab is trapped,
 * Escape cancels, focus returns to whatever opened it.
 */
export default function ConfirmDialog({
  title,
  children,
  confirmLabel,
  cancelLabel,
  pending = false,
  tone = 'danger',
  onConfirm,
  onCancel,
}: {
  title: string;
  children?: ReactNode;
  confirmLabel: string;
  cancelLabel: string;
  pending?: boolean;
  /** Red for destructive confirms, blue for the rest. */
  tone?: 'danger' | 'primary';
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const cancelRef = useRef<HTMLButtonElement>(null);
  const titleId = useId();

  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null;
    cancelRef.current?.focus();
    return () => opener?.focus();
  }, []);

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') {
      e.stopPropagation();
      onCancel();
      return;
    }
    if (e.key !== 'Tab') return;
    const items = ref.current?.querySelectorAll<HTMLElement>(FOCUSABLE);
    if (!items?.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  };

  return (
    <div className="fixed inset-0 z-10 flex items-center justify-center bg-black/50 p-4">
      <div
        ref={ref}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        onKeyDown={onKeyDown}
        className="w-full max-w-md rounded bg-white p-6 shadow-lg"
      >
        <h2 id={titleId} className="mb-2 text-lg font-semibold">
          {title}
        </h2>
        <div className="mb-4">{children}</div>
        <div className="flex gap-2">
          <button
            type="button"
            disabled={pending}
            onClick={onConfirm}
            className={`rounded px-3 py-1 text-white disabled:opacity-60 ${
              tone === 'danger' ? 'bg-red-700' : 'bg-blue-700'
            }`}
          >
            {confirmLabel}
          </button>
          <button
            type="button"
            ref={cancelRef}
            onClick={onCancel}
            className="rounded border border-slate-600 px-3 py-1"
          >
            {cancelLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
