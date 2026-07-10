import type { Identity } from "./Identity";

/**
 * Port for resolving the signed-in identity from the gated `who-am-i` endpoint.
 * The adapter owns the HTTP call and the 401 mapping, so the access layer
 * depends on this domain contract (DIP) and never touches `fetch` / status codes.
 */
export interface IdentityRepository {
  /** The signed-in identity, or `null` when there is no live session (401). */
  me(): Promise<Identity | null>;
}
