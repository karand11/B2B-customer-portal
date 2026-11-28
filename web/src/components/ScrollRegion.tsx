import type { ReactNode } from 'react';

/**
 * Horizontal scroll container for a wide table, so the page itself never scrolls sideways.
 * It is a keyboard stop (tabIndex 0) with a name, which is what axe `scrollable-region-focusable`
 * asks for; the focus ring comes from the global :focus-visible rule. The table stays a real
 * table inside it.
 */
export default function ScrollRegion({
  label,
  className = '',
  children,
}: {
  label: string;
  className?: string;
  children: ReactNode;
}) {
  return (
    <div
      role="region"
      tabIndex={0}
      aria-label={label}
      className={`max-w-full overflow-x-auto ${className}`}
    >
      {children}
    </div>
  );
}
