import { test, expect } from "@playwright/test";
import { expectFrontOfficeHealthOk } from "../helpers/health-assertions";

test.describe("FrontOffice - Landing Page", () => {
  test.describe.configure({ mode: "parallel" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/");
  });

  test("displays hero heading", async ({ page }) => {
    await expect(
      page.getByRole("heading", { level: 1, name: /Modern ERP for Construction/i }),
    ).toBeVisible();
  });

  test("navigates to backoffice from primary CTA", async ({ page }) => {
    await page.getByRole("button", { name: "Go to BackOffice" }).click();
    await expect(page).toHaveURL("/backoffice");
  });

  test("runs frontoffice API health check", async ({ page }) => {
    await page.getByRole("button", { name: "Check FrontOffice API health" }).click();
    await expectFrontOfficeHealthOk(page);
    await expect(page.getByTestId("frontoffice-health-status")).not.toContainText(
      "Error checking health",
    );
  });
});
