import { test, expect } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP, VIEWPORT_MOBILE } from "../constants";
import {
  navigateToHealthViaSidebarDesktop,
  navigateToHealthViaSidebarMobile,
} from "../helpers/backoffice-nav";
import { expectBackOfficeHealthOk } from "../helpers/health-assertions";

test.describe("BackOffice - Dashboard", () => {
  test.describe.configure({ mode: "parallel" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/backoffice");
  });

  test("displays dashboard content", async ({ page }) => {
    await expect(page.getByRole("heading", { level: 1, name: "Dashboard" })).toBeVisible();
    await expect(page.getByText("No metrics to show yet")).toBeVisible();
  });

  test.describe("desktop", () => {
    test.use({ viewport: VIEWPORT_DESKTOP });

    test("reaches health check via Configuration sidebar", async ({ page }) => {
      await navigateToHealthViaSidebarDesktop(page);
      await expect(page).toHaveURL("/backoffice/health");
      await expectBackOfficeHealthOk(page);
    });
  });

  test.describe("mobile", () => {
    test.use({ viewport: VIEWPORT_MOBILE });

    test("reaches health check via mobile menu", async ({ page }) => {
      await navigateToHealthViaSidebarMobile(page);
      await expect(page).toHaveURL("/backoffice/health");
      await expectBackOfficeHealthOk(page);
    });
  });
});
