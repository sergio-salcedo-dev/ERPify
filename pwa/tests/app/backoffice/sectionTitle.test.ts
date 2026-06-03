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
});
