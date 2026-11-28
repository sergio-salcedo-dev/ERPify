import type { RetentionTerms } from "@/context/backoffice/document/domain/DocumentUploader";

/** What a document attached to a bank account proves. Mirrors the API's `BankAccountEvidenceKind`. */
export const BANK_ACCOUNT_EVIDENCE_KINDS = ["ownership-certificate"] as const;
export type BankAccountEvidenceKind = (typeof BANK_ACCOUNT_EVIDENCE_KINDS)[number];

/** A document's lifecycle state, as the Documents context names it. */
export const EVIDENCE_DOCUMENT_STATES = [
  "received",
  "scanning",
  "ready",
  "quarantined",
  "scan_failed",
  "erased",
] as const;
export type EvidenceDocumentState = (typeof EVIDENCE_DOCUMENT_STATES)[number];

/**
 * The retention terms each kind is filed under. The kind fixes them, never the user: the API
 * refuses the attach (422 `bank-account-evidence-retention-mismatch`) unless the document was
 * filed under exactly these, so this table and the server's must agree.
 */
export const EVIDENCE_RETENTION: Readonly<Record<BankAccountEvidenceKind, RetentionTerms>> = {
  "ownership-certificate": {
    retentionReason: "contract-execution",
    legalBasis: "legitimate-interests",
  },
};

/** One document a bank account holds as evidence, with what the Documents context says of it. */
export interface BankAccountEvidence {
  /** The link's id. */
  readonly id: string;
  /** The document's id — what the audited read route takes to download it. */
  readonly documentId: string;
  readonly kind: BankAccountEvidenceKind;
  /** The original filename, or `null` when it cannot or must not open (an erased document). */
  readonly name: string | null;
  readonly state: EvidenceDocumentState;
  readonly digest: string;
  readonly sizeBytes: number;
  readonly attachedAt: string;
}

const KINDS: ReadonlySet<string> = new Set(BANK_ACCOUNT_EVIDENCE_KINDS);
const STATES: ReadonlySet<string> = new Set(EVIDENCE_DOCUMENT_STATES);

/** The payload guard of one item, derived from the two vocabularies above. */
export function isBankAccountEvidence(value: unknown): value is BankAccountEvidence {
  if (typeof value !== "object" || value === null) return false;
  const v = value as Record<string, unknown>;
  return (
    typeof v.id === "string" &&
    typeof v.documentId === "string" &&
    typeof v.kind === "string" &&
    KINDS.has(v.kind) &&
    (v.name === null || typeof v.name === "string") &&
    typeof v.state === "string" &&
    STATES.has(v.state) &&
    typeof v.digest === "string" &&
    typeof v.sizeBytes === "number" &&
    typeof v.attachedAt === "string"
  );
}

/** Only a `ready` document's original can be read. */
export function isDownloadable(evidence: Pick<BankAccountEvidence, "state">): boolean {
  return evidence.state === "ready";
}
