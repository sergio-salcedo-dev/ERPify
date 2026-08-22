import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";

/** One day's worth of consecutive timeline rows, in render order. */
export interface AuditDayGroup {
  label: string;
  entries: ReadonlyArray<AuditEntry>;
}

/**
 * Groups already-day-ordered timeline rows into per-day buckets, preserving order. Entries on the
 * same local day are contiguous (the keyset orders by `occurred_on`), so a single linear pass
 * suffices — no sorting, no map. `labelOf` turns an ISO instant into its local day header. The flat
 * roving-focus index is assigned by the renderer, not here, so a regrouping (e.g. into Journey mode)
 * never has to re-thread indices.
 */
export function groupEntriesByDay(
  entries: ReadonlyArray<AuditEntry>,
  labelOf: (iso: string) => string,
): AuditDayGroup[] {
  const groups: AuditDayGroup[] = [];
  for (const entry of entries) {
    const label = labelOf(entry.occurredOn);
    const current = groups.at(-1);
    if (current?.label === label) {
      (current.entries as AuditEntry[]).push(entry);
    } else {
      groups.push({ label, entries: [entry] });
    }
  }
  return groups;
}
