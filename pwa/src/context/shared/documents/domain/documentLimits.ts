/**
 * The byte cap of an evidence original: the API's `UploadLimits::MAX_ORIGINAL_BYTES` (100 MiB),
 * exactly that many bytes accepted. A mirror for the pre-upload check — the server stays the
 * authority, and a change to the API value is a change here.
 */
export const MAX_DOCUMENT_BYTES = 100 * 1024 * 1024;

/** The cap as a figure for copy. */
export const MAX_DOCUMENT_MEBIBYTES = MAX_DOCUMENT_BYTES / (1024 * 1024);

/**
 * The slowest uplink the server accepts (`UploadLimits::MIN_THROUGHPUT_BYTES_PER_SECOND`, 256 KiB/s)
 * and the margin it adds (`DEADLINE_MARGIN_SECONDS`): the server's own deadline for a body of a given
 * size. The client derives its budget from it so it never gives up on an upload the server is still
 * willing to finish.
 */
const MIN_THROUGHPUT_BYTES_PER_SECOND = 256 * 1024;
const DEADLINE_MARGIN_SECONDS = 60;

/** Room for the commit after the last byte (`UploadLimits::COMMIT_TIME_LIMIT_SECONDS`). */
const COMMIT_SECONDS = 60;

/** How long the client waits for the body of `sizeBytes` to be sent and filed. */
export function uploadBudgetMs(sizeBytes: number): number {
  const deadlineSeconds =
    Math.ceil(sizeBytes / MIN_THROUGHPUT_BYTES_PER_SECOND) + DEADLINE_MARGIN_SECONDS;
  return (deadlineSeconds + COMMIT_SECONDS) * 1000;
}
