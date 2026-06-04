"use client";

import { useEffect, useRef, useState } from "react";

/**
 * Tracks whether an element's content is visually truncated (single-line
 * `truncate` or multi-line `line-clamp-*`). Re-checks on element resize and
 * whenever `value` changes (a content swap can flip truncation without a
 * resize, which a ResizeObserver alone would miss).
 *
 * The effect also re-runs when `truncated` flips: consumers typically remount
 * the measured node inside a tooltip trigger at that exact moment, so the
 * observer must move from the now-detached old node (whose detach fires a
 * 0-size observation that would flap the state back) to the current one.
 */
export function useIsTruncated<T extends HTMLElement>(value: string) {
  const ref = useRef<T>(null);
  const [truncated, setTruncated] = useState(false);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const check = (): void => {
      const current = ref.current;
      if (!current || !current.isConnected) return;
      setTruncated(
        current.scrollWidth > current.clientWidth || current.scrollHeight > current.clientHeight,
      );
    };
    check();
    const observer = new ResizeObserver(check);
    observer.observe(el);
    return () => observer.disconnect();
  }, [value, truncated]);

  return { ref, truncated };
}
