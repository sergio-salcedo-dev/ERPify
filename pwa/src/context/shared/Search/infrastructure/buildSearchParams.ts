import type { Filter } from "@/context/shared/Search/domain";

/**
 * Serializes generic search filters into the API's `filters[N][field|operator|value]`
 * wire grammar (decisions D1/D2). Indices are contiguous from 0; scalar operators
 * emit `filters[N][value]`, while `in` emits a repeated `filters[N][value][]` per
 * item. An empty list yields an empty `URLSearchParams`.
 *
 * The result is composable: callers merge it with pagination/sort params before
 * hitting the endpoint. The builder serializes what it is given — mapping empty UI
 * fields to filters (and trimming) is the caller's concern, mirroring the API's
 * split of shape validation (mapping) from semantics (applier).
 */
export function buildSearchParams(filters: readonly Filter[]): URLSearchParams {
  const params = new URLSearchParams();

  filters.forEach((filter, index) => {
    const prefix = `filters[${index}]`;
    params.append(`${prefix}[field]`, filter.field);
    params.append(`${prefix}[operator]`, filter.operator);

    if (Array.isArray(filter.value)) {
      filter.value.forEach((item) => params.append(`${prefix}[value][]`, item));
    } else {
      params.append(`${prefix}[value]`, filter.value);
    }
  });

  return params;
}
