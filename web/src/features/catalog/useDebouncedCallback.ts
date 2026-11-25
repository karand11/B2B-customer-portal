import { useCallback, useEffect, useRef } from 'react';

/** Returns [schedule, cancel]. Only the last scheduled call runs, after `delay` ms. */
export function useDebouncedCallback<A extends unknown[]>(
  fn: (...args: A) => void,
  delay: number,
) {
  const fnRef = useRef(fn);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  useEffect(() => {
    fnRef.current = fn;
  });
  const cancel = useCallback(() => clearTimeout(timer.current), []);
  const schedule = useCallback(
    (...args: A) => {
      clearTimeout(timer.current);
      timer.current = setTimeout(() => fnRef.current(...args), delay);
    },
    [delay],
  );
  useEffect(() => cancel, [cancel]);
  return [schedule, cancel] as const;
}
