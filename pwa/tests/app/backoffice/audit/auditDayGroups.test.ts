import { describe, expect, it } from "vitest";
import { groupEntriesByDay } from "@/app/backoffice/audit/_lib/auditDayGroups";
import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";

function entryOn(occurredOn: string, id: string): AuditEntry {
  return {
    id,
    occurredOn,
    level: "activity",
    action: "ROUTE_X",
    actorType: "anonymous",
    actorId: null,
    correlationId: "c",
    resourceType: null,
    resourceId: null,
    actorErased: false,
    resourceErased: false,
  };
}

// Label by the date portion so the grouping is timezone-independent in the test.
const dayOf = (iso: string): string => iso.slice(0, 10);

describe("groupEntriesByDay", () => {
  it("buckets consecutive same-day rows, preserving order", () => {
    const entries = [
      entryOn("2026-06-12T12:00:00+00:00", "a"),
      entryOn("2026-06-12T09:00:00+00:00", "b"),
      entryOn("2026-06-10T23:00:00+00:00", "c"),
    ];

    const groups = groupEntriesByDay(entries, dayOf);

    expect(groups).toHaveLength(2);
    expect(groups[0].label).toBe("2026-06-12");
    expect(groups[0].entries.map((e) => e.id)).toEqual(["a", "b"]);
    expect(groups[1].label).toBe("2026-06-10");
    expect(groups[1].entries.map((e) => e.id)).toEqual(["c"]);
  });

  it("returns no groups for an empty page", () => {
    expect(groupEntriesByDay([], dayOf)).toEqual([]);
  });

  it("starts a new group when the day repeats non-contiguously", () => {
    const entries = [
      entryOn("2026-06-12T12:00:00+00:00", "a"),
      entryOn("2026-06-11T12:00:00+00:00", "b"),
      entryOn("2026-06-12T01:00:00+00:00", "c"),
    ];
    const groups = groupEntriesByDay(entries, dayOf);
    expect(groups.map((g) => g.label)).toEqual(["2026-06-12", "2026-06-11", "2026-06-12"]);
  });
});
