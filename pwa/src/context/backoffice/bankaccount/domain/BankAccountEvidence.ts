import {
  LegalBasis,
  RetentionReason,
  type RetentionTerms,
} from "@/context/shared/documents/domain/RetentionTerms";

/**
 * What a piece of evidence attached to a bank account proves — the API's `EvidenceKind` wire
 * vocabulary. A kind is added by a decision on both deployables.
 */
export const EvidenceKind = {
  OWNERSHIP_CERTIFICATE: "ownership-certificate",
} as const;

export type EvidenceKind = (typeof EvidenceKind)[keyof typeof EvidenceKind];

export const EVIDENCE_KINDS: readonly EvidenceKind[] = Object.values(EvidenceKind);

/**
 * The retention terms each kind's document must be filed under. The kind decides them, never the
 * person uploading: the attach compares the document against exactly these and refuses another set
 * (`bank-account-evidence-retention-mismatch`). Mirrors `EvidenceKind::retentionReason()` /
 * `legalBasis()` on the API.
 */
export const EVIDENCE_RETENTION: Record<EvidenceKind, RetentionTerms> = {
  [EvidenceKind.OWNERSHIP_CERTIFICATE]: {
    retentionReason: RetentionReason.CONTRACT_EXECUTION,
    legalBasis: LegalBasis.LEGITIMATE_INTERESTS,
    reviewAt: null,
  },
};

/** A document's lifecycle as Documents reports it on the evidence listing. */
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
 * One piece of evidence of a bank account: the link (id, document id, kind, when attached) and what
 * Documents says about the document. `documentState`, `documentName` and `documentSizeBytes` are
 * `null` when Documents has nothing to give (a name that does not open, an erased document). The
 * name is a person-supplied filename: rendered as text, never logged or persisted client-side.
 */
export interface BankAccountEvidence {
  readonly id: string;
  readonly documentId: string;
  readonly kind: EvidenceKind;
  readonly attachedAt: string;
  readonly documentState: EvidenceDocumentState | null;
  readonly documentName: string | null;
  readonly documentSizeBytes: number | null;
}

/**
 * Port for a bank account's evidence: list it (oldest first) and attach a document already filed
 * through the Documents upload. Rejections surface as the transport's `HttpError`.
 */
export interface BankAccountEvidenceRepository {
  list(accountId: string): Promise<BankAccountEvidence[]>;
  attach(accountId: string, documentId: string, kind: EvidenceKind): Promise<BankAccountEvidence>;
}
