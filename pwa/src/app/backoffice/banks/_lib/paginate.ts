// Page-size options for the banks list, all ≤ WIRE_MAX_LIMIT (100, D-Cap): the
// former 500/1000 were removed because the API caps `limit` at 100 (422
// otherwise). `paginate.test.ts` pins the ≤-cap invariant against the single
// source of truth (`WIRE_MAX_LIMIT`), so a future option can never exceed it.
export const BANKS_PAGE_SIZE_OPTIONS = [25, 50, 100] as const;
export type BanksPageSize = (typeof BANKS_PAGE_SIZE_OPTIONS)[number];
export const BANKS_PAGE_SIZE_DEFAULT: BanksPageSize = 25;

/** Membership guard: narrows an arbitrary page size to a supported option. */
export function isBanksPageSize(value: number): value is BanksPageSize {
  return (BANKS_PAGE_SIZE_OPTIONS as readonly number[]).includes(value);
}
