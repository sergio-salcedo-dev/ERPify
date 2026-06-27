import { describe, expect, it } from "vitest";
import { humanizeAuditAction } from "@/context/backoffice/audit/application/humanizeAuditAction";

describe("humanizeAuditAction", () => {
  it("uses the curated label for a known action", () => {
    expect(humanizeAuditAction("BANK_ACCOUNTS_VIEWED")).toBe("Cuentas bancarias consultadas");
    expect(humanizeAuditAction("ACCESS_DENIED")).toBe("Acceso denegado");
    expect(humanizeAuditAction("GDPR_ERASURE_EXECUTED")).toBe("Borrado GDPR ejecutado");
  });

  it("strips the ROUTE_ prefix and title-cases the rest for route actions", () => {
    expect(humanizeAuditAction("ROUTE_BACKOFFICE_BANK_SEARCH")).toBe("Backoffice Bank Search");
    expect(humanizeAuditAction("ROUTE_BACKOFFICE_AUDIT_TIMELINE_SEARCH")).toBe(
      "Backoffice Audit Timeline Search",
    );
  });

  it("title-cases an unmapped non-route action without dropping anything", () => {
    expect(humanizeAuditAction("SOME_NEW_ACTION")).toBe("Some New Action");
  });

  it("is resilient to empty or odd tokens (the raw token still carries the truth)", () => {
    expect(humanizeAuditAction("")).toBe("");
    expect(humanizeAuditAction("ROUTE_")).toBe("");
    expect(humanizeAuditAction("LOGIN")).toBe("Login");
  });
});
