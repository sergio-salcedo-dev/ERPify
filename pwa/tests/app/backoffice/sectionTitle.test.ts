import { describe, expect, it } from "vitest";
import { sectionTitleFor } from "@/app/backoffice/_lib/sectionTitle";

describe("sectionTitleFor", () => {
  it("maps the backoffice root to Dashboard", () => {
    expect(sectionTitleFor("/backoffice")).toBe("Dashboard");
  });

  it("maps banks routes (list, detail, edit) to Banks", () => {
    expect(sectionTitleFor("/backoffice/banks")).toBe("Banks");
    expect(sectionTitleFor("/backoffice/banks/123")).toBe("Banks");
    expect(sectionTitleFor("/backoffice/banks/123/edit")).toBe("Banks");
  });

  it("maps the health route to Service Health", () => {
    expect(sectionTitleFor("/backoffice/health")).toBe("Service Health");
  });

  it("maps administration to Administration", () => {
    expect(sectionTitleFor("/backoffice/administration")).toBe("Administration");
  });

  it("maps profile sub-routes before the profile root", () => {
    expect(sectionTitleFor("/backoffice/profile/notifications")).toBe("Notifications");
    expect(sectionTitleFor("/backoffice/profile/settings")).toBe("Settings");
    expect(sectionTitleFor("/backoffice/profile")).toBe("User Profile");
  });

  it("falls back to Backoffice for unknown paths", () => {
    expect(sectionTitleFor("/backoffice/unknown")).toBe("Backoffice");
    expect(sectionTitleFor("/something-else")).toBe("Backoffice");
  });

  it("maps ERP leaf routes to their section title", () => {
    expect(sectionTitleFor("/backoffice/clients")).toBe("Clients");
    expect(sectionTitleFor("/backoffice/quotes")).toBe("Quotes");
    expect(sectionTitleFor("/backoffice/invoices")).toBe("Invoicing");
    expect(sectionTitleFor("/backoffice/products")).toBe("Products Catalog");
    expect(sectionTitleFor("/backoffice/audit")).toBe("Audit Logs");
  });

  it("resolves nested ERP routes before their parent prefix", () => {
    // /docs is a strict prefix of /docs/dictionary and /docs/flow.
    expect(sectionTitleFor("/backoffice/docs")).toBe("Technical Explorer");
    expect(sectionTitleFor("/backoffice/docs/dictionary")).toBe("Data Dictionary");
    expect(sectionTitleFor("/backoffice/docs/flow")).toBe("Domain Flows");
    // /companies vs /companies/employees.
    expect(sectionTitleFor("/backoffice/companies")).toBe("Companies");
    expect(sectionTitleFor("/backoffice/companies/employees")).toBe("Employees");
  });

  it("distinguishes equal-length sibling routes under finance", () => {
    expect(sectionTitleFor("/backoffice/finance/control")).toBe("Management Control");
    expect(sectionTitleFor("/backoffice/finance/treasury")).toBe("Treasury & Banks");
    expect(sectionTitleFor("/backoffice/finance/cash-flow")).toBe("Cash Flow");
    expect(sectionTitleFor("/backoffice/finance/accounting")).toBe("Cost Allocation");
  });

  it("keeps the title for deep sub-routes of an ERP leaf", () => {
    expect(sectionTitleFor("/backoffice/clients/abc-123")).toBe("Clients");
    expect(sectionTitleFor("/backoffice/finance/treasury/settings")).toBe("Treasury & Banks");
  });

  it("titles the bare parent-segment landing pages", () => {
    expect(sectionTitleFor("/backoffice/finance")).toBe("Finance");
    expect(sectionTitleFor("/backoffice/catalog")).toBe("Catalog");
    expect(sectionTitleFor("/backoffice/settings")).toBe("Settings");
  });

  it("keeps parent-segment landings from shadowing their nested routes", () => {
    expect(sectionTitleFor("/backoffice/finance/control")).toBe("Management Control");
    expect(sectionTitleFor("/backoffice/catalog/brands")).toBe("Brands & Manufacturers");
    expect(sectionTitleFor("/backoffice/settings/features")).toBe("Features & Modules");
  });
});
