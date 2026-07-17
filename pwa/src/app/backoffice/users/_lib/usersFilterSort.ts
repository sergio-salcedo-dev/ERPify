import type { DataTableSort } from "@/components/erpify";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import type { UserStatus } from "@/context/shared/access/domain/UserStatus";

export interface UsersFilter {
  email: string;
  status: UserStatus | "";
}
export type UsersSort = DataTableSort | null;

/** Default sort: alphabetical by email, A → Z. */
export const DEFAULT_SORT: UsersSort = { columnId: "email", direction: SortDirection.ASC };
export const EMPTY_FILTER: UsersFilter = { email: "", status: "" };

export function isDefaultSort(sort: UsersSort): boolean {
  if (!sort || !DEFAULT_SORT) return sort === DEFAULT_SORT;
  return sort.columnId === DEFAULT_SORT.columnId && sort.direction === DEFAULT_SORT.direction;
}
export function hasActiveFilter(f: UsersFilter): boolean {
  return Boolean(f.email.trim()) || f.status !== "";
}
export const USERS_SORTABLE_COLUMNS = [
  { id: "email", label: "Email" },
  { id: "status", label: "Status" },
  { id: "createdAt", label: "Created" },
  { id: "updatedAt", label: "Updated" },
] as const;
