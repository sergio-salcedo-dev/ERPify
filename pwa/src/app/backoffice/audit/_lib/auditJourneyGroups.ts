import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";

/** A reconstructed session: the rows of one `correlation_id`, in chronological (ascending) order. */
export interface AuditJourneyGroup {
  correlationId: string;
  count: number;
  /** Earliest / latest instant in the session, for the `HH:MM`–`HH:MM` window header. */
  startIso: string;
  endIso: string;
  entries: ReadonlyArray<AuditEntry>;
}

/**
 * Reconstructs an actor's jornada client-side from the visible (keyset-paginated) page: groups by
 * `correlation_id`, sessions ordered by first appearance (the page is newest-first, so sessions read
 * newest → oldest), and entries within a session flipped to chronological ascending — "asc within a
 * session, sessions desc" (D6: no session table; the grouping is purely a render of already-fetched
 * rows). The grouping reflects only the visible page — a session that spills onto the next keyset
 * page simply continues there.
 */
export function groupEntriesByCorrelation(entries: ReadonlyArray<AuditEntry>): AuditJourneyGroup[] {
  const order: string[] = [];
  const buckets = new Map<string, AuditEntry[]>();
  for (const entry of entries) {
    const bucket = buckets.get(entry.correlationId);
    if (bucket) {
      bucket.push(entry);
    } else {
      buckets.set(entry.correlationId, [entry]);
      order.push(entry.correlationId);
    }
  }
  return order.map((correlationId) => {
    const bucket = buckets.get(correlationId) ?? [];
    const ascending = [...bucket].sort((a, b) => a.occurredOn.localeCompare(b.occurredOn));
    return {
      correlationId,
      count: ascending.length,
      startIso: ascending[0]?.occurredOn ?? "",
      endIso: ascending.at(-1)?.occurredOn ?? "",
      entries: ascending,
    };
  });
}
