import { describe, expect, it } from "vitest";
import { checkDocumentFile } from "@/context/shared/documents/domain/checkDocumentFile";
import {
  MAX_DOCUMENT_BYTES,
  uploadBudgetMs,
} from "@/context/shared/documents/domain/documentLimits";

describe("checkDocumentFile", () => {
  it("refuses an empty file", () => {
    expect(checkDocumentFile({ size: 0 })).toMatchObject({ ok: false, reason: "empty" });
  });

  it("admits exactly the cap and refuses one byte over it", () => {
    expect(checkDocumentFile({ size: MAX_DOCUMENT_BYTES })).toEqual({ ok: true });
    expect(checkDocumentFile({ size: MAX_DOCUMENT_BYTES + 1 })).toMatchObject({
      ok: false,
      reason: "too-large",
      message: "This file is larger than 100 MB. Choose a smaller one.",
    });
  });

  it("mirrors the API cap of 104857600 bytes", () => {
    expect(MAX_DOCUMENT_BYTES).toBe(104_857_600);
  });
});

describe("uploadBudgetMs", () => {
  it("never gives up before the server's own deadline for the size", () => {
    // Server: ceil(size / 256 KiB) + 60 s; a 100 MiB body gets 460 s.
    expect(uploadBudgetMs(MAX_DOCUMENT_BYTES)).toBeGreaterThan(460_000);
    expect(uploadBudgetMs(1)).toBeGreaterThan(61_000);
  });
});
