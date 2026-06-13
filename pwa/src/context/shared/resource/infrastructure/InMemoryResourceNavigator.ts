import type { ResourceSearchNavigator } from "../domain/ResourceSearchNavigator";
import type { ResourceSearchCriteria, ResourceSearchPage } from "../domain/CrudRepository";
import type { InMemoryCrudRepository } from "./InMemoryCrudRepository";
import { decodeCursorOffset } from "./cursorLink";

/**
 * Generic navigator over an in-memory repository. Decodes the opaque link's
 * offset and re-runs the slice. The criteria are remembered from the last
 * search so a follow continues the same query (mirrors how a real link encodes
 * the full query server-side).
 */
export class InMemoryResourceNavigator<T extends { id: string }, TInput>
  implements ResourceSearchNavigator<T>
{
  private lastCriteria: ResourceSearchCriteria = { filters: [], sort: null, limit: 25 };

  constructor(private readonly repository: InMemoryCrudRepository<T, TInput>) {}

  /** Called by useResourceList before navigation so follow() reuses the live query. */
  remember(criteria: ResourceSearchCriteria): void {
    this.lastCriteria = criteria;
  }

  async follow(link: string): Promise<ResourceSearchPage<T>> {
    const offset = decodeCursorOffset(link);
    return this.repository.searchAt(this.lastCriteria, offset);
  }
}
