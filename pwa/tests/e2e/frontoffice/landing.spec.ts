import { test, expect } from "@playwright/test";

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

  test("navigates to the public status page from the navbar", async ({ page }) => {
    await page.getByTestId("navbar__link-status").click();
    await expect(page).toHaveURL("/status");
    await expect(page.getByRole("heading", { level: 1, name: /System Status/i })).toBeVisible();
  });
});
