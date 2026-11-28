import { describe, expect, it } from "vitest";
import { checkOriginalFilename } from "@/context/shared/documents/domain/checkOriginalFilename";

describe("checkOriginalFilename", () => {
  it.each([
    "certificate.pdf",
    "Certificado de titularidad (copia).pdf",
    "título.pdf",
    "a/b\\c.pdf",
    "क्‍ष.pdf", // a zero-width joiner, which real scripts need
    "é".repeat(127) + "a", // 255 bytes exactly
  ])("admits %j", (name) => {
    expect(checkOriginalFilename(name)).toEqual({ ok: true });
  });

  it.each([
    ["empty", ""],
    ["blank", "   "],
    ["a lone surrogate, which cannot be percent-encoded", "a\uD800.pdf"],
    ["not in NFC", "té.pdf"],
    ["over 255 bytes", "é".repeat(128)],
    ["a control character", "a\u0007.pdf"],
    ["a newline", "a\nb.pdf"],
    ["a bidi override", "evil‮fdp.exe"],
    ["a zero-width space", "a​b.pdf"],
    ["a line separator", "a b.pdf"],
    ["a private-use character", "a.pdf"],
  ])("refuses %s", (_label, name) => {
    expect(checkOriginalFilename(name)).toMatchObject({ ok: false });
  });
});
