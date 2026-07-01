import { describe, expect, it } from "vitest";
import { isNoOpChange } from "@/context/backoffice/audit/domain/AuditChange";

describe("isNoOpChange", () => {
  it("is true when both sides are null (a field with no value)", () => {
    expect(isNoOpChange({ old: null, new: null })).toBe(true);
  });

  it("is false for an added field (null → value)", () => {
    expect(isNoOpChange({ old: null, new: "BBVAESMM" })).toBe(false);
  });

  it("is false for a removed field (value → null)", () => {
    expect(isNoOpChange({ old: "BBVA", new: null })).toBe(false);
  });

  it("is false for a changed field (value → value)", () => {
    expect(isNoOpChange({ old: "BBVA", new: "BBVA S.A." })).toBe(false);
  });

  it("treats an empty string as a value, not a no-op", () => {
    expect(isNoOpChange({ old: "", new: null })).toBe(false);
    expect(isNoOpChange({ old: null, new: "" })).toBe(false);
  });
});
