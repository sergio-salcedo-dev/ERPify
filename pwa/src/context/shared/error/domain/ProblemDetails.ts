/**
 * RFC 9457 Problem Details — the canonical error envelope returned by every
 * non-2xx response from the ERPify API. The UI layer consumes this shape
 * verbatim; no parallel UI error type is permitted.
 *
 * See the API PRD for the authoritative producer-side contract.
 */
export interface ProblemDetails {
  /** Opaque, kebab-case, stable identifier (e.g. "bank-not-found"). */
  type: string;
  /** Short, human-readable, safe to display to end users. */
  title: string;
  /** HTTP status code; matches the response status line. */
  status: number;
  /** Optional, human-readable explanation for this occurrence. */
  detail?: string;
  /** UUIDv7, unique per error occurrence. User-citable from a toast. */
  instance: string;
  /** UUIDv7, unique per HTTP request. Spans logs/events for the request. */
  "correlation-id": string;
  /** Per-field violations on validation errors (typically 422). */
  violations?: ProblemViolation[];
  /** Open-ended extension members per error category. */
  [extension: string]: unknown;
}

export interface ProblemViolation {
  field: string;
  message: string;
  code?: string;
}

/** Type guard for narrowing an unknown response body to a ProblemDetails. */
export function isProblemDetails(value: unknown): value is ProblemDetails {
  if (typeof value !== "object" || value === null) return false;
  const v = value as Record<string, unknown>;
  return (
    typeof v.type === "string" &&
    typeof v.title === "string" &&
    typeof v.status === "number" &&
    typeof v.instance === "string" &&
    typeof v["correlation-id"] === "string"
  );
}
