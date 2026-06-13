import type { ResourceSearchCriteria } from "../domain/CrudRepository";

/**
 * Opaque pagination link encode/decode for the in-memory repository. Mirrors the
 * real backend contract: the CLIENT treats links as opaque transport tokens and
 * never decodes them — only this repository/navigator (the "server") does. The
 * link is a same-origin relative URL carrying a base64 payload so it passes the
 * navigator's same-origin guard exactly like a real API link.
 *
 * The payload is self-contained: it carries the full query (filters, sort, limit)
 * plus the page offset. The navigator is therefore stateless — `follow(link)`
 * needs nothing but the link, exactly as a real cursor URL encodes its own query
 * server-side. No `remember()`, no mutable navigator state.
 */
const PATH = "/__mock__/resource";

interface CursorPayload {
  criteria: ResourceSearchCriteria;
  offset: number;
}

export interface DecodedCursor {
  criteria: ResourceSearchCriteria;
  offset: number;
}

export function encodeCursorLink(criteria: ResourceSearchCriteria, offset: number): string {
  const payload: CursorPayload = { criteria, offset };
  const token = globalThis.btoa(JSON.stringify(payload));
  return `${PATH}?cursor=${encodeURIComponent(token)}`;
}

const EMPTY_CRITERIA: ResourceSearchCriteria = { filters: [], sort: null, limit: 25 };

/**
 * Decode a link the repository issued. A missing/forged/corrupt token degrades to
 * an empty first page rather than throwing — the same safe default a server gives
 * a tampered cursor. The offset is accepted only when it is a non-negative integer.
 */
export function decodeCursor(link: string): DecodedCursor {
  try {
    const url = new URL(link, "https://mock.invalid");
    const token = url.searchParams.get("cursor");
    if (!token) return { criteria: EMPTY_CRITERIA, offset: 0 };
    const parsed = JSON.parse(globalThis.atob(token)) as Partial<CursorPayload>;
    const offset =
      typeof parsed.offset === "number" && Number.isInteger(parsed.offset) && parsed.offset >= 0
        ? parsed.offset
        : 0;
    const criteria = isCriteria(parsed.criteria) ? parsed.criteria : EMPTY_CRITERIA;
    return { criteria, offset };
  } catch {
    return { criteria: EMPTY_CRITERIA, offset: 0 };
  }
}

function isCriteria(value: unknown): value is ResourceSearchCriteria {
  if (value === null || typeof value !== "object") return false;
  const candidate = value as Partial<ResourceSearchCriteria>;
  return Array.isArray(candidate.filters) && typeof candidate.limit === "number";
}
