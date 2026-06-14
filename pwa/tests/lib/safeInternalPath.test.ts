import { describe, expect, it } from "vitest";
import { safeInternalPath } from "@/lib/safeInternalPath";

const FALLBACK = "/backoffice";

describe("safeInternalPath", () => {
  it("returns root-relative in-app paths unchanged", () => {
    expect(safeInternalPath("/backoffice/users", FALLBACK)).toBe("/backoffice/users");
    expect(safeInternalPath("/backoffice/banks/123?tab=x#h", FALLBACK)).toBe(
      "/backoffice/banks/123?tab=x#h",
    );
    expect(safeInternalPath("  /backoffice/users  ", FALLBACK)).toBe("/backoffice/users");
  });

  it("rejects absolute URLs (open redirect)", () => {
    expect(safeInternalPath("https://evil.com", FALLBACK)).toBe(FALLBACK);
    expect(safeInternalPath("http://evil.com/path", FALLBACK)).toBe(FALLBACK);
  });

  it("rejects protocol-relative and backslash-smuggled hosts", () => {
    expect(safeInternalPath("//evil.com", FALLBACK)).toBe(FALLBACK);
    expect(safeInternalPath("/\\evil.com", FALLBACK)).toBe(FALLBACK);
    expect(safeInternalPath("/\\/evil.com", FALLBACK)).toBe(FALLBACK);
  });

  it("rejects scheme-bearing and non-rooted values", () => {
    expect(safeInternalPath("javascript:alert(1)", FALLBACK)).toBe(FALLBACK);
    expect(safeInternalPath("relative/path", FALLBACK)).toBe(FALLBACK);
    expect(safeInternalPath("#anchor", FALLBACK)).toBe(FALLBACK);
  });

  it("returns the fallback for empty / null / undefined input", () => {
    expect(safeInternalPath(undefined, FALLBACK)).toBe(FALLBACK);
    expect(safeInternalPath(null, FALLBACK)).toBe(FALLBACK);
    expect(safeInternalPath("", FALLBACK)).toBe(FALLBACK);
    expect(safeInternalPath("   ", FALLBACK)).toBe(FALLBACK);
  });
});
