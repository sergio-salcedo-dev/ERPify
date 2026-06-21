import { describe, expect, it } from "vitest";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";

describe("safeHref", () => {
  it("returns relative paths unchanged", () => {
    expect(safeHref("/backoffice/banks")).toBe("/backoffice/banks");
    expect(safeHref("./relative")).toBe("./relative");
    expect(safeHref("../up")).toBe("../up");
    expect(safeHref("#anchor")).toBe("#anchor");
    expect(safeHref("?q=1")).toBe("?q=1");
  });

  it("returns http(s) absolute URLs unchanged", () => {
    expect(safeHref("https://example.com/path")).toBe("https://example.com/path");
    expect(safeHref("http://localhost:8000/api")).toBe("http://localhost:8000/api");
  });

  it("returns mailto/tel/sms unchanged", () => {
    expect(safeHref("mailto:user@example.com")).toBe("mailto:user@example.com");
    expect(safeHref("tel:+34900000000")).toBe("tel:+34900000000");
  });

  it("rejects javascript: in any casing", () => {
    expect(safeHref("javascript:alert(1)")).toBe("#");
    expect(safeHref("JavaScript:alert(1)")).toBe("#");
    expect(safeHref("JAVASCRIPT:alert(1)")).toBe("#");
  });

  it("rejects javascript: hidden behind whitespace, tabs, and newlines", () => {
    expect(safeHref("  javascript:alert(1)")).toBe("#");
    expect(safeHref("java\tscript:alert(1)")).toBe("#");
    expect(safeHref("java\nscript:alert(1)")).toBe("#");
    expect(safeHref("java script:alert(1)")).toBe("#");
  });

  it("rejects data: URLs (HTML payloads can carry inline scripts)", () => {
    expect(safeHref("data:text/html,<script>alert(1)</script>")).toBe("#");
    expect(safeHref("DATA:text/html,xss")).toBe("#");
  });

  it("rejects vbscript: and file:", () => {
    expect(safeHref("vbscript:msgbox(1)")).toBe("#");
    expect(safeHref("file:///etc/passwd")).toBe("#");
  });

  it("returns the supplied fallback for unsafe input", () => {
    expect(safeHref("javascript:alert(1)", "/safe")).toBe("/safe");
  });

  it("returns the fallback for empty / null / undefined / whitespace input", () => {
    expect(safeHref(undefined)).toBe("#");
    expect(safeHref(null)).toBe("#");
    expect(safeHref("")).toBe("#");
    expect(safeHref("   ")).toBe("#");
  });
});
