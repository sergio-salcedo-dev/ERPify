"use client";

import { useEffect, useRef, useState } from "react";

/**
 * Tracks whether an element's content is visually truncated (single-line
 * `truncate` or multi-line `line-clamp-*`). Re-checks on element resize and
 * whenever `value` changes (a content swap can flip truncation without a
 * resize, which a ResizeObserver alone would miss).
 */
export function useIsTruncated<T extends HTMLElement>(value: string) {
  const ref = useRef<T>(null);
  const [truncated, setTruncated] = useState(false);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const check = (): void => {
      setTruncated(el.scrollWidth > el.clientWidth || el.scrollHeight > el.clientHeight);
    };
    check();
    const observer = new ResizeObserver(check);
    observer.observe(el);
    return () => observer.disconnect();
  }, [value]);

  return { ref, truncated };
}
