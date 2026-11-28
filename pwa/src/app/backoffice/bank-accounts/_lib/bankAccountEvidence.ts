import type { StatusBadgeVariant } from "@/components/erpify";
import type {
  BankAccountEvidenceKind,
  EvidenceDocumentState,
  UNKNOWN,
} from "@/context/backoffice/bankaccount/domain/BankAccountEvidence";
import { BankAccountProblemType } from "@/context/backoffice/bankaccount/domain/BankAccountProblemType";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { NETWORK_ERROR, REQUEST_TIMEOUT } from "@/context/shared/http-client/domain/HttpClient";

/** Presentation maps for the evidence surface, keyed by the wire identity values. */
export const EVIDENCE_KIND_LABEL: Record<BankAccountEvidenceKind | typeof UNKNOWN, string> = {
  "ownership-certificate": "Bank ownership certificate",
  unknown: "Unknown kind",
};

export const EVIDENCE_STATE_LABEL: Record<EvidenceDocumentState | typeof UNKNOWN, string> = {
  received: "Awaiting scan",
  scanning: "Scanning",
  ready: "Ready",
  quarantined: "Quarantined",
  scan_failed: "Scan failed",
  erased: "Erased",
  unknown: "Unknown",
};

export const EVIDENCE_STATE_VARIANT: Record<
  EvidenceDocumentState | typeof UNKNOWN,
  StatusBadgeVariant
> = {
  received: "info",
  scanning: "info",
  ready: "success",
  quarantined: "danger",
  scan_failed: "warning",
  erased: "neutral",
  unknown: "neutral",
};

/** The copy a refusal of the attach reads in, by Problem Details `type`; others keep the server's. */
const ATTACH_PROBLEM_TITLE: Readonly<Record<string, string>> = {
  [BankAccountProblemType.EVIDENCE_RETENTION_MISMATCH]:
    "This screen and the server disagree on how this kind of evidence is kept. Contact an administrator.",
  [BankAccountProblemType.EVIDENCE_DOCUMENT_ERASED]: "The filed document has since been erased.",
  [BankAccountProblemType.EVIDENCE_DOCUMENT_NOT_ADMISSIBLE]:
    "The scan found this file infected, so it cannot be attached.",
  [BankAccountProblemType.EVIDENCE_ALREADY_ATTACHED]:
    "This document is already attached to a bank account. Detach it there first.",
  [BankAccountProblemType.EVIDENCE_ACCOUNT_CLOSED]:
    "This account is closed. Reopen it before attaching evidence.",
  [BankAccountProblemType.EVIDENCE_DOCUMENT_NOT_FOUND]: "The filed document could not be found.",
  [BankAccountProblemType.NOT_FOUND]: "This bank account no longer exists.",
  "invalid-filename":
    "The server refused the file name. Rename the file with ordinary characters and try again.",
  "content-length-mismatch": "The file changed while it was being sent. Try again.",
  "aborted-upload": "The upload did not complete. Try again.",
};

/** The two outcomes where the request may or may not have reached the server. */
function outcomeIsUnknown(problem: ProblemDetails): boolean {
  return problem.type === NETWORK_ERROR || problem.type === REQUEST_TIMEOUT;
}

export function attachProblem(problem: ProblemDetails, fileName: string): ProblemDetails {
  if (outcomeIsUnknown(problem)) {
    return {
      ...problem,
      title: "The outcome is unknown.",
      detail: `It is not known whether "${fileName}" was attached. Check the list below before trying again.`,
    };
  }
  return {
    ...problem,
    title: ATTACH_PROBLEM_TITLE[problem.type] ?? problem.title,
    detail: `"${fileName}" was not attached. The evidence already on file is unchanged.`,
  };
}

const KIB = 1024;
const MIB = KIB * 1024;

/** A size in the unit a person reads: bytes, KB or MB, one decimal past the first unit. */
export function formatSize(bytes: number): string {
  if (bytes < KIB) return `${bytes} B`;
  if (bytes < MIB) return `${(bytes / KIB).toFixed(1)} KB`;
  return `${(bytes / MIB).toFixed(1)} MB`;
}
