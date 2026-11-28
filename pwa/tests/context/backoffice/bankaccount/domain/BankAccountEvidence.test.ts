import { describe, expect, it } from "vitest";
import {
  EVIDENCE_KINDS,
  EVIDENCE_RETENTION,
  EvidenceKind,
} from "@/context/backoffice/bankaccount/domain/BankAccountEvidence";

describe("EVIDENCE_RETENTION", () => {
  it("fixes the ownership certificate's terms to what the API's EvidenceKind requires", () => {
    expect(EVIDENCE_RETENTION[EvidenceKind.OWNERSHIP_CERTIFICATE]).toStrictEqual({
      retentionReason: "contract-execution",
      legalBasis: "legitimate-interests",
      reviewAt: null,
    });
  });

  it("covers every kind", () => {
    for (const kind of EVIDENCE_KINDS) {
      expect(EVIDENCE_RETENTION[kind]).toBeDefined();
    }
  });
});
