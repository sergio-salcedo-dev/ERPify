"use client";

import { useCallback, useEffect, useState } from "react";

/**
 * localStorage-backed UI preference that is hydration-safe by construction:
 * SSR and the first client render both see `fallback`, and the stored value
 * is applied in an effect after hydration. Reading localStorage during the
 * initial render (the previous pattern) made the server and client trees
 * diverge whenever a non-default preference was stored.
 */
export function useStoredPreference<T extends string>(
  key: string,
  fallback: T,
  isValid: (value: string) => value is T,
): [T, (next: T) => void] {
  const [value, setValue] = useState<T>(fallback);

  useEffect(() => {
    const stored = globalThis.localStorage.getItem(key);
    if (stored !== null && isValid(stored)) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setValue(stored);
    }
    // `isValid` is a module-level type guard at every call site; re-running on
    // identity changes would re-read the same key for nothing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  const update = useCallback(
    (next: T) => {
      setValue(next);
      globalThis.localStorage.setItem(key, next);
    },
    [key],
  );

  return [value, update];
}
