import type { SortDirection } from "@/context/shared/domain/types/sorting";

/** Server-side sort: a public field name + direction. */
export interface ResourceSort {
  field: string;
  direction: SortDirection;
}
