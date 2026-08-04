import { test, expect } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP, VIEWPORT_MOBILE } from "../constants";
import { clickUntilVisible } from "../helpers/click-until-visible";

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
      await clickUntilVisible(
        aside.getByRole("button", { name: "User Profile" }),
        aside.getByRole("button", { name: "Logout" }),
      );
      // The expanded parent only toggles its sub-items, so this leaf is the sole way the
      // account landing page is reachable from an expanded desktop sidebar.
      await expect(aside.getByTestId("bo-layout__sidebar-profile")).toBeVisible();
    });

    test("reaches the profile page from the Account group", async ({ page }) => {
      const aside = page.locator("aside");
      await clickUntilVisible(
        aside.getByRole("button", { name: "User Profile" }),
        aside.getByTestId("bo-layout__sidebar-profile"),
      );

      await aside.getByTestId("bo-layout__sidebar-profile").click();

      await expect(page).toHaveURL(/\/backoffice\/profile$/);
      await expect(page.getByTestId("account-profile__title")).toBeVisible();
    });

    test("expands and collapses User Profile sub-items", async ({ page }) => {
      const aside = page.locator("aside");
      const userProfile = aside.getByRole("button", { name: "User Profile" });
      const notifications = aside.getByRole("button", { name: "Notifications" });
      const settings = aside.getByRole("button", { name: "Settings" });

      await expect(notifications).not.toBeVisible();
      await expect(settings).not.toBeVisible();

      await clickUntilVisible(userProfile, notifications);
      await expect(settings).toBeVisible();

      await userProfile.click();
      await expect(notifications).not.toBeVisible();
      await expect(settings).not.toBeVisible();
    });
  });

  test.describe("mobile", () => {
    test.use({ viewport: VIEWPORT_MOBILE });

    test("opens sheet and shows primary nav links", async ({ page }) => {
      await clickUntilVisible(
        page.getByRole("button", { name: "Open navigation menu" }),
        page.getByRole("dialog"),
      );
      await expect(page.getByRole("button", { name: "Dashboard" }).first()).toBeVisible();
      await expect(page.getByRole("button", { name: "User Profile" }).first()).toBeVisible();
      await expect(page.getByRole("button", { name: "Logout" }).first()).toBeVisible();
    });

    test("shows profile sub-actions in mobile sheet", async ({ page }) => {
      await clickUntilVisible(
        page.getByRole("button", { name: "Open navigation menu" }),
        page.getByRole("dialog"),
      );
      await expect(page.getByRole("button", { name: "My profile" }).first()).toBeVisible();
      await expect(page.getByRole("button", { name: "Notifications" }).first()).toBeVisible();
      await expect(page.getByRole("button", { name: "Settings" }).first()).toBeVisible();
    });
  });
});
