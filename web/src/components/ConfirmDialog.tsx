import { useEffect, useId, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

const FOCUSABLE =
  'button:not([disabled]), a[href], input:not([disabled]):not([type="hidden"]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Modal confirm: rendered in a portal on document.body, everything else is made
 * `inert` while it is open (no focus, clicks or screen reader access behind it),
 * focus moves in (to the safe "keep" button), Tab is trapped, Escape cancels and
 * focus returns to whatever opened it.
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
  const overlayRef = useRef<HTMLDivElement>(null);
  const cancelRef = useRef<HTMLButtonElement>(null);
  const titleId = useId();
  const bodyId = useId();

  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null;
    const silenced: Element[] = [];
    for (const el of document.body.children) {
      if (el === overlayRef.current || el.hasAttribute('inert')) continue;
      el.setAttribute('inert', '');
      silenced.push(el);
    }
    cancelRef.current?.focus();
    return () => {
      // Order matters: an inert opener cannot take focus back.
      silenced.forEach((el) => el.removeAttribute('inert'));
      opener?.focus();
    };
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
    if (!Array.from(items).includes(document.activeElement as HTMLElement)) {
      // Focus is on the dialog box itself: enter the loop from the right end.
      e.preventDefault();
      (e.shiftKey ? last : first).focus();
    } else if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  };

  return createPortal(
    <div
      ref={overlayRef}
      className="fixed inset-0 z-10 flex items-center justify-center bg-black/50 p-4"
    >
      <div
        ref={ref}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={bodyId}
        tabIndex={-1}
        onKeyDown={onKeyDown}
        className="w-full max-w-md rounded bg-white p-6 shadow-lg outline-none"
      >
        <h2 id={titleId} className="mb-2 text-lg font-semibold">
          {title}
        </h2>
        <div id={bodyId} className="mb-4">
          {children}
        </div>
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
    </div>,
    document.body,
  );
}
