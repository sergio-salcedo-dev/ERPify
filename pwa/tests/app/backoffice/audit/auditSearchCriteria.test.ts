import { describe, expect, it } from "vitest";
import {
  toAuditCriteria,
  toAuditFilters,
  toAuditSort,
} from "@/app/backoffice/audit/_lib/auditSearchCriteria";
import { EMPTY_AUDIT_FILTER, type AuditFilter } from "@/app/backoffice/audit/_lib/auditFilter";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";

const UUID = "019f0427-61f7-71d6-ae8f-b91d41227c29";

function filterOf(patch: Partial<AuditFilter>): AuditFilter {
  return { ...EMPTY_AUDIT_FILTER, ...patch };
}

describe("toAuditFilters", () => {
  it("maps an empty filter to no server filters", () => {
    expect(toAuditFilters(EMPTY_AUDIT_FILTER)).toEqual([]);
  });

  it("maps level / actorType / resourceType to eq, and action to contains", () => {
    const filters = toAuditFilters(
      filterOf({
        level: "security",
        actorType: "user",
        resourceType: "BankAccount",
        action: "bank",
      }),
    );
    expect(filters).toContainEqual({ field: "level", operator: "eq", value: "security" });
    expect(filters).toContainEqual({ field: "actorType", operator: "eq", value: "user" });
    expect(filters).toContainEqual({ field: "resourceType", operator: "eq", value: "BankAccount" });
    expect(filters).toContainEqual({ field: "action", operator: "contains", value: "bank" });
  });

  it("sends actorId / resourceId only when they are complete UUIDs (no 422 on a mid-edit value)", () => {
    expect(toAuditFilters(filterOf({ actorId: UUID }))).toContainEqual({
      field: "actorId",
      operator: "eq",
      value: UUID,
    });
    expect(toAuditFilters(filterOf({ resourceId: UUID }))).toContainEqual({
      field: "resourceId",
      operator: "eq",
      value: UUID,
    });
    // A partial / non-UUID value is omitted entirely.
    expect(toAuditFilters(filterOf({ actorId: "019f0427" }))).toEqual([]);
    expect(toAuditFilters(filterOf({ resourceId: "not-a-uuid" }))).toEqual([]);
  });

  it("maps the date range to inclusive gte/lte bounds on occurredOn", () => {
    const filters = toAuditFilters(filterOf({ from: "12/06/2026", to: "13/06/2026" }));
    const from = filters.find((f) => f.field === "occurredOn" && f.operator === "gte");
    const to = filters.find((f) => f.field === "occurredOn" && f.operator === "lte");
    expect(typeof from?.value).toBe("string");
    expect(typeof to?.value).toBe("string");
    // Wall-clock parts are timezone-invariant (start-of-day / end-of-day local).
    expect(from?.value as string).toMatch(/^2026-06-12T00:00:00/);
    expect(to?.value as string).toMatch(/^2026-06-13T23:59:59/);
  });

  it("drops a half-typed / impossible date", () => {
    expect(toAuditFilters(filterOf({ from: "12/06" }))).toEqual([]);
    expect(toAuditFilters(filterOf({ to: "31/02/2026" }))).toEqual([]);
  });
});

describe("toAuditSort / toAuditCriteria", () => {
  it("always sorts by occurredOn in the requested direction", () => {
    expect(toAuditSort(SortDirection.DESC)).toEqual({ field: "occurredOn", direction: "desc" });
    expect(toAuditSort(SortDirection.ASC)).toEqual({ field: "occurredOn", direction: "asc" });
  });

  it("bundles filters, sort and limit into a criteria object", () => {
    const criteria = toAuditCriteria(filterOf({ level: "activity" }), SortDirection.DESC, 50);
    expect(criteria.filters).toContainEqual({ field: "level", operator: "eq", value: "activity" });
    expect(criteria.sort).toEqual({ field: "occurredOn", direction: "desc" });
    expect(criteria.limit).toBe(50);
  });
});
