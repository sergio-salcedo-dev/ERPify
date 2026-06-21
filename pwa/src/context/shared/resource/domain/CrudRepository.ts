import type { Filter, PageEnvelope } from "@/context/shared/Search/domain";
import type { ResourceSort } from "./ResourceSort";

/** Cursor-only search request: filters + optional sort + page size. No cursor here. */
export interface ResourceSearchCriteria {
  filters: Filter[];
  sort: ResourceSort | null;
  limit: number;
}

/** One page of `T` plus the cursor-only envelope (items + hasNext/hasPrev/count/links). */
export type ResourceSearchPage<T> = { items: T[] } & PageEnvelope;

/**
 * Generic CRUD port (the execution-core contract). Entity repositories extend
 * this with their concrete entity + input. A real backend later implements the
 * same shape — swapping the in-memory adapter requires no consumer change.
 */
export interface CrudRepository<T, TInput> {
  search(criteria: ResourceSearchCriteria): Promise<ResourceSearchPage<T>>;
  find(id: string): Promise<T>;
  create(input: TInput): Promise<T>;
  update(id: string, input: TInput): Promise<T>;
  delete(id: string): Promise<void>;
}
