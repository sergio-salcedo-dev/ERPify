import type { Filter, PageEnvelope } from "@/context/shared/search/domain";
import type { SortDirection } from "@/context/shared/search/domain/SortDirection";
import type { AuditEntry } from "./AuditEntry";

/**
 * Sort axis for the timeline. The 4.1 read model exposes a single sortable column (`occurredOn`,
 * the keyset key); any other field is a 422 server-side. Kept as a `{ field, direction }` pair to
 * mirror the generic search vocabulary without pulling in the CRUD resource toolkit (this surface
 * is read-only — see {@link AuditTimelineRepository}).
 */
export interface AuditSort {
  field: string;
  direction: SortDirection;
}

/** First-page query: filters + optional sort + page size. Cursors never travel here (W11) — */
/** continuing a page is {@link AuditTimelineNavigator.follow} on a server-issued link. */
export interface AuditTimelineCriteria {
  filters: Filter[];
  sort: AuditSort | null;
  limit: number;
}

/** A timeline page: the rows plus the cursor-only pagination envelope, carried verbatim. */
export type AuditTimelinePage = { entries: AuditEntry[] } & PageEnvelope;

/**
 * Read-only port over the 4.1 timeline read model. Deliberately NOT a `CrudRepository`: Backoffice
 * only consumes auditoría (D5), so there is no find/create/update/delete to honour — a read-only
 * port keeps the contract honest (ISP) and never forces a throwing write stub.
 */
export interface AuditTimelineRepository {
  search(criteria: AuditTimelineCriteria): Promise<AuditTimelinePage>;
}

/**
 * Cursor navigation port: follows a server-composed pagination link verbatim. The link is opaque
 * and self-contained, so the navigator needs nothing but the link itself — it is never parsed or
 * rebuilt client-side.
 */
export interface AuditTimelineNavigator {
  follow(link: string): Promise<AuditTimelinePage>;
}
