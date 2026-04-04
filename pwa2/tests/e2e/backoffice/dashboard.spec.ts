import { test, expect } from "@playwright/test";

test.describe("BackOffice - Dashboard", () => {
  test.beforeEach(async ({ page }) => {
    await page.goto("/backoffice");
  });

  test("should display dashboard content", async ({ page }) => {
    await expect(page.locator("h1")).toContainText("Dashboard");
    await expect(page.locator("text=Welcome back, Admin")).toBeVisible();
  });

  test("should check backoffice api health in administration page", async ({ page }) => {
    // Navigate to health page via sidebar
    const adminItem = page.locator(".sidebar-item:has-text('Administration')");
    if (await adminItem.isVisible()) {
      await adminItem.click();
      await page.click("text=Health");
    } else {
      // Mobile
      await page.click("button:has(svg)"); // Menu toggle
      await page.click("button:has-text('Administration')");
      await page.click("button:has-text('Health')");
    }

    await expect(page).toHaveURL("/backoffice/health");
    await page.click("text=Run Health Check");
    await expect(page.locator("text=Status: ok")).toBeVisible();
  });

  test("should logout and redirect to landing page", async ({ page }) => {
    // Open mobile menu if needed or just click logout in desktop sidebar
    const logoutBtn = page.locator("button:has-text('Logout')");
    if (await logoutBtn.isVisible()) {
      await logoutBtn.click();
    } else {
      // Mobile menu
      await page.click("button:has(svg)"); // Menu button
      await page.click("button:has-text('Logout')");
    }

    await expect(page).toHaveURL("/");
    await expect(page.locator("h1")).toContainText("Modern ERP for Construction");
  });
});
