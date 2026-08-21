"use client";

import { useSyncExternalStore } from "react";
import { currentDeparture, subscribeToDeparture, type DepartureReason } from "./departure";

/**
 * Why this document is being left, or `null` while it is staying.
 *
 * `useSyncExternalStore` rather than an effect + local state: the store is module state a non-React
 * caller writes, so a subscription that only settles after paint would let the error UI a reader of
 * this exists to suppress render first. The server snapshot is `null` — SSR has no document to leave.
 */
export function useDeparture(): DepartureReason | null {
  return useSyncExternalStore(subscribeToDeparture, currentDeparture, () => null);
}
