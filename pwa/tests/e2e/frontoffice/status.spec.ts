import { test, expect } from "@playwright/test";
import { expectStatusPageOperational } from "../helpers/health-assertions";

test.describe("FrontOffice - Status Page", () => {
  test.describe.configure({ mode: "parallel" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/status");
  });

  test("auto-runs the health check and reports all systems operational", async ({ page }) => {
    await expect(page.getByRole("heading", { level: 1, name: /System Status/i })).toBeVisible();
    await expectStatusPageOperational(page);
  });

  test("re-checks via the manual refresh control", async ({ page }) => {
    await expectStatusPageOperational(page);
    await page.getByTestId("status-page__refresh").click();
    await expectStatusPageOperational(page);
  });
});
