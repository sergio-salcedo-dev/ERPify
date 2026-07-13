import { test, expect } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";

test.describe("BackOffice - App Shell", () => {
  test.describe.configure({ mode: "parallel" });
  test.use({ viewport: VIEWPORT_DESKTOP });

  test.beforeEach(async ({ page }) => {
    await page.goto("/backoffice");
  });

  test("renders the desktop top bar with the route's section title", async ({ page }) => {
    await expect(page.getByTestId("bo-layout__topbar-title")).toHaveText("Dashboard");
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("bo-layout__topbar-title")).toHaveText("Banks");
  });

  test("shows the (placeholder) search, notifications and account controls", async ({ page }) => {
    await expect(page.getByTestId("bo-layout__topbar-search")).toBeEnabled();
    await expect(page.getByTestId("bo-layout__topbar-notifications")).toBeEnabled();
    await expect(page.getByTestId("bo-layout__topbar-account")).toBeEnabled();
  });

  test("persists the sidebar collapse state across reload", async ({ page }) => {
    const aside = page.locator("aside");
    await expect(aside).toHaveAttribute("data-sidebar-open", "true");

    await page.getByTestId("bo-layout__topbar-toggle").click();
    await expect(aside).toHaveAttribute("data-sidebar-open", "false");

    await page.reload();
    await expect(aside).toHaveAttribute("data-sidebar-open", "false");
  });

  test("toggles the sidebar with Ctrl/Cmd+B", async ({ page }) => {
    const aside = page.locator("aside");
    await expect(aside).toHaveAttribute("data-sidebar-open", "true");

    await page.keyboard.press("Control+b");
    await expect(aside).toHaveAttribute("data-sidebar-open", "false");

    await page.keyboard.press("Control+b");
    await expect(aside).toHaveAttribute("data-sidebar-open", "true");
  });

  test("exposes a skip-to-content link as the first focusable element", async ({ page }) => {
    const skip = page.getByRole("link", { name: "Skip to main content" });
    await expect(skip).toHaveAttribute("href", "#main-content");
    await page.keyboard.press("Tab");
    await expect(skip).toBeFocused();
    await expect(skip).toBeVisible();
    await expect(page.locator("#main-content")).toBeAttached();
  });
});
