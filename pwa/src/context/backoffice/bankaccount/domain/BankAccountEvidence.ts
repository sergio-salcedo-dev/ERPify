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
 * What an item reads when the server sends a kind or a state this client does not know yet — a
 * newer API ahead of a cached bundle. The item is kept and shown as unknown rather than failing
 * the whole list over one value.
 */
export const UNKNOWN = "unknown" as const;

/**
 * The retention terms each kind is filed under. The kind fixes them, never the user: the API
 * refuses the attach (422 `bank-account-evidence-retention-mismatch`) unless the document was
 * filed under exactly these, so this table and the server's must agree (`bank-account-evidence-
 * parity.test.ts` reads the PHP enum).
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
  readonly kind: BankAccountEvidenceKind | typeof UNKNOWN;
  /** The original filename, or `null` when it cannot or must not open (an erased document). */
  readonly name: string | null;
  readonly state: EvidenceDocumentState | typeof UNKNOWN;
  readonly digest: string;
  readonly sizeBytes: number;
  readonly attachedAt: string;
}

const KINDS: ReadonlySet<string> = new Set(BANK_ACCOUNT_EVIDENCE_KINDS);
const STATES: ReadonlySet<string> = new Set(EVIDENCE_DOCUMENT_STATES);

function isKind(value: string): value is BankAccountEvidenceKind {
  return KINDS.has(value);
}

function isState(value: string): value is EvidenceDocumentState {
  return STATES.has(value);
}

/**
 * Reads one item off the wire, or `null` when its shape is not an item at all. A kind or a state
 * outside the vocabularies above is kept as {@link UNKNOWN}: the shape is the contract, the
 * vocabulary may grow on the server first.
 */
export function toBankAccountEvidence(value: unknown): BankAccountEvidence | null {
  if (typeof value !== "object" || value === null) return null;
  const v = value as Record<string, unknown>;
  if (
    typeof v.id !== "string" ||
    typeof v.documentId !== "string" ||
    typeof v.kind !== "string" ||
    (v.name !== null && typeof v.name !== "string") ||
    typeof v.state !== "string" ||
    typeof v.digest !== "string" ||
    typeof v.sizeBytes !== "number" ||
    typeof v.attachedAt !== "string"
  ) {
    return null;
  }
  return {
    id: v.id,
    documentId: v.documentId,
    kind: isKind(v.kind) ? v.kind : UNKNOWN,
    name: v.name,
    state: isState(v.state) ? v.state : UNKNOWN,
    digest: v.digest,
    sizeBytes: v.sizeBytes,
    attachedAt: v.attachedAt,
  };
}

/** Only a `ready` document's original can be read. */
export function isDownloadable(evidence: Pick<BankAccountEvidence, "state">): boolean {
  return evidence.state === "ready";
}
