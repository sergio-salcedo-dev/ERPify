import type { SortDirection } from "@/context/shared/search/domain/SortDirection";

/** Server-side sort: a public field name + direction. */
export interface ResourceSort {
  field: string;
  direction: SortDirection;
}
