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
  /** Revoke the current session (sign out this device); the server also drops the cookie. */
  revokeCurrent(): Promise<void>;
}
