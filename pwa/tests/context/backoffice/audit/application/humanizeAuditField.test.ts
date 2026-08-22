import { describe, expect, it } from "vitest";
import { humanizeAuditField } from "@/context/backoffice/audit/application/humanizeAuditField";

describe("humanizeAuditField", () => {
  it("uses the curated label for an audited Bank field", () => {
    expect(humanizeAuditField("name")).toBe("Name");
    expect(humanizeAuditField("shortName")).toBe("Short name");
  });

  it("uses the curated label for an audited BankAccount field", () => {
    expect(humanizeAuditField("holderName")).toBe("Holder");
    expect(humanizeAuditField("iban")).toBe("IBAN");
    expect(humanizeAuditField("bic")).toBe("BIC");
  });

  it("title-cases an unmapped camelCase field", () => {
    expect(humanizeAuditField("legalName")).toBe("Legal Name");
    expect(humanizeAuditField("taxId")).toBe("Tax Id");
  });

  it("title-cases an unmapped snake_case field", () => {
    expect(humanizeAuditField("legal_name")).toBe("Legal Name");
  });

  it("is resilient to an empty or single-word key (the raw key still carries the truth)", () => {
    expect(humanizeAuditField("")).toBe("");
    expect(humanizeAuditField("reference")).toBe("Reference");
  });
});
