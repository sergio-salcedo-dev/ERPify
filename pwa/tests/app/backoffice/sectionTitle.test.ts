import { describe, expect, it } from "vitest";
import { sectionTitleFor } from "@/app/backoffice/_lib/sectionTitle";

describe("sectionTitleFor", () => {
  it.each([
    ["/backoffice", "Dashboard"],
    ["/backoffice/health", "Service Health"],
  ])("maps the top-level route %s to %s", (pathname, title) => {
    expect(sectionTitleFor(pathname)).toBe(title);
  });

  it.each([
    ["/backoffice/banks", "Banks"],
    ["/backoffice/banks/123", "Banks"],
    ["/backoffice/banks/123/edit", "Banks"],
  ])("maps banks routes (list, detail, edit) %s to Banks", (pathname, title) => {
    expect(sectionTitleFor(pathname)).toBe(title);
  });

  it.each([
    ["/backoffice/clients", "Clients"],
    ["/backoffice/quotes", "Quotes"],
    ["/backoffice/invoices", "Invoicing"],
    ["/backoffice/products", "Products Catalog"],
    ["/backoffice/audit", "Audit Logs"],
    ["/backoffice/roadmap", "Product Roadmap"],
  ])("maps ERP leaf route %s to %s", (pathname, title) => {
    expect(sectionTitleFor(pathname)).toBe(title);
  });

  it.each([
    ["/backoffice/finance", "Finance"],
    ["/backoffice/catalog", "Catalog"],
    ["/backoffice/settings", "Settings"],
  ])("titles the bare parent-segment landing page %s as %s", (pathname, title) => {
    expect(sectionTitleFor(pathname)).toBe(title);
  });

  // Longest-match-first ordering: every nested route below shares a strict
  // prefix with a shorter rule, so these assert the longer rule wins. Profile
  // sub-routes resolve before the profile root; /docs before /docs/{dictionary,
  // flow}; /companies before /companies/employees; equal-length finance
  // siblings tie-break deterministically; parent-segment landings never shadow
  // their nested routes.
  it.each([
    ["/backoffice/profile/notifications", "Notifications"],
    ["/backoffice/profile/settings", "Settings"],
    ["/backoffice/profile", "User Profile"],
    ["/backoffice/docs", "Technical Explorer"],
    ["/backoffice/docs/dictionary", "Data Dictionary"],
    ["/backoffice/docs/flow", "How ERPify Works"],
    ["/backoffice/companies", "Companies"],
    ["/backoffice/companies/employees", "Employees"],
  ])("resolves the longest matching prefix: %s → %s", (pathname, title) => {
    expect(sectionTitleFor(pathname)).toBe(title);
  });

  it.each([
    ["/backoffice/finance/control", "Budget vs Actuals"],
    ["/backoffice/finance/transactions", "Transactions"],
    ["/backoffice/finance/cash-flow", "Cash Flow"],
    ["/backoffice/finance/accounting", "Project Costs"],
    ["/backoffice/catalog/brands", "Brands & Manufacturers"],
    ["/backoffice/settings/features", "Features & Modules"],
  ])("distinguishes sibling routes under a shared parent: %s → %s", (pathname, title) => {
    expect(sectionTitleFor(pathname)).toBe(title);
  });

  it.each([
    ["/backoffice/clients/abc-123", "Clients"],
    ["/backoffice/finance/cash-flow/settings", "Cash Flow"],
  ])("keeps the title for deep sub-routes of an ERP leaf: %s → %s", (pathname, title) => {
    expect(sectionTitleFor(pathname)).toBe(title);
  });

  it.each([["/backoffice/unknown"], ["/something-else"]])(
    "falls back to Backoffice for the unknown path %s",
    (pathname) => {
      expect(sectionTitleFor(pathname)).toBe("Backoffice");
    },
  );
});
