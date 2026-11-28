import { describe, expect, it } from "vitest";
import {
  checkDocumentFile,
  MAX_DOCUMENT_BYTES,
  normalisedFilename,
} from "@/context/backoffice/document/domain/checkDocumentFile";

describe("checkDocumentFile", () => {
  it("admits any type at any size from one byte to the cap", () => {
    expect(checkDocumentFile({ name: "drawing.dwg", size: 1 })).toEqual({ ok: true });
    expect(checkDocumentFile({ name: "scan.pdf", size: MAX_DOCUMENT_BYTES })).toEqual({ ok: true });
  });

  it("refuses an empty file and one over the cap", () => {
    expect(checkDocumentFile({ name: "a.pdf", size: 0 }).ok).toBe(false);
    expect(checkDocumentFile({ name: "a.pdf", size: MAX_DOCUMENT_BYTES + 1 }).ok).toBe(false);
  });

  it("refuses a blank name and one over 255 UTF-8 bytes once normalised", () => {
    expect(checkDocumentFile({ name: "   ", size: 1 }).ok).toBe(false);
    expect(checkDocumentFile({ name: "a".repeat(255), size: 1 })).toEqual({ ok: true });
    expect(checkDocumentFile({ name: "a".repeat(256), size: 1 }).ok).toBe(false);
    // 128 two-byte characters: 128 characters, 256 bytes.
    expect(checkDocumentFile({ name: "é".repeat(128), size: 1 }).ok).toBe(false);
  });

  it("normalises a decomposed name to NFC", () => {
    expect(normalisedFilename({ name: "Café.pdf" })).toBe("Café.pdf");
  });
});
