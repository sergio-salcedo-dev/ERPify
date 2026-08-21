import { describe, expect, it } from "vitest";
import { safeInternalPath } from "@/context/shared/navigation/domain/safeInternalPath";

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

  it("rejects a host smuggled behind stripped whitespace", () => {
    // The class a regex over `String.trim()` structurally cannot see: `trim()` strips
    // TAB/LF/CR only at the ENDS, the WHATWG URL parser strips them ANYWHERE. Measured
    // against `new URL(v, "https://app.example/x")`, each of these resolved to
    // `https://evil.com/` while satisfying "a single leading slash not followed by a
    // slash or a backslash" — a live post-auth open redirect through `?next=`, since
    // `URLSearchParams.get()` decodes `%09` to a raw TAB before the guard ever sees it.
    expect(safeInternalPath("/\t/evil.com", FALLBACK)).toBe(FALLBACK);
    expect(safeInternalPath("/\n/evil.com", FALLBACK)).toBe(FALLBACK);
    expect(safeInternalPath("/\r/evil.com", FALLBACK)).toBe(FALLBACK);
    // The same smuggle behind the backslash arm, which the old regex also passed.
    expect(safeInternalPath("/\t\\evil.com", FALLBACK)).toBe(FALLBACK);
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
