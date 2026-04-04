import { test, expect } from "@playwright/test";

test.describe("BackOffice - Sidebar Navigation", () => {
  test.beforeEach(async ({ page }) => {
    await page.goto("/backoffice");
  });

  test("should display sidebar menu items", async ({ page }) => {
    // Desktop sidebar
    const dashboardItem = page.locator("aside .sidebar-item:has-text('Dashboard')");
    const userProfileItem = page.locator("aside .sidebar-item:has-text('User Profile')");
    const logoutItem = page.locator("aside .sidebar-item:has-text('Logout')");

    if (await dashboardItem.isVisible()) {
      await expect(dashboardItem).toBeVisible();
      await expect(userProfileItem).toBeVisible();
      await expect(logoutItem).toBeVisible();
    } else {
      // Mobile menu
      await page.click("button:has(svg)"); // Menu toggle
      await expect(
        page.locator(".bo-layout__sidebar-mobile-link:has-text('Dashboard')"),
      ).toBeVisible();
      await expect(
        page.locator(".bo-layout__sidebar-mobile-link:has-text('User Profile')"),
      ).toBeVisible();
      await expect(
        page.locator(".bo-layout__sidebar-mobile-link:has-text('Logout')"),
      ).toBeVisible();
    }
  });

  test("should toggle sub-items for User Profile", async ({ page }) => {
    const userProfileItem = page.locator(".sidebar-item:has-text('User Profile')");
    const notificationsSubItem = page.locator("text=Notifications");
    const settingsSubItem = page.locator("text=Settings");

    if (await userProfileItem.isVisible()) {
      // Desktop
      await expect(notificationsSubItem).not.toBeVisible();
      await expect(settingsSubItem).not.toBeVisible();

      await userProfileItem.click();

      await expect(notificationsSubItem).toBeVisible();
      await expect(settingsSubItem).toBeVisible();

      await userProfileItem.click();
      await expect(notificationsSubItem).not.toBeVisible();
    } else {
      // Mobile
      await page.click("button:has(svg)"); // Menu toggle

      // In mobile they are always visible if implemented as I did (no toggle state in mobile layout)
      await expect(
        page.locator(".bo-layout__sidebar-mobile-sub-item:has-text('Notifications')"),
      ).toBeVisible();
      await expect(
        page.locator(".bo-layout__sidebar-mobile-sub-item:has-text('Settings')"),
      ).toBeVisible();
    }
  });
});
