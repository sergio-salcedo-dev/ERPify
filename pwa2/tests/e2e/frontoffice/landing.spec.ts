import { test, expect } from "@playwright/test";

test.describe("FrontOffice - Landing Page", () => {
  test.beforeEach(async ({ page }) => {
    await page.goto("/");
  });

  test("should display landing page content", async ({ page }) => {
    await expect(page.locator("h1")).toContainText("Modern ERP for Construction");
  });

  test("should navigate to backoffice", async ({ page }) => {
    await page.click("text=Go to BackOffice");
    await expect(page).toHaveURL("/backoffice");
  });

  test("should check frontoffice api health", async ({ page }) => {
    await page.click("text=Check FrontOffice API health");
    await expect(page.locator("text=Status: ok")).toBeVisible();
  });
});
