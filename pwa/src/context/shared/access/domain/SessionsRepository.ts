import type { SessionSummary } from "./SessionSummary";

/**
 * Port for the signed-in user's own session registry. The adapter owns the HTTP
 * calls; callers depend on this domain contract (DIP).
 */
export interface SessionsRepository {
  /** The user's active sessions, current one flagged. */
  list(): Promise<SessionSummary[]>;
  /** Revoke every session except the current one. */
  revokeOthers(): Promise<void>;
  /**
   * Revoke the current session (sign out this device); the server also drops the cookie.
   *
   * `budgetMs` is how long the caller is prepared to wait for the server to answer. It is a
   * number rather than a transport option so this port keeps speaking in intent: the adapter
   * decides what "give up" means over HTTP. Omitted, the adapter's own default applies.
   */
  revokeCurrent(budgetMs?: number): Promise<void>;
}
