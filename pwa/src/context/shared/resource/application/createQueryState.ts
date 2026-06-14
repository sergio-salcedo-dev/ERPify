"use client";

import { useState } from "react";
import type { ResourceSort } from "../domain/ResourceSort";

/** Generic, entity-agnostic query state (filter object + sort + page size). */
export interface QueryState<F> {
  filter: F;
  setFilter: (f: F) => void;
  sort: ResourceSort | null;
  setSort: (s: ResourceSort | null) => void;
  pageSize: number;
  setPageSize: (n: number) => void;
  /** Clears filter + sort back to their defaults; page size is left untouched. */
  reset: () => void;
}

export interface QueryStateConfig<F> {
  emptyFilter: F;
  defaultSort: ResourceSort | null;
  defaultPageSize: number;
}

/**
 * Hook factory owning filter, sort, and page-size state. `reset()` returns the
 * filter and sort to their defaults; page size is a persistent viewing
 * preference and survives a reset (it is changed only via `setPageSize`).
 */
export function useQueryState<F>({
  emptyFilter,
  defaultSort,
  defaultPageSize,
}: QueryStateConfig<F>): QueryState<F> {
  const [filter, setFilter] = useState<F>(emptyFilter);
  const [sort, setSort] = useState<ResourceSort | null>(defaultSort);
  const [pageSize, setPageSize] = useState<number>(defaultPageSize);
  const reset = (): void => {
    setFilter(emptyFilter);
    setSort(defaultSort);
  };
  return { filter, setFilter, sort, setSort, pageSize, setPageSize, reset };
}
