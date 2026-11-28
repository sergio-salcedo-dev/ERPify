import {
  retryAfterSecondsOf,
  type ProblemDetails,
} from "@/context/shared/error/domain/ProblemDetails";
import { NETWORK_ERROR, REQUEST_TIMEOUT } from "@/context/shared/http-client/domain/HttpClient";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { MAX_DOCUMENT_MEBIBYTES } from "./documentLimits";
import { DocumentProblemType } from "./DocumentProblemType";

const DOCUMENT_TYPE_MESSAGE: Record<DocumentProblemType, string> = {
  [DocumentProblemType.GONE]: "The upload expired before the file arrived. Try again.",
  [DocumentProblemType.LENGTH_REQUIRED]:
    "The size of the upload could not be confirmed, so nothing was kept. Try again.",
  [DocumentProblemType.CONTENT_LENGTH_MISMATCH]:
    "The file changed size while it was being sent, so nothing was kept. Try again.",
  [DocumentProblemType.INVALID_FILENAME]:
    "The file name cannot be kept as it is. Rename the file and try again.",
  [DocumentProblemType.ABORTED_UPLOAD]:
    "The upload was interrupted, so nothing was kept. Try again.",
  [DocumentProblemType.UPLOAD_IN_PROGRESS]:
    "This file is already being uploaded. Wait for it to finish.",
  [DocumentProblemType.IDEMPOTENCY_KEY_CONFLICT]: "This upload clashes with an earlier one.",
};

const CLIENT_TYPE_MESSAGE: ReadonlyMap<string, string> = new Map([
  [NETWORK_ERROR, "The connection to the server was lost."],
  [REQUEST_TIMEOUT, "The server did not answer in time."],
  [
    "validation-failed",
    `The file must be at least one byte and at most ${MAX_DOCUMENT_MEBIBYTES} MB.`,
  ],
]);

function isDocumentProblemType(type: string): type is DocumentProblemType {
  return (Object.values(DocumentProblemType) as string[]).includes(type);
}

function throttledMessage(problem: ProblemDetails): string {
  const seconds = retryAfterSecondsOf(problem);
  if (seconds === null) return "Too many uploads at once. Wait a moment and try again.";
  const unit = seconds === 1 ? "second" : "seconds";
  return `Too many uploads in a short time. Wait ${seconds} ${unit} and try again.`;
}

/**
 * The English copy for a failed evidence upload (either step), or `null` when the problem is not
 * one these routes produce — so a consumer can fall through to its own mapping. Routed on `type`;
 * only the throttle, whose `type` is shared, is read by status.
 */
export function documentUploadProblemMessage(problem: ProblemDetails): string | null {
  if (isDocumentProblemType(problem.type)) return DOCUMENT_TYPE_MESSAGE[problem.type];
  const byType = CLIENT_TYPE_MESSAGE.get(problem.type);
  if (byType !== undefined) return byType;
  if (problem.status === HttpStatus.TOO_MANY_REQUESTS) return throttledMessage(problem);
  return null;
}
