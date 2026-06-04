import { test, expect } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";

/**
 * Canonical dark canvas: `--erpify-bg` (#11151f) as the browser computes it.
 * Asserting the exact token value guards the v3 navy-slate ramp — a silent
 * revert to the v2 grey (#16181d) or the v1 near-black (#08090a) fails this spec.
 */
const DARK_CANVAS_RGB = "rgb(17, 21, 31)";

test.describe("FrontOffice - Landing theme toggle", () => {
  test.describe.configure({ mode: "parallel" });
  // Pin the emulated colour scheme so the `system` default resolves the same on
  // every host/CI runner instead of inheriting ambient preferences.
  test.use({ viewport: VIEWPORT_DESKTOP, colorScheme: "light" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/");
    // next-themes applies the resolved theme class pre-hydration; waiting for
    // it makes the toggle clicks below deterministic instead of racing it.
    await expect(page.locator("html")).toHaveClass(/\blight\b/);
  });

  test("exposes the theme toggle in the navbar", async ({ page }) => {
    await expect(page.getByTestId("navbar__theme")).toBeVisible();
    await expect(page.getByTestId("navbar__theme")).toBeEnabled();
  });

  test("cycling to dark applies `.dark` and the dark canvas on the landing", async ({ page }) => {
    const html = page.locator("html");
    const landing = page.locator(".landing-page");
    const toggle = page.getByTestId("navbar__theme");

    await toggle.click(); // system → light
    await expect(html).toHaveClass(/\blight\b/);

    await toggle.click(); // light → dark
    await expect(html).toHaveClass(/\bdark\b/);

    const background = await landing.evaluate((node) => getComputedStyle(node).backgroundColor);
    expect(background).toBe(DARK_CANVAS_RGB);
  });

  test("persists the explicit dark choice across reload", async ({ page }) => {
    const html = page.locator("html");
    const toggle = page.getByTestId("navbar__theme");

    await toggle.click(); // system → light
    await toggle.click(); // light → dark
    await expect(html).toHaveClass(/\bdark\b/);

    await page.reload();
    await expect(html).toHaveClass(/\bdark\b/);
  });
});
