import { describe, expect, it } from "vitest";
import {
  AUDIT_PAGE_SIZE_DEFAULT,
  AUDIT_PAGE_SIZE_OPTIONS,
  isAuditPageSize,
} from "@/app/backoffice/audit/_lib/auditPaginate";
import { WIRE_MAX_LIMIT } from "@/context/shared/search/domain";

describe("audit page size options", () => {
  it("never exceeds the API wire ceiling", () => {
    for (const option of AUDIT_PAGE_SIZE_OPTIONS) {
      expect(option).toBeLessThanOrEqual(WIRE_MAX_LIMIT);
    }
  });

  it("offers a valid default", () => {
    expect(isAuditPageSize(AUDIT_PAGE_SIZE_DEFAULT)).toBe(true);
  });

  it("narrows arbitrary numbers to supported options", () => {
    expect(isAuditPageSize(25)).toBe(true);
    expect(isAuditPageSize(7)).toBe(false);
    expect(isAuditPageSize(1000)).toBe(false);
  });
});
