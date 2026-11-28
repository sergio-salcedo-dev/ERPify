import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { documentUploadProblemMessage } from "@/context/shared/documents/domain/documentUploadProblemMessage";
import { BankAccountProblemType } from "./BankAccountProblemType";

type EvidenceProblemType =
  | typeof BankAccountProblemType.NOT_FOUND
  | typeof BankAccountProblemType.EVIDENCE_UNAVAILABLE
  | typeof BankAccountProblemType.EVIDENCE_RETENTION_MISMATCH
  | typeof BankAccountProblemType.EVIDENCE_ALREADY_ATTACHED;

const EVIDENCE_TYPE_MESSAGE: Record<EvidenceProblemType, string> = {
  [BankAccountProblemType.NOT_FOUND]: "This account no longer exists. Reload the page.",
  [BankAccountProblemType.EVIDENCE_UNAVAILABLE]:
    "The file was kept but cannot be attached: it is no longer available, or the scan flagged it. Upload it again or choose another file.",
  [BankAccountProblemType.EVIDENCE_RETENTION_MISMATCH]:
    "The file was kept under different retention terms than an ownership certificate needs, so it was not attached.",
  [BankAccountProblemType.EVIDENCE_ALREADY_ATTACHED]:
    "This document is already attached to the account.",
};

const isEvidenceProblemType = (type: string): type is EvidenceProblemType =>
  Object.hasOwn(EVIDENCE_TYPE_MESSAGE, type);

const STATUS_MESSAGE: Readonly<Record<number, string>> = {
  [HttpStatus.UNAUTHORIZED]: "Your session has expired. Sign in again to continue.",
  [HttpStatus.FORBIDDEN]: "You do not have permission to attach evidence to this account.",
};

/** `gone` on the attach means the erasure ledger's override is set — documents are frozen. */
const GONE = "gone";

/**
 * The English copy for a failed evidence attach — either the upload of the original or the attach
 * that follows it. Routed on the Problem Details `type`; status only for the outcomes whose `type`
 * is not this route's to own.
 */
export function evidenceAttachProblemMessage(
  problem: ProblemDetails,
  stage: "upload" | "attach",
): string {
  if (isEvidenceProblemType(problem.type)) return EVIDENCE_TYPE_MESSAGE[problem.type];
  if (stage === "attach" && problem.type === GONE) {
    return "Documents are read-only right now, so the file could not be attached. Try again later.";
  }
  const uploadMessage = documentUploadProblemMessage(problem);
  if (uploadMessage !== null) return uploadMessage;
  const byStatus = STATUS_MESSAGE[problem.status];
  if (byStatus !== undefined) return byStatus;
  return stage === "upload"
    ? "The file could not be uploaded. Try again."
    : "The file was kept but could not be attached. Try again.";
}

/** The number of live documents a `bank-account-has-live-evidence` refusal counts, if it says. */
export function liveDocumentsOf(problem: ProblemDetails): number | null {
  const value = problem.liveDocuments;
  return typeof value === "number" && Number.isInteger(value) && value >= 0 ? value : null;
}
