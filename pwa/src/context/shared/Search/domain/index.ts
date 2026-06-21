/**
 * Search filter vocabulary barrel. Bounded contexts import the generic filter
 * types from here; the wire serialization lives in
 * `context/shared/Search/infrastructure`.
 */
export { FilterOperator } from "./Filter";
export type { Filter, ListFilter, ScalarFilter } from "./Filter";
export type { PageEnvelope } from "./PageEnvelope";
export type { PaginationLinks } from "./PaginationLinks";
export { WIRE_MAX_LIMIT } from "./paginationLimits";
