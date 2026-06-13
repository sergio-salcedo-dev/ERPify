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
  reset: () => void;
}

export interface QueryStateConfig<F> {
  emptyFilter: F;
  defaultSort: ResourceSort | null;
  defaultPageSize: number;
}

/** Hook factory: owns filter/sort/pageSize state with a single reset. */
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
