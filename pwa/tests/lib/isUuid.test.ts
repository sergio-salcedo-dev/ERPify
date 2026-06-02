import { describe, expect, it } from "vitest";
import { isUuid } from "@/lib/isUuid";

describe("isUuid", () => {
  it("accepts canonical UUIDs (incl. the v7 ids the stack uses)", () => {
    expect(isUuid("0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b")).toBe(true); // v7
    expect(isUuid("2e6d865c-17b0-476a-85f2-037bf6d3b3dc")).toBe(true); // v4
    expect(isUuid("0190A1B2-C3D4-7E5F-8A9B-0C1D2E3F4A5B")).toBe(true); // upper-case
  });

  it("rejects non-UUID strings", () => {
    expect(isUuid("")).toBe(false);
    expect(isUuid("not-a-uuid")).toBe(false);
    expect(isUuid("123")).toBe(false);
    // wrong length / stray characters
    expect(isUuid("0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5")).toBe(false);
    expect(isUuid("0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5bb")).toBe(false);
    expect(isUuid(" 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b ")).toBe(false);
    // invalid version (0) / variant (c) nibbles
    expect(isUuid("0190a1b2-c3d4-0e5f-8a9b-0c1d2e3f4a5b")).toBe(false);
    expect(isUuid("0190a1b2-c3d4-7e5f-ca9b-0c1d2e3f4a5b")).toBe(false);
  });
});
