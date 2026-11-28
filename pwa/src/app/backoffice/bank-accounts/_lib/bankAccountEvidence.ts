import type { StatusBadgeVariant } from "@/components/erpify";
import type {
  BankAccountEvidenceKind,
  EvidenceDocumentState,
} from "@/context/backoffice/bankaccount/domain/BankAccountEvidence";
import { BankAccountProblemType } from "@/context/backoffice/bankaccount/domain/BankAccountProblemType";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

/** Presentation maps for the evidence surface, keyed by the wire identity values. */
export const EVIDENCE_KIND_LABEL: Record<BankAccountEvidenceKind, string> = {
  "ownership-certificate": "Bank ownership certificate",
};

export const EVIDENCE_STATE_LABEL: Record<EvidenceDocumentState, string> = {
  received: "Awaiting scan",
  scanning: "Scanning",
  ready: "Ready",
  quarantined: "Quarantined",
  scan_failed: "Scan failed",
  erased: "Erased",
};

export const EVIDENCE_STATE_VARIANT: Record<EvidenceDocumentState, StatusBadgeVariant> = {
  received: "info",
  scanning: "info",
  ready: "success",
  quarantined: "danger",
  scan_failed: "warning",
  erased: "neutral",
};

/** The copy a refusal of the attach reads in, by Problem Details `type`; others keep the server's. */
const ATTACH_PROBLEM_TITLE: Readonly<Record<string, string>> = {
  [BankAccountProblemType.EVIDENCE_RETENTION_MISMATCH]:
    "The file was filed under other retention terms than this kind of evidence. Attach it again.",
  [BankAccountProblemType.EVIDENCE_DOCUMENT_ERASED]: "The filed document has since been erased.",
  [BankAccountProblemType.EVIDENCE_ALREADY_ATTACHED]:
    "This document is already attached to the account.",
  [BankAccountProblemType.EVIDENCE_DOCUMENT_NOT_FOUND]: "The filed document could not be found.",
  [BankAccountProblemType.NOT_FOUND]: "This bank account no longer exists.",
  "invalid-filename":
    "The server refused the file name. Rename the file with ordinary characters and try again.",
  "content-length-mismatch": "The file changed while it was being sent. Try again.",
  "aborted-upload": "The upload did not complete. Try again.",
};

export function attachProblem(problem: ProblemDetails, fileName: string): ProblemDetails {
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
