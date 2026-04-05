import { test, expect } from "@playwright/test";
import { VIEWPORT_DESKTOP, VIEWPORT_MOBILE } from "../constants";
import {
  navigateToHealthViaSidebarDesktop,
  navigateToHealthViaSidebarMobile,
  logoutViaSidebarDesktop,
  logoutViaSidebarMobile,
} from "../helpers/backoffice-nav";
import { expectBackOfficeHealthOk } from "../helpers/health-assertions";

test.describe("BackOffice - Dashboard", () => {
  test.describe.configure({ mode: "parallel" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/backoffice");
  });

  test("displays dashboard content", async ({ page }) => {
    await expect(page.getByRole("heading", { level: 1, name: "Dashboard" })).toBeVisible();
    await expect(page.getByText("Welcome back, Admin")).toBeVisible();
  });

  test.describe("desktop", () => {
    test.use({ viewport: VIEWPORT_DESKTOP });

    test("reaches health check via Administration sidebar", async ({ page }) => {
      await navigateToHealthViaSidebarDesktop(page);
      await expect(page).toHaveURL("/backoffice/health");
      await page.getByRole("button", { name: "Run Health Check" }).click();
      await expectBackOfficeHealthOk(page);
    });

    test("logs out and returns to landing", async ({ page }) => {
      await logoutViaSidebarDesktop(page);
      await expect(page).toHaveURL("/");
      await expect(
        page.getByRole("heading", { level: 1, name: /Modern ERP for Construction/i }),
      ).toBeVisible();
    });
  });

  test.describe("mobile", () => {
    test.use({ viewport: VIEWPORT_MOBILE });

    test("reaches health check via mobile menu", async ({ page }) => {
      await navigateToHealthViaSidebarMobile(page);
      await expect(page).toHaveURL("/backoffice/health");
      await page.getByRole("button", { name: "Run Health Check" }).click();
      await expectBackOfficeHealthOk(page);
    });

    test("logs out from mobile navigation", async ({ page }) => {
      await logoutViaSidebarMobile(page);
      await expect(page).toHaveURL("/");
      await expect(
        page.getByRole("heading", { level: 1, name: /Modern ERP for Construction/i }),
      ).toBeVisible();
    });
  });
});
