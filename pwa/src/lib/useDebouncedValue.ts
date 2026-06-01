import { useEffect, useState } from "react";

/**
 * Returns a debounced copy of `value` that only updates after `delayMs` has
 * elapsed without further changes. Each change resets the timer.
 */
export function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState<T>(value);

  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(id);
  }, [value, delayMs]);

  return debounced;
}
