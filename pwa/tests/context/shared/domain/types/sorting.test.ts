import { describe, expect, it } from "vitest";
import { SortDirection } from "@/context/shared/domain/types/sorting";

describe("SortDirection", () => {
  it("exposes ASC and DESC", () => {
    expect(SortDirection).toEqual({
      ASC: "asc",
      DESC: "desc",
    });
  });

  it("derives the matching union type via keyof typeof", () => {
    const allowed: SortDirection[] = [SortDirection.ASC, SortDirection.DESC];
    expect(allowed).toHaveLength(2);
  });
});
