"use client";

import { useSyncExternalStore } from "react";
import { isSessionExpiring, subscribeToSessionExpiry } from "./sessionExpiry";

/**
 * Whether the transport has started bouncing this document to /login.
 *
 * `useSyncExternalStore` rather than an effect + local state: the store is module state a
 * non-React caller writes, so a subscription that only settles after paint would let the
 * error UI this exists to suppress render first. The server snapshot is `false` — SSR has
 * no document to expire.
 */
export function useSessionExpiring(): boolean {
  return useSyncExternalStore(subscribeToSessionExpiry, isSessionExpiring, () => false);
}
