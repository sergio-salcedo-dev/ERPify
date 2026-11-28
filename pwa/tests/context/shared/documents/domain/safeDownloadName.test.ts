import { describe, expect, it } from "vitest";
import { safeDownloadName } from "@/context/shared/documents/domain/safeDownloadName";

describe("safeDownloadName", () => {
  it.each([
    "certificate.pdf",
    "Certificado de titularidad (copia) 2026.pdf",
    "título_bancario-1.pdf",
    "a".repeat(255),
  ])("keeps %j", (name) => {
    expect(safeDownloadName(name)).toBe(name);
  });

  it("keeps the NFC form of a decomposed name", () => {
    expect(safeDownloadName("té.pdf")).toBe("té.pdf");
  });

  it.each([
    ["absent", null],
    ["empty", ""],
    ["a leading dot", ".bashrc"],
    ["a path separator", "../etc/passwd"],
    ["a backslash", "a\\b.pdf"],
    ["a bidi override", "invoice‮fdp.exe"],
    ["a control character", "a\u0007.pdf"],
    ["a colon", "C:file.pdf"],
    ["an angle bracket", "<script>.pdf"],
    ["over 255 characters", "a".repeat(256)],
  ])("drops a name with %s", (_label, name) => {
    expect(safeDownloadName(name)).toBeNull();
  });
});
