/**
 * Centralised HTTP status codes for the PWA. They replace number literals
 * scattered across components / hooks / use cases.
 *
 * Only the codes the codebase actually compares against live here — add
 * new entries when a new branch lands, but do NOT mirror the entire RFC
 * 9110 table. Keeping the surface tight makes "is this status meaningful
 * to the UI?" a tractable question at review time.
 *
 * The matching TypeScript type is derived via
 * `(typeof HttpStatus)[keyof typeof HttpStatus]` so adding / renaming a
 * value forces every call site to update.
 */
export const HttpStatus = {
  /** 200 — successful GET / PUT response. */
  OK: 200,
  /** 201 — successful POST / resource creation. */
  CREATED: 201,
  /** 204 — successful response with no content body (DELETE, etc.). */
  NO_CONTENT: 204,
  /** 400 — malformed request. */
  BAD_REQUEST: 400,
  /** 401 — missing / invalid credentials. */
  UNAUTHORIZED: 401,
  /** 403 — authenticated but not allowed. */
  FORBIDDEN: 403,
  /** 404 — resource does not exist. */
  NOT_FOUND: 404,
  /** 409 — concurrent edit / unique-constraint violation. */
  CONFLICT: 409,
  /** 422 — semantic validation failure (per-field violations). */
  UNPROCESSABLE_ENTITY: 422,
  /** 500 — generic server error. */
  INTERNAL_SERVER_ERROR: 500,
  /** 502 — upstream gateway error. */
  BAD_GATEWAY: 502,
  /** 503 — service unavailable. */
  SERVICE_UNAVAILABLE: 503,
} as const;

export type HttpStatus = (typeof HttpStatus)[keyof typeof HttpStatus];
