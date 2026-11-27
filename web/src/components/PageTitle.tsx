import { useEffect, useRef, type ReactNode } from 'react';

/** The page's single h1. It takes focus on mount so route changes are announced and keyboard users start at the top. */
export default function PageTitle({
  children,
  className = '',
}: {
  children: ReactNode;
  className?: string;
}) {
  const ref = useRef<HTMLHeadingElement>(null);
  useEffect(() => ref.current?.focus(), []);
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
