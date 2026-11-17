/**
 * RFC 9457 `type` values the image write routes mint (wire contract — see
 * `docs/api-error-contract.md`, "Image writes"). The other outcomes of those routes reuse
 * types that exist already (`invalid-uuid`, the owner's own not-found, the rate-limit type) and
 * are mapped by status.
 */
export const ImageProblemType = {
  /** `Content-Length` missing, malformed, or not equal to the bytes read (411). */
  LENGTH_REQUIRED: "image-length-required",
  /** Over the byte cap, or over the decoded pixel and dimension limits (413). */
  TOO_LARGE: "image-too-large",
  /** Format outside the allowlist, or a declared media type that contradicts the detected one (415). */
  UNSUPPORTED_TYPE: "image-unsupported-type",
  /** Empty body, or bytes that do not decode (422). */
  INVALID: "image-invalid",
} as const;

export type ImageProblemType = (typeof ImageProblemType)[keyof typeof ImageProblemType];
