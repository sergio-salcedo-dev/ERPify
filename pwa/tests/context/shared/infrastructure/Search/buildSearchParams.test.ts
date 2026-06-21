import { describe, expect, it } from "vitest";
import type { Filter } from "@/context/shared/Search/domain";
import { buildSearchParams } from "@/context/shared/Search/infrastructure";

describe("buildSearchParams", () => {
  it("serializes a scalar eq filter into the wire grammar", () => {
    const filters: Filter[] = [{ field: "id", operator: "eq", value: "abc" }];

    const params = buildSearchParams(filters);

    expect(params.get("filters[0][field]")).toBe("id");
    expect(params.get("filters[0][operator]")).toBe("eq");
    expect(params.get("filters[0][value]")).toBe("abc");
    expect(params.getAll("filters[0][value][]")).toEqual([]);
  });

  it("serializes a scalar contains filter", () => {
    const filters: Filter[] = [{ field: "name", operator: "contains", value: "banc" }];

    const params = buildSearchParams(filters);

    expect(params.get("filters[0][field]")).toBe("name");
    expect(params.get("filters[0][operator]")).toBe("contains");
    expect(params.get("filters[0][value]")).toBe("banc");
  });

  it("serializes an in filter as a repeated value[] key per item", () => {
    const filters: Filter[] = [{ field: "shortName", operator: "in", value: ["ES", "PT"] }];

    const params = buildSearchParams(filters);

    expect(params.get("filters[0][field]")).toBe("shortName");
    expect(params.get("filters[0][operator]")).toBe("in");
    expect(params.getAll("filters[0][value][]")).toEqual(["ES", "PT"]);
    expect(params.get("filters[0][value]")).toBeNull();
  });

  it("serializes several filters with contiguous indices from 0", () => {
    const filters: Filter[] = [
      { field: "name", operator: "contains", value: "banc" },
      { field: "createdAt", operator: "gte", value: "2026-01-01T00:00:00Z" },
      { field: "shortName", operator: "in", value: ["ES", "PT"] },
    ];

    const params = buildSearchParams(filters);

    expect(params.get("filters[0][field]")).toBe("name");
    expect(params.get("filters[0][operator]")).toBe("contains");
    expect(params.get("filters[0][value]")).toBe("banc");
    expect(params.get("filters[1][field]")).toBe("createdAt");
    expect(params.get("filters[1][operator]")).toBe("gte");
    expect(params.get("filters[1][value]")).toBe("2026-01-01T00:00:00Z");
    expect(params.get("filters[2][field]")).toBe("shortName");
    expect(params.get("filters[2][operator]")).toBe("in");
    expect(params.getAll("filters[2][value][]")).toEqual(["ES", "PT"]);
  });

  it("emits no params for an empty filter list", () => {
    const params = buildSearchParams([]);

    expect(params.toString()).toBe("");
  });
});
