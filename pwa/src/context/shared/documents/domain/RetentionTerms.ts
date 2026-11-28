/**
 * Why an original is kept — the Documents `RetentionReason` wire vocabulary, spelled exactly as the
 * API reads it on the declare step. The consumer that files the document decides the value (a
 * bank-account evidence kind fixes its own), never the person uploading.
 */
export const RetentionReason = {
  CONTRACT_EXECUTION: "contract-execution",
  TAX_AND_ACCOUNTING: "tax-and-accounting",
  TECHNICAL_FILE: "technical-file",
  LEGAL_CLAIMS: "legal-claims",
} as const;

export type RetentionReason = (typeof RetentionReason)[keyof typeof RetentionReason];

/** The GDPR lawful basis of keeping it — the Documents `LegalBasis` wire vocabulary. */
export const LegalBasis = {
  CONSENT: "consent",
  CONTRACT: "contract",
  LEGAL_OBLIGATION: "legal-obligation",
  VITAL_INTERESTS: "vital-interests",
  PUBLIC_TASK: "public-task",
  LEGITIMATE_INTERESTS: "legitimate-interests",
} as const;

export type LegalBasis = (typeof LegalBasis)[keyof typeof LegalBasis];

/**
 * The retention terms a document is declared under. The server compares them on every later use (an
 * attach refuses a document filed under other terms), so they are fixed by the consumer per purpose.
 * `reviewAt` is an ISO-8601 instant, or `null` for none.
 */
export interface RetentionTerms {
  readonly retentionReason: RetentionReason;
  readonly legalBasis: LegalBasis;
  readonly reviewAt: string | null;
}
