import { describe, expect, it, vi } from "vitest";
import { AttachBankAccountEvidence } from "@/context/backoffice/bankaccount/application/AttachBankAccountEvidence";
import { DetachBankAccountEvidence } from "@/context/backoffice/bankaccount/application/DetachBankAccountEvidence";
import {
  DownloadBankAccountEvidence,
  savedFilename,
} from "@/context/backoffice/bankaccount/application/DownloadBankAccountEvidence";
import type { BankAccountEvidenceRepository } from "@/context/backoffice/bankaccount/domain/BankAccountEvidenceRepository";
import type { DocumentUploader } from "@/context/backoffice/document/domain/DocumentUploader";

const ACCOUNT_ID = "0190e9c2-0000-7000-8000-000000000001";

describe("AttachBankAccountEvidence", () => {
  it("files the original under the kind's terms, then attaches the document it filed", async () => {
    const upload = vi.fn().mockResolvedValue({
      id: "doc-1",
      state: "received",
      digest: "d",
      sizeBytes: 1,
    });
    const attach = vi.fn().mockResolvedValue({ id: "link-1" });
    const repository: BankAccountEvidenceRepository = { list: vi.fn(), attach, detach: vi.fn() };
    const uploader: DocumentUploader = { upload };
    const file = new File(["1"], "certificate.pdf");
    const onProgress = vi.fn();

    await new AttachBankAccountEvidence(uploader, repository).run(
      ACCOUNT_ID,
      file,
      "ownership-certificate",
      { onProgress },
    );

    expect(upload).toHaveBeenCalledWith(
      file,
      { retentionReason: "contract-execution", legalBasis: "legitimate-interests" },
      { onProgress },
    );
    expect(attach).toHaveBeenCalledWith(ACCOUNT_ID, "doc-1", "ownership-certificate");
  });

  it("attaches nothing when the upload is refused", async () => {
    const attach = vi.fn();
    const failure = new Error("refused");

    await expect(
      new AttachBankAccountEvidence(
        { upload: vi.fn().mockRejectedValue(failure) },
        { list: vi.fn(), attach, detach: vi.fn() },
      ).run(ACCOUNT_ID, new File(["1"], "a.pdf"), "ownership-certificate"),
    ).rejects.toBe(failure);
    expect(attach).not.toHaveBeenCalled();
  });

  it("reports the original filed before it sends the attach, so the caller can stop offering a cancel", async () => {
    const order: string[] = [];
    const attach = vi.fn().mockImplementation(async () => {
      order.push("attach");
      return { id: "link-1" };
    });
    const uploader: DocumentUploader = {
      upload: vi.fn().mockImplementation(async () => {
        order.push("upload");
        return { id: "doc-1", state: "received", digest: "d", sizeBytes: 1 };
      }),
    };

    await new AttachBankAccountEvidence(uploader, {
      list: vi.fn(),
      attach,
      detach: vi.fn(),
    }).run(ACCOUNT_ID, new File(["1"], "a.pdf"), "ownership-certificate", {
      onFiled: () => order.push("filed"),
    });

    expect(order).toEqual(["upload", "filed", "attach"]);
  });
});

describe("DetachBankAccountEvidence", () => {
  it("detaches the link from the account it is on", async () => {
    const detach = vi.fn().mockResolvedValue(undefined);

    await new DetachBankAccountEvidence({ list: vi.fn(), attach: vi.fn(), detach }).run(
      ACCOUNT_ID,
      "link-1",
    );

    expect(detach).toHaveBeenCalledWith(ACCOUNT_ID, "link-1");
  });
});

describe("DownloadBankAccountEvidence", () => {
  it("reads the document by id and names the saved file after the original", async () => {
    const blob = new Blob(["1"]);
    const download = vi.fn().mockResolvedValue(blob);

    const saved = await new DownloadBankAccountEvidence({ download }).run({
      documentId: "doc-1",
      name: "certificate.pdf",
    });

    expect(download).toHaveBeenCalledWith("doc-1");
    expect(saved).toEqual({ blob, filename: "certificate.pdf" });
  });

  it("reduces a hostile or missing name to one plain segment", () => {
    expect(savedFilename({ name: "../../etc/passwd" })).toBe(".._.._etc_passwd");
    expect(savedFilename({ name: "a\\b\u0000c\nd.pdf" })).toBe("a_b_c_d.pdf");
    expect(savedFilename({ name: null })).toBe("document");
    expect(savedFilename({ name: ".." })).toBe("document");
    expect(savedFilename({ name: "  " })).toBe("document");
  });
});
