/**
 * The image formats the write routes accept — the API's `ImageProcessor` allowlist and nothing
 * wider. The server decides from the bytes; this list only keeps the file picker and the
 * pre-upload check from offering what the server is certain to refuse.
 */
export const ACCEPTED_IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp", "image/gif"] as const;

export type AcceptedImageType = (typeof ACCEPTED_IMAGE_TYPES)[number];

/** The value of an `<input type="file" accept>` for {@link ACCEPTED_IMAGE_TYPES}. */
export const IMAGE_ACCEPT_ATTRIBUTE = ACCEPTED_IMAGE_TYPES.join(",");

/**
 * The byte cap of an image write: `erpify.images.max_input_bytes` (20 MiB), which the edge
 * matcher for these routes carries too. A mirror for the pre-upload check — the server stays the
 * authority, and a change to the API value is a change here.
 */
export const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

/** The cap as a figure for copy. */
export const MAX_IMAGE_MEGABYTES = MAX_IMAGE_BYTES / (1024 * 1024);
