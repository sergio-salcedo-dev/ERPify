import { describe, expect, it } from "vitest";
import { groupEntriesByCorrelation } from "@/app/backoffice/audit/_lib/auditJourneyGroups";
import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";

function entryOf(id: string, correlationId: string, occurredOn: string): AuditEntry {
  return {
    id,
    occurredOn,
    level: "activity",
    action: "ROUTE_X",
    actorType: "api_key",
    actorId: "019f0427-61f7-71d6-ae8f-b91d41227c29",
    correlationId,
    resourceType: null,
    resourceId: null,
    actorErased: false,
    resourceErased: false,
  };
}

describe("groupEntriesByCorrelation", () => {
  it("groups by correlation, sessions newest-first, entries ascending within a session", () => {
    // Page is newest-first: session A (newest) then session B.
    const entries = [
      entryOf("a2", "A", "2026-06-12T12:05:00+00:00"),
      entryOf("a1", "A", "2026-06-12T12:00:00+00:00"),
      entryOf("b1", "B", "2026-06-12T09:00:00+00:00"),
    ];

    const sessions = groupEntriesByCorrelation(entries);

    expect(sessions.map((s) => s.correlationId)).toEqual(["A", "B"]);
    // Ascending within the session (chronological reconstruction).
    expect(sessions[0].entries.map((e) => e.id)).toEqual(["a1", "a2"]);
    expect(sessions[0].count).toBe(2);
    expect(sessions[0].startIso).toBe("2026-06-12T12:00:00+00:00");
    expect(sessions[0].endIso).toBe("2026-06-12T12:05:00+00:00");
  });

  it("returns no sessions for an empty page", () => {
    expect(groupEntriesByCorrelation([])).toEqual([]);
  });

  it("keeps interleaved correlations as distinct sessions in first-appearance order", () => {
    const entries = [
      entryOf("a1", "A", "2026-06-12T12:00:00+00:00"),
      entryOf("b1", "B", "2026-06-12T11:00:00+00:00"),
      entryOf("a2", "A", "2026-06-12T10:00:00+00:00"),
    ];
    const sessions = groupEntriesByCorrelation(entries);
    expect(sessions.map((s) => s.correlationId)).toEqual(["A", "B"]);
    expect(sessions[0].entries.map((e) => e.id)).toEqual(["a2", "a1"]);
  });
});
