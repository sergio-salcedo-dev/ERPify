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

  it("refuses a name that is not well-formed Unicode or carries a character the API refuses", () => {
    expect(checkDocumentFile({ name: "a\uD800.pdf", size: 1 }).ok).toBe(false);
    expect(checkDocumentFile({ name: "a\u0007.pdf", size: 1 }).ok).toBe(false);
    expect(checkDocumentFile({ name: "a\u2028.pdf", size: 1 }).ok).toBe(false);
    expect(checkDocumentFile({ name: "a\uE000.pdf", size: 1 }).ok).toBe(false);
    expect(checkDocumentFile({ name: "a\u200B.pdf", size: 1 }).ok).toBe(false);
    expect(checkDocumentFile({ name: "a\u202E.pdf", size: 1 }).ok).toBe(false);
  });

  it("keeps the zero-width joiner and non-joiner, which some scripts need", () => {
    expect(checkDocumentFile({ name: "\u0645\u200C\u06CC.pdf", size: 1 })).toEqual({ ok: true });
    expect(checkDocumentFile({ name: "\u0915\u094D\u200D\u0937.pdf", size: 1 })).toEqual({
      ok: true,
    });
  });

  it("normalises a decomposed name to NFC", () => {
    expect(normalisedFilename({ name: "Café.pdf" })).toBe("Café.pdf");
  });
});
