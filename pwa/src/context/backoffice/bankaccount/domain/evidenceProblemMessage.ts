import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { NETWORK_ERROR, REQUEST_TIMEOUT } from "@/context/shared/http-client/domain/HttpClient";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { DocumentProblemType } from "@/context/shared/documents/domain/DocumentProblemType";
import { documentUploadProblemMessage } from "@/context/shared/documents/domain/documentUploadProblemMessage";
import { BankAccountProblemType } from "./BankAccountProblemType";

/** Which of the two steps of filing evidence failed: the original may already be filed after `attach`. */
export const EvidenceAttachStage = {
  UPLOAD: "upload",
  ATTACH: "attach",
} as const;

export type EvidenceAttachStage = (typeof EvidenceAttachStage)[keyof typeof EvidenceAttachStage];

/** Where a failed attempt stands, as far as the client can know. */
export interface EvidenceFailureContext {
  readonly stage: EvidenceAttachStage;
  /** Upload stage only: the body had started, so the server may have filed the document. */
  readonly contentSent: boolean;
}

type EvidenceProblemType =
  | typeof BankAccountProblemType.NOT_FOUND
  | typeof BankAccountProblemType.EVIDENCE_UNAVAILABLE
  | typeof BankAccountProblemType.EVIDENCE_RETENTION_MISMATCH
  | typeof BankAccountProblemType.EVIDENCE_ALREADY_ATTACHED;

const EVIDENCE_TYPE_MESSAGE: Record<EvidenceProblemType, string> = {
  [BankAccountProblemType.NOT_FOUND]: "This account no longer exists. Reload the page.",
  [BankAccountProblemType.EVIDENCE_UNAVAILABLE]:
    "The document is no longer available to attach: it was erased or the scan flagged it.",
  [BankAccountProblemType.EVIDENCE_RETENTION_MISMATCH]:
    "The document was filed under different retention terms than an ownership certificate needs, so it was not attached.",
  [BankAccountProblemType.EVIDENCE_ALREADY_ATTACHED]:
    "This document is already attached to the account.",
};

const isEvidenceProblemType = (type: string): type is EvidenceProblemType =>
  Object.hasOwn(EVIDENCE_TYPE_MESSAGE, type);

const STATUS_MESSAGE: Readonly<Record<number, string>> = {
  [HttpStatus.UNAUTHORIZED]: "Your session has expired. Sign in again to continue.",
  [HttpStatus.FORBIDDEN]: "You do not have permission to attach evidence to this account.",
};

/** `gone` on the upload is an expired intent; on the attach, the erasure ledger's override. */
const GONE = DocumentProblemType.GONE;

/** A proxy that answered for a server it lost: the request may have been applied behind it. */
const GATEWAY_STATUSES: ReadonlySet<number> = new Set([
  HttpStatus.BAD_GATEWAY,
  HttpStatus.SERVICE_UNAVAILABLE,
  HttpStatus.GATEWAY_TIMEOUT,
]);

/** The Documents refusals after which the same file can simply be sent again. */
const RETRYABLE_UPLOAD_TYPES: ReadonlySet<string> = new Set([
  DocumentProblemType.GONE,
  DocumentProblemType.LENGTH_REQUIRED,
  DocumentProblemType.CONTENT_LENGTH_MISMATCH,
  DocumentProblemType.ABORTED_UPLOAD,
  DocumentProblemType.UPLOAD_IN_PROGRESS,
]);

function isTransportUnknown(problem: ProblemDetails): boolean {
  return problem.type === NETWORK_ERROR || problem.type === REQUEST_TIMEOUT;
}

function isServerError(problem: ProblemDetails): boolean {
  return problem.status >= HttpStatus.INTERNAL_SERVER_ERROR;
}

/**
 * True when the client cannot know whether the step took effect. An upload is unknown only once its
 * body had started — a declare that never answered filed nothing — and only for a lost connection,
 * a timeout or a gateway answer; a 500 from the application itself expired the upload. An attach is
 * unknown on a lost connection, a timeout or any 5xx: its transaction may have committed.
 */
export function isEvidenceOutcomeUnknown(
  problem: ProblemDetails,
  context: EvidenceFailureContext,
): boolean {
  if (context.stage === EvidenceAttachStage.UPLOAD) {
    return (
      context.contentSent && (isTransportUnknown(problem) || GATEWAY_STATUSES.has(problem.status))
    );
  }
  return isTransportUnknown(problem) || isServerError(problem);
}

/**
 * True when repeating the failed step is worth offering. An upload is repeated only when it is known
 * to have filed nothing — never blind, since a second upload of a filed original would leave an
 * unattached copy. An attach is repeated with the same document, so it is safe even when unknown.
 */
export function isEvidenceRetryable(
  problem: ProblemDetails,
  context: EvidenceFailureContext,
): boolean {
  if (isEvidenceOutcomeUnknown(problem, context)) {
    return context.stage === EvidenceAttachStage.ATTACH;
  }
  const transient =
    isTransportUnknown(problem) ||
    isServerError(problem) ||
    problem.status === HttpStatus.TOO_MANY_REQUESTS ||
    problem.type === GONE;
  if (context.stage === EvidenceAttachStage.ATTACH) return transient;
  return transient || RETRYABLE_UPLOAD_TYPES.has(problem.type);
}

/**
 * The English headline for a failed step, routed on the Problem Details `type`; status only for the
 * outcomes whose `type` is not these routes' to own. It names what went wrong; what became of the
 * file is the detail's job, so no headline promises a retry the surface may not offer.
 */
export function evidenceAttachProblemMessage(
  problem: ProblemDetails,
  stage: EvidenceAttachStage,
): string {
  if (isEvidenceProblemType(problem.type)) return EVIDENCE_TYPE_MESSAGE[problem.type];
  if (stage === EvidenceAttachStage.ATTACH && problem.type === GONE) {
    return "Documents are read-only right now, so the document could not be attached.";
  }
  const uploadMessage = documentUploadProblemMessage(problem);
  if (uploadMessage !== null) return uploadMessage;
  const byStatus = STATUS_MESSAGE[problem.status];
  if (byStatus !== undefined) return byStatus;
  return stage === EvidenceAttachStage.UPLOAD
    ? "The file could not be uploaded."
    : "The document could not be attached.";
}

/** The number of live documents a `bank-account-has-live-evidence` refusal counts, if it says. */
export function liveDocumentsOf(problem: ProblemDetails): number | null {
  const value = problem.liveDocuments;
  return typeof value === "number" && Number.isInteger(value) && value >= 0 ? value : null;
}
