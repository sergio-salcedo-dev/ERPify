import { test, expect } from "@playwright/test";
import { VIEWPORT_DESKTOP, VIEWPORT_MOBILE } from "../constants";

test.describe("BackOffice - Sidebar Navigation", () => {
  test.describe.configure({ mode: "parallel" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/backoffice");
  });

  test.describe("desktop", () => {
    test.use({ viewport: VIEWPORT_DESKTOP });

    test("shows core nav and Account items", async ({ page }) => {
      const aside = page.locator("aside");
      await expect(aside.getByRole("button", { name: "Dashboard" })).toBeVisible();
      await expect(aside.getByRole("button", { name: "User Profile" })).toBeVisible();
      await aside.getByRole("button", { name: "User Profile" }).click();
      await expect(aside.getByRole("button", { name: "Logout" })).toBeVisible();
    });

    test("expands and collapses User Profile sub-items", async ({ page }) => {
      const aside = page.locator("aside");
      const userProfile = aside.getByRole("button", { name: "User Profile" });
      const notifications = aside.getByRole("button", { name: "Notifications" });
      const settings = aside.getByRole("button", { name: "Settings" });

      await expect(notifications).not.toBeVisible();
      await expect(settings).not.toBeVisible();

      await userProfile.click();
      await expect(notifications).toBeVisible();
      await expect(settings).toBeVisible();

      await userProfile.click();
      await expect(notifications).not.toBeVisible();
      await expect(settings).not.toBeVisible();
    });
  });

  test.describe("mobile", () => {
    test.use({ viewport: VIEWPORT_MOBILE });

    test("opens sheet and shows primary nav links", async ({ page }) => {
      await page.getByRole("button", { name: "Open navigation menu" }).click();
      await expect(page.getByRole("button", { name: "Dashboard" }).first()).toBeVisible();
      await expect(page.getByRole("button", { name: "User Profile" }).first()).toBeVisible();
      await expect(page.getByRole("button", { name: "Logout" }).first()).toBeVisible();
    });

    test("shows profile sub-actions in mobile sheet", async ({ page }) => {
      await page.getByRole("button", { name: "Open navigation menu" }).click();
      await expect(page.getByRole("button", { name: "Notifications" }).first()).toBeVisible();
      await expect(page.getByRole("button", { name: "Settings" }).first()).toBeVisible();
    });
  });
});
