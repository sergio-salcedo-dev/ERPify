/**
 * Search filter vocabulary barrel. Bounded contexts import the generic filter
 * types from here; the wire serialization lives in
 * `context/shared/infrastructure/Search`.
 */
export { FilterOperator } from "./Filter";
export type { Filter, ListFilter, ScalarFilter } from "./Filter";
