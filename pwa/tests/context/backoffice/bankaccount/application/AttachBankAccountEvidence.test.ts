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
import type {
  DocumentUploader,
  DocumentUploadObserver,
} from "@/context/shared/documents/domain/DocumentUploader";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";

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

const TERMS = {
  retentionReason: "contract-execution",
  legalBasis: "legitimate-interests",
  reviewAt: null,
};

function httpError(type: string, status: number): HttpError {
  return new HttpError({ type, title: "t", status, instance: "i", "correlation-id": "c" });
}

/** An uploader that drives the observer as the real one does, then settles as told. */
function drivingUploader(outcome: "filed" | "fail-before-body" | "fail-during-body") {
  return uploader({
    upload: vi.fn(async (_file: File, _terms: unknown, observer?: DocumentUploadObserver) => {
      if (outcome === "fail-before-body") throw new Error("declare failed");
      observer?.onSending?.();
      observer?.onProgress?.({ loaded: 3, total: 3 });
      if (outcome === "fail-during-body") throw new Error("put failed");
      observer?.onSent?.();
      return { id: DOCUMENT_ID, state: "received", digest: "d", sizeBytes: 3 };
    }),
  });
}

describe("AttachBankAccountEvidence", () => {
  it("uploads under the kind's retention terms, then attaches the filed document", async () => {
    const up = uploader();
    const repo = repository();

    const result = await new AttachBankAccountEvidence(up, repo).run(
      ACCOUNT_ID,
      file,
      "ownership-certificate",
    );

    expect(vi.mocked(up.upload).mock.calls[0][1]).toStrictEqual(TERMS);
    expect(repo.attach).toHaveBeenCalledWith(ACCOUNT_ID, DOCUMENT_ID, "ownership-certificate");
    expect(result).toBe(EVIDENCE);
  });

  it("reports its stages explicitly: upload, finishing after the last byte, attach after the upload resolved", async () => {
    const events: string[] = [];
    const repo = repository({
      attach: vi.fn(async () => {
        events.push("attach called");
        return EVIDENCE;
      }),
    });
    await new AttachBankAccountEvidence(drivingUploader("filed"), repo).run(
      ACCOUNT_ID,
      file,
      "ownership-certificate",
      {
        onStage: (stage) => events.push(stage),
        onProgress: (p) => events.push(`${p.loaded}/${p.total}`),
      },
    );

    expect(events).toEqual(["upload", "3/3", "finishing", "attach", "attach called"]);
  });

  it("an upload failure before the body started says nothing can have been filed", async () => {
    const repo = repository();
    const failure = await new AttachBankAccountEvidence(drivingUploader("fail-before-body"), repo)
      .run(ACCOUNT_ID, file, "ownership-certificate")
      .catch((e: unknown) => e);

    expect(failure).toBeInstanceOf(EvidenceAttachFailure);
    expect(failure).toMatchObject({ stage: "upload", contentSent: false, documentId: null });
    expect(repo.attach).not.toHaveBeenCalled();
  });

  it("an upload failure during the body says the document may have been filed", async () => {
    const failure = await new AttachBankAccountEvidence(
      drivingUploader("fail-during-body"),
      repository(),
    )
      .run(ACCOUNT_ID, file, "ownership-certificate")
      .catch((e: unknown) => e);

    expect(failure).toMatchObject({ stage: "upload", contentSent: true });
  });

  it("an attach failure carries the filed document id", async () => {
    const cause = httpError("about:blank", 503);
    await expect(
      new AttachBankAccountEvidence(
        uploader(),
        repository({ attach: vi.fn().mockRejectedValue(cause) }),
      ).run(ACCOUNT_ID, file, "ownership-certificate"),
    ).rejects.toMatchObject({ stage: "attach", cause, documentId: DOCUMENT_ID });
  });

  describe("retryAttach", () => {
    it("attaches the same document again, with no upload", async () => {
      const up = uploader();
      const repo = repository();
      await expect(
        new AttachBankAccountEvidence(up, repo).retryAttach(
          ACCOUNT_ID,
          DOCUMENT_ID,
          "ownership-certificate",
        ),
      ).resolves.toBe(EVIDENCE);
      expect(repo.attach).toHaveBeenCalledWith(ACCOUNT_ID, DOCUMENT_ID, "ownership-certificate");
      expect(up.upload).not.toHaveBeenCalled();
    });

    it("treats already-attached as the success of the earlier attach", async () => {
      const repo = repository({
        attach: vi.fn().mockRejectedValue(httpError("bank-account-evidence-already-attached", 409)),
      });
      await expect(
        new AttachBankAccountEvidence(uploader(), repo).retryAttach(
          ACCOUNT_ID,
          DOCUMENT_ID,
          "ownership-certificate",
        ),
      ).resolves.toBeNull();
    });

    it("rethrows any other failure as an attach failure carrying the document id", async () => {
      const cause = httpError("bank-account-evidence-unavailable", 422);
      await expect(
        new AttachBankAccountEvidence(
          uploader(),
          repository({ attach: vi.fn().mockRejectedValue(cause) }),
        ).retryAttach(ACCOUNT_ID, DOCUMENT_ID, "ownership-certificate"),
      ).rejects.toMatchObject({ stage: "attach", cause, documentId: DOCUMENT_ID });
    });
  });
});

describe("ListBankAccountEvidence", () => {
  it("lists the account's evidence through the port", async () => {
    const repo = repository();
    await expect(new ListBankAccountEvidence(repo).run(ACCOUNT_ID)).resolves.toEqual([EVIDENCE]);
    expect(repo.list).toHaveBeenCalledWith(ACCOUNT_ID);
  });
});
