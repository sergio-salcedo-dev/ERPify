"use client";

import { useCallback, useState } from "react";

export interface IbanReveal {
  /** Id of the row whose integral IBAN is currently shown, or null. */
  revealedId: string | null;
  /** Reveal one row's IBAN (re-masking any other). */
  reveal: (id: string) => void;
  /** Re-mask the revealed row. */
  hide: () => void;
}

/**
 * Single-flight IBAN reveal for a list view (table / cards / stacked): at most
 * one row shows its integral IBAN at a time, and every reveal resets when the
 * page (`items`) changes so paginating or a realtime reconcile always re-masks
 * (IBAN is PII). The reset is adjusted during render — before the new rows paint
 * a stale revealed state. `reveal`/`hide` are stable so `IbanCell` can key its
 * auto-hide timer on `hide` without restarting the countdown each render.
 */
export function useIbanReveal<T>(items: readonly T[]): IbanReveal {
  const [revealedId, setRevealedId] = useState<string | null>(null);
  const [prevItems, setPrevItems] = useState(items);
  if (items !== prevItems) {
    setPrevItems(items);
    setRevealedId(null);
  }
  const hide = useCallback(() => setRevealedId(null), []);
  return { revealedId, reveal: setRevealedId, hide };
}
