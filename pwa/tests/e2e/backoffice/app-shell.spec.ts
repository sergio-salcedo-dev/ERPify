import { test, expect } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";
import { clickUntilVisible } from "../helpers/click-until-visible";

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

  test("shows the (placeholder) search and notifications controls", async ({ page }) => {
    await expect(page.getByTestId("bo-layout__topbar-search")).toBeEnabled();
    await expect(page.getByTestId("bo-layout__topbar-notifications")).toBeEnabled();
  });

  // Logout is asserted present but never clicked: the worker's session is shared by the
  // rest of this file, and signing out would strand every test that follows.
  test("opens the account menu and navigates to the profile page", async ({ page }) => {
    await clickUntilVisible(
      page.getByTestId("bo-layout__topbar-account"),
      page.getByTestId("bo-layout__account-menu"),
    );
    await expect(page.getByTestId("bo-layout__sidebar-logout--menu")).toBeVisible();

    await page.getByTestId("bo-layout__sidebar-profile--menu").click();

    await expect(page).toHaveURL(/\/backoffice\/profile$/);
    await expect(page.getByTestId("account-profile__title")).toBeVisible();
  });

  test("closes the account menu with Escape", async ({ page }) => {
    const menu = page.getByTestId("bo-layout__account-menu");
    await clickUntilVisible(page.getByTestId("bo-layout__topbar-account"), menu);

    await page.keyboard.press("Escape");

    await expect(menu).toBeHidden();
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
