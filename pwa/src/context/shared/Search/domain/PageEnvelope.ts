import type { PaginationLinks } from "./PaginationLinks";

/**
 * The cursor-only pagination envelope a search response carries alongside its
 * data array (PR3). Mirrors the API's `PaginationMeta`: directional affordance
 * flags, an optional total (`null` under LIGHT pagination, where no COUNT
 * runs), and the verbatim navigation `links`. There is no page number — keyset
 * navigation is purely directional.
 *
 * Items travel separately in each context's page type (e.g. `BankSearchPage`
 * composes `{ banks } & PageEnvelope`), so this envelope is intentionally
 * item-agnostic — no speculative `PageEnvelope<T>` generic (OQ-3 / YAGNI).
 */
export interface PageEnvelope {
  hasNext: boolean;
  hasPrev: boolean;
  count: number | null;
  links: PaginationLinks;
}
