import { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { ResourceSearchPage } from "@/context/shared/resource/domain/CrudRepository";

/**
 * Builds a `ResourceSearchPage` in the shape `BackOfficeBankCrudRepository`
 * resolves to under the cursor-only contract (items in `items`). Defaults to a
 * complete single page (no prev/next affordance, null links, LIGHT `count`);
 * pass `overrides` to vary any field — e.g.
 * `{ hasNext: true, links: { next: "/api/...", prev: null } }`.
 */
export function searchPage(
  banks: Bank[],
  overrides: Partial<ResourceSearchPage<Bank>> = {},
): ResourceSearchPage<Bank> {
  return {
    items: banks,
    hasNext: false,
    hasPrev: false,
    count: null,
    links: { next: null, prev: null },
    ...overrides,
  };
}

/**
 * Canonical bank pair for the banks list specs. Shared so every spec
 * exercises the same ids/names instead of re-declaring its own primitives.
 * Keep these deeply immutable — specs import the same instances, so a
 * mutable field would couple tests across files.
 */
export const ACME = Bank.fromPrimitives({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-04-15T14:30:00Z",
  accountCount: 0,
});

export const BETA = Bank.fromPrimitives({
  id: "22222222-2222-4222-8222-222222222222",
  name: "Beta Bank",
  shortName: "BETA",
  createdAt: "2026-01-02T10:00:00Z",
  updatedAt: "2026-04-16T14:30:00Z",
  accountCount: 0,
});
