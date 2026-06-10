/**
 * Generic search-filter vocabulary — the TypeScript mirror of the PHP
 * `Erpify\Shared\Domain\Search\{Filter,FilterOperator}` contract.
 *
 * The operator string IS the wire token (`filters[N][operator]`), so each
 * value must match the PHP enum's backing string exactly. The PHP enum carries
 * seven operators; this mirror exposes only the five with a real PWA consumer
 * or test (`gt`/`lt` are omitted until one exists — YAGNI).
 */

export const FilterOperator = {
  Eq: "eq",
  In: "in",
  Contains: "contains",
  Gte: "gte",
  Lte: "lte",
} as const;

export type FilterOperator = (typeof FilterOperator)[keyof typeof FilterOperator];

/** Operators carrying a single scalar value (`filters[N][value]`). */
export interface ScalarFilter {
  field: string;
  operator: Exclude<FilterOperator, typeof FilterOperator.In>;
  value: string;
}

/** The `in` operator carries a list (`filters[N][value][]`). */
export interface ListFilter {
  field: string;
  operator: typeof FilterOperator.In;
  value: string[];
}

/**
 * A single search filter. `field` is the public field name (never a DQL path);
 * allow-list membership is enforced server-side by the applier.
 */
export type Filter = ScalarFilter | ListFilter;
