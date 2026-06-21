/**
 * Centralised state constants for the PWA. They replace ad-hoc string
 * literals scattered across components / hooks / use cases and split the
 * concerns into three orthogonal axes:
 *
 * 1. {@link ApiStatus} — the lifecycle of a single async data fetch
 *    (idle → pending → fulfilled / rejected). Use this in hooks, use
 *    cases, repositories and anything that wraps an async call.
 * 2. {@link ViewStatus} — the rendering state of a UI surface (loading,
 *    ready, error, empty, not-found). Use this in pages and components
 *    that branch on what to display. Keep "loading" here and "pending"
 *    in `ApiStatus` so the layers don't leak into each other.
 * 3. {@link PersistenceAction} — the user's CRUD intention against an
 *    aggregate (creating / updating / deleting / saved). Use this for
 *    form modes and post-mutation feedback states.
 *
 * Each constant is declared `as const` and the matching TypeScript type
 * is derived via `(typeof X)[keyof typeof X]` so adding or renaming a
 * value updates the type automatically — no place is allowed to keep
 * speaking the literal "loading" / "create" / "pending" strings.
 */

export const ApiStatus = {
  IDLE: "idle",
  PENDING: "pending",
  FULFILLED: "fulfilled",
  REJECTED: "rejected",
} as const;
export type ApiStatus = (typeof ApiStatus)[keyof typeof ApiStatus];

export const ViewStatus = {
  LOADING: "loading",
  READY: "ready",
  ERROR: "error",
  EMPTY: "empty",
  NOT_FOUND: "not-found",
} as const;
export type ViewStatus = (typeof ViewStatus)[keyof typeof ViewStatus];

export const PersistenceAction = {
  CREATING: "creating",
  UPDATING: "updating",
  DELETING: "deleting",
  SAVED: "saved",
} as const;
export type PersistenceAction = (typeof PersistenceAction)[keyof typeof PersistenceAction];
