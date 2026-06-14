import type { ResourceSearchPage } from "./CrudRepository";

/** Cursor-only navigation: follow a server-issued link verbatim. */
export interface ResourceSearchNavigator<T> {
  follow(link: string): Promise<ResourceSearchPage<T>>;
}
