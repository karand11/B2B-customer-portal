import { createContext, useContext, useState } from 'react';

/**
 * True once the first page of the app has rendered. Until then (a full page load, including
 * the guard redirect to /login) headings must not take focus: the first Tab has to reach the
 * skip link and the header. Layout provides the ref; outside Layout (isolated component tests)
 * the default lets headings focus as before.
 */
export const PageSettledContext = createContext(true);

/**
 * Whether this page was mounted by a client-side navigation (so it may take focus). Captured
 * once at mount, during render: reading it later would race with Layout marking the app settled.
 */
export function useFocusOnMount(): boolean {
  const settled = useContext(PageSettledContext);
  return useState(settled)[0];
}
