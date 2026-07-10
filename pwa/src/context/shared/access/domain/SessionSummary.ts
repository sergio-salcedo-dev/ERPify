/**
 * A row in the signed-in user's list of active sessions. Mirrors the
 * `/api/v1/sessions` envelope items — the current session is flagged so the UI
 * can label it "This device" and exclude it from a "sign out others" action.
 */
export interface SessionSummary {
  id: string;
  /** Server-normalized device / user-agent label. Render as text, never HTML. */
  device: string;
  /** ISO-8601 timestamp of when the session was created. */
  createdAt: string;
  /** True for the session backing the current request. */
  current: boolean;
}
