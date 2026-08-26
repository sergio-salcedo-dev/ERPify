import { describe, expect, it } from "vitest";
import {
  ChangeKind,
  changeKind,
  isNoOpChange,
} from "@/context/backoffice/audit/domain/AuditChange";

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

describe("changeKind", () => {
  it("classifies every corner of the (null, non-null) square", () => {
    expect(changeKind({ old: null, new: "BBVAESMM" })).toBe(ChangeKind.Added);
    expect(changeKind({ old: "BBVA", new: null })).toBe(ChangeKind.Removed);
    expect(changeKind({ old: "BBVA", new: "BBVA S.A." })).toBe(ChangeKind.Changed);
    expect(changeKind({ old: null, new: null })).toBe(ChangeKind.Empty);
  });

  it("treats an empty string as a value, so a cleared field is removed and not empty", () => {
    expect(changeKind({ old: "", new: null })).toBe(ChangeKind.Removed);
    expect(changeKind({ old: null, new: "" })).toBe(ChangeKind.Added);
  });

  it("classifies a sealed value as a present side, never as empty", () => {
    expect(changeKind({ old: null, new: { __enc__: "c2VjcmV0" } })).toBe(ChangeKind.Added);
  });

  it("agrees with isNoOpChange in both directions, so the two predicates cannot drift", () => {
    const cases = [
      { old: null, new: null },
      { old: null, new: "x" },
      { old: "x", new: null },
      { old: "x", new: "y" },
      { old: "", new: null },
    ] as const;

    for (const change of cases) {
      expect(changeKind(change) === ChangeKind.Empty).toBe(isNoOpChange(change));
    }
  });
});
