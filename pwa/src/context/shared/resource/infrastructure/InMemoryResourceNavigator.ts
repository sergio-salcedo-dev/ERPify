import type { ResourceSearchNavigator } from "../domain/ResourceSearchNavigator";
import type { ResourceSearchPage } from "../domain/CrudRepository";
import type { InMemoryCrudRepository } from "./InMemoryCrudRepository";
import { decodeCursor } from "./cursorLink";

/**
 * Stateless navigator over an in-memory repository. The opaque link carries the
 * full query and the page offset, so `follow(link)` decodes both and re-runs the
 * slice with no remembered state — exactly how a real cursor URL encodes its own
 * query server-side. There is no mutable navigator state and no side channel.
 */
export class InMemoryResourceNavigator<
  T extends { id: string },
  TInput,
> implements ResourceSearchNavigator<T> {
  constructor(private readonly repository: InMemoryCrudRepository<T, TInput>) {}

  async follow(link: string): Promise<ResourceSearchPage<T>> {
    const { criteria, offset } = decodeCursor(link);
    return this.repository.searchAt(criteria, offset);
  }
}
