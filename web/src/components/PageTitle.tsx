import { useEffect, useRef, type ReactNode } from 'react';
import { useFocusOnMount } from './pageFocus';

/**
 * The page's single h1. After a client-side navigation it takes focus so the change is
 * announced and keyboard users start at the top; on a full page load it does not, so the
 * first Tab reaches the skip link.
 */
export default function PageTitle({
  children,
  className = '',
}: {
  children: ReactNode;
  className?: string;
}) {
  const ref = useRef<HTMLHeadingElement>(null);
  const focusOnMount = useFocusOnMount();
  useEffect(() => {
    if (focusOnMount) ref.current?.focus();
  }, [focusOnMount]);
  return (
    <h1
      ref={ref}
      tabIndex={-1}
      className={`mb-4 text-2xl font-semibold outline-none ${className}`}
    >
      {children}
    </h1>
  );
}
