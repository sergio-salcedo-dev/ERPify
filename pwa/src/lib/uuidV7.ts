import { v7 } from "uuid";

/**
 * Canonical UUID v7 generator for the PWA. Every client-generated identifier — e.g. the `instance`
 * and `correlation-id` fields the UI fabricates for client-side `ProblemDetails` fallbacks — MUST use
 * this, never `crypto.randomUUID()` (which is always v4).
 *
 * v7 keeps ids time-ordered and consistent with the API: persisted PKs and minted correlation-ids are
 * v7, and the API's `CorrelationIdListener` strictly rejects non-v7 values. Isolating the `uuid`
 * dependency behind this one wrapper keeps it swappable in a single place.
 */
export function uuidV7(): string {
  return v7();
}
