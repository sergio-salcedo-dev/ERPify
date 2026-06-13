import { describe, it, expect } from "vitest";
import {
  InMemoryCrudRepository,
  type InMemoryEntityConfig,
} from "@/context/shared/resource/infrastructure/InMemoryCrudRepository";
import { InMemoryResourceNavigator } from "@/context/shared/resource/infrastructure/InMemoryResourceNavigator";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import { FilterOperator, type Filter } from "@/context/shared/domain/Search";

interface Row {
  id: string;
  name: string;
  n: number;
}
type RowInput = { name: string; n: number };

const config: InMemoryEntityConfig<Row, RowInput> = {
  matchesFilter: (row, filter) =>
    filter.field === "name" && filter.operator === FilterOperator.Contains
      ? row.name.toLowerCase().includes(String(filter.value).toLowerCase())
      : true,
  compare: (field) => (a, b) => (field === "n" ? a.n - b.n : a.name.localeCompare(b.name)),
  fromInput: (input, id) => ({ id, name: input.name, n: input.n }),
  applyInput: (row, input) => ({ ...row, name: input.name, n: input.n }),
};

function repo() {
  const seed: Row[] = Array.from({ length: 7 }, (_, i) => ({ id: `id-${i}`, name: `n${i}`, n: i }));
  return new InMemoryCrudRepository<Row, RowInput>(
    seed,
    config,
    () => "new-id",
    () => "2026-01-01T00:00:00.000Z",
  );
}

describe("InMemoryCrudRepository", () => {
  it("returns the first page with hasNext and a next link, no prev", async () => {
    const page = await repo().search({ filters: [], sort: null, limit: 3 });
    expect(page.items).toHaveLength(3);
    expect(page.hasNext).toBe(true);
    expect(page.hasPrev).toBe(false);
    expect(page.links.next).toMatch(/^\//);
    expect(page.links.prev).toBeNull();
  });

  it("navigates next via the opaque link", async () => {
    const r = repo();
    const nav = new InMemoryResourceNavigator<Row, RowInput>(r);
    const criteria = { filters: [], sort: null, limit: 3 };
    // The navigator continues the live query, so the consumer (useResourceList)
    // hands it the same criteria before following a link.
    nav.remember(criteria);
    const first = await r.search(criteria);
    const second = await nav.follow(first.links.next!);
    expect(second.items.map((x) => x.id)).toEqual(["id-3", "id-4", "id-5"]);
    expect(second.hasPrev).toBe(true);
    expect(second.hasNext).toBe(true);
  });

  it("filters by name contains", async () => {
    const page = await repo().search({
      filters: [{ field: "name", operator: FilterOperator.Contains, value: "n5" } as Filter],
      sort: null,
      limit: 25,
    });
    expect(page.items.map((x) => x.id)).toEqual(["id-5"]);
  });

  it("sorts descending by n", async () => {
    const page = await repo().search({
      filters: [],
      sort: { field: "n", direction: SortDirection.DESC },
      limit: 2,
    });
    expect(page.items.map((x) => x.n)).toEqual([6, 5]);
  });

  it("create/find/update/delete round-trip", async () => {
    const r = repo();
    const created = await r.create({ name: "zz", n: 99 });
    expect(created.id).toBe("new-id");
    expect((await r.find("new-id")).name).toBe("zz");
    await r.update("new-id", { name: "yy", n: 1 });
    expect((await r.find("new-id")).name).toBe("yy");
    await r.delete("new-id");
    await expect(r.find("new-id")).rejects.toThrow();
  });
});
