import { describe, expect, it, vi } from "vitest";
import {
  AttachBankAccountEvidence,
  EvidenceAttachFailure,
} from "@/context/backoffice/bankaccount/application/AttachBankAccountEvidence";
import { ListBankAccountEvidence } from "@/context/backoffice/bankaccount/application/ListBankAccountEvidence";
import type {
  BankAccountEvidence,
  BankAccountEvidenceRepository,
} from "@/context/backoffice/bankaccount/domain/BankAccountEvidence";
import type { DocumentUploader } from "@/context/shared/documents/domain/DocumentUploader";

const ACCOUNT_ID = "0192aaaa-aaaa-7aaa-8aaa-000000000001";
const DOCUMENT_ID = "0192bbbb-bbbb-7bbb-8bbb-000000000002";
const EVIDENCE: BankAccountEvidence = {
  id: "0192cccc-cccc-7ccc-8ccc-000000000003",
  documentId: DOCUMENT_ID,
  kind: "ownership-certificate",
  attachedAt: "2026-01-01T10:00:00Z",
  documentState: "received",
  documentName: "cert.pdf",
  documentSizeBytes: 3,
};
const file = new File([new Uint8Array([1, 2, 3])], "cert.pdf");

function repository(overrides: Partial<BankAccountEvidenceRepository> = {}) {
  return {
    list: vi.fn().mockResolvedValue([EVIDENCE]),
    attach: vi.fn().mockResolvedValue(EVIDENCE),
    ...overrides,
  };
}

function uploader(overrides: Partial<DocumentUploader> = {}): DocumentUploader {
  return {
    upload: vi
      .fn()
      .mockResolvedValue({ id: DOCUMENT_ID, state: "received", digest: "d", sizeBytes: 3 }),
    ...overrides,
  };
}

describe("AttachBankAccountEvidence", () => {
  it("uploads under the kind's retention terms, then attaches the filed document", async () => {
    const up = uploader();
    const repo = repository();
    const onProgress = vi.fn();

    const result = await new AttachBankAccountEvidence(up, repo).run(
      ACCOUNT_ID,
      file,
      "ownership-certificate",
      onProgress,
    );

    expect(up.upload).toHaveBeenCalledWith(
      file,
      { retentionReason: "contract-execution", legalBasis: "legitimate-interests", reviewAt: null },
      onProgress,
    );
    expect(repo.attach).toHaveBeenCalledWith(ACCOUNT_ID, DOCUMENT_ID, "ownership-certificate");
    expect(result).toBe(EVIDENCE);
  });

  it("tags an upload failure with its stage and never attaches", async () => {
    const cause = new Error("offline");
    const repo = repository();
    const run = new AttachBankAccountEvidence(
      uploader({ upload: vi.fn().mockRejectedValue(cause) }),
      repo,
    ).run(ACCOUNT_ID, file, "ownership-certificate");

    await expect(run).rejects.toMatchObject({ stage: "upload", cause });
    await expect(run).rejects.toBeInstanceOf(EvidenceAttachFailure);
    expect(repo.attach).not.toHaveBeenCalled();
  });

  it("tags an attach failure with its stage", async () => {
    const cause = new Error("409");
    await expect(
      new AttachBankAccountEvidence(
        uploader(),
        repository({ attach: vi.fn().mockRejectedValue(cause) }),
      ).run(ACCOUNT_ID, file, "ownership-certificate"),
    ).rejects.toMatchObject({ stage: "attach", cause });
  });
});

describe("ListBankAccountEvidence", () => {
  it("lists the account's evidence through the port", async () => {
    const repo = repository();
    await expect(new ListBankAccountEvidence(repo).run(ACCOUNT_ID)).resolves.toEqual([EVIDENCE]);
    expect(repo.list).toHaveBeenCalledWith(ACCOUNT_ID);
  });
});
