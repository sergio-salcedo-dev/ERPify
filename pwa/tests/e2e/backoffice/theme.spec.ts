import { test, expect } from "@playwright/test";
import { VIEWPORT_DESKTOP, VIEWPORT_MOBILE } from "../constants";

test.describe("BackOffice - Theme toggle", () => {
  test.describe.configure({ mode: "parallel" });
  // Pin the emulated colour scheme so the `system` default resolves the same
  // on every host/CI runner instead of inheriting ambient preferences.
  test.use({ viewport: VIEWPORT_DESKTOP, colorScheme: "light" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/backoffice");
  });

  test("toggling applies the `.dark` class on <html>", async ({ page }) => {
    const html = page.locator("html");
    const toggle = page.getByTestId("bo-layout__topbar-theme");

    await expect(toggle).toBeEnabled();
    // The default theme is system and the emulated colour scheme is pinned to
    // light, so the resolved state starts without `.dark`. Cycle system → light → dark.
    await expect(html).not.toHaveClass(/\bdark\b/);

    await toggle.click(); // system → light
    await expect(html).not.toHaveClass(/\bdark\b/);

    await toggle.click(); // light → dark
    await expect(html).toHaveClass(/\bdark\b/);
  });

  test("persists the explicit dark choice across reload", async ({ page }) => {
    const html = page.locator("html");
    const toggle = page.getByTestId("bo-layout__topbar-theme");

    await toggle.click(); // system → light
    await toggle.click(); // light → dark
    await expect(html).toHaveClass(/\bdark\b/);

    await page.reload();
    await expect(html).toHaveClass(/\bdark\b/);
  });
});

test.describe("BackOffice - Theme toggle (mobile header)", () => {
  test.describe.configure({ mode: "parallel" });
  test.use({ viewport: VIEWPORT_MOBILE, colorScheme: "light" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/backoffice");
  });

  test("mobile header toggle applies and persists the dark theme", async ({ page }) => {
    const html = page.locator("html");
    const toggle = page.getByTestId("bo-layout__header-mobile-theme");

    await expect(toggle).toBeVisible();
    await toggle.click(); // system → light
    await toggle.click(); // light → dark
    await expect(html).toHaveClass(/\bdark\b/);

    await page.reload();
    await expect(html).toHaveClass(/\bdark\b/);
  });
});
