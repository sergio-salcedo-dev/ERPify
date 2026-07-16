import { FilterOperator, type Filter } from "@/context/shared/search/domain";
import type { ResourceSort } from "@/context/shared/resource/domain/ResourceSort";
import type { UsersFilter, UsersSort } from "./usersFilterSort";

/** Maps the users UI filter state to the generic `Filter[]` vocabulary. */
export function toUserFilters(filter: UsersFilter): Filter[] {
  const filters: Filter[] = [];
  const email = filter.email.trim();
  if (email) filters.push({ field: "email", operator: FilterOperator.Contains, value: email });
  if (filter.status)
    filters.push({ field: "status", operator: FilterOperator.Eq, value: filter.status });
  return filters;
}

/** Maps the UI sort (table column id + direction) to the domain sort, or null. */
export function toUserSort(sort: UsersSort): ResourceSort | null {
  return sort ? { field: sort.columnId, direction: sort.direction } : null;
}
