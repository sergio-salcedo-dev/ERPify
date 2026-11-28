/**
 * RFC 9457 `type` values the Documents upload routes mint (wire contract — see
 * `docs/api-error-contract.md`, "Documents ingest"). The routes' other outcomes reuse types other
 * routes mint too (`validation-failed`, `rate-limited`, `invalid-uuid`, `forbidden`).
 */
export const DocumentProblemType = {
  /** The upload does not exist, is another actor's, or expired (410). */
  GONE: "gone",
  /** The PUT carried no usable `Content-Length` (411). */
  LENGTH_REQUIRED: "length-required",
  /** `Content-Length` differs from the declared size (422). */
  CONTENT_LENGTH_MISMATCH: "content-length-mismatch",
  /** The original filename is missing or breaks a rule (422). */
  INVALID_FILENAME: "invalid-filename",
  /** The body fell short, overran, mismatched its digest, or lost its lease (422). */
  ABORTED_UPLOAD: "aborted-upload",
  /** The same upload is streaming right now (409). */
  UPLOAD_IN_PROGRESS: "upload-in-progress",
  /** An idempotency key reused with different parameters (409). */
  IDEMPOTENCY_KEY_CONFLICT: "idempotency-key-conflict",
} as const;

export type DocumentProblemType = (typeof DocumentProblemType)[keyof typeof DocumentProblemType];
