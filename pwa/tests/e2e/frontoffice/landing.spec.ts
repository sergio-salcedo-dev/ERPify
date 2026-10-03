import { test as anonymousTest } from "@playwright/test";
import { test as authenticatedTest, expect } from "../fixtures/authenticatedTest";

// The navbar's access cluster follows the session, so each half of it is exercised from the
// session that shows it: the back-office entry from a signed-in worker, "Sign in" from a fresh,
// unauthenticated context.
authenticatedTest.describe("FrontOffice - Landing Page (signed in)", () => {
  authenticatedTest.describe.configure({ mode: "parallel" });

  authenticatedTest.beforeEach(async ({ page }) => {
    await page.goto("/");
  });

  authenticatedTest("displays hero heading", async ({ page }) => {
    await expect(
      page.getByRole("heading", { level: 1, name: /ERP for Construction/i }),
    ).toBeVisible();
  });

  authenticatedTest("navigates to backoffice from primary CTA", async ({ page }) => {
    await page.getByTestId("navbar__go-to-backoffice-button").click();
    await expect(page).toHaveURL("/backoffice");
  });

  authenticatedTest("does not offer 'Sign in' to a signed-in visitor", async ({ page }) => {
    await expect(page.getByTestId("navbar__go-to-backoffice-button")).toBeVisible();
    await expect(page.getByTestId("navbar__link-login")).toHaveCount(0);
  });

  authenticatedTest("navigates to the public status page from the navbar", async ({ page }) => {
    await page.getByTestId("navbar__link-status").click();
    await expect(page).toHaveURL("/status");
    await expect(page.getByRole("heading", { level: 1, name: /System Status/i })).toBeVisible();
  });
});

anonymousTest.describe("FrontOffice - Landing Page (anonymous)", () => {
  anonymousTest.describe.configure({ mode: "parallel" });

  anonymousTest.beforeEach(async ({ page }) => {
    await page.goto("/");
  });

  anonymousTest("navigates to login from the Sign in CTA", async ({ page }) => {
    await page.getByTestId("navbar__link-login").click();
    await expect(page).toHaveURL("/login");
    await expect(page.getByTestId("login-form")).toBeVisible();
  });

  anonymousTest(
    "does not offer the back-office entry to an anonymous visitor",
    async ({ page }) => {
      await expect(page.getByTestId("navbar__link-login")).toBeVisible();
      await expect(page.getByTestId("navbar__go-to-backoffice-button")).toHaveCount(0);
    },
  );

  anonymousTest("navigates to login from the mobile Sign in CTA", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.getByTestId("navbar__mobile-menu-toggle").click();
    await page.getByTestId("navbar__link-login--mobile").click();
    await expect(page).toHaveURL("/login");
    await expect(page.getByTestId("login-form")).toBeVisible();
  });
});
