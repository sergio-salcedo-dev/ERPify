import { test, expect } from "@playwright/test";

// An anonymous visitor: a fresh, unauthenticated context, since "Sign in" is offered only
// to a visitor the server has not confirmed a session for.
test.describe("FrontOffice - Landing Page (anonymous)", () => {
  test.describe.configure({ mode: "parallel" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/");
  });

  test("navigates to login from the Sign in CTA", async ({ page }) => {
    await page.getByTestId("navbar__link-login").click();
    await expect(page).toHaveURL("/login");
    await expect(page.getByTestId("login-form")).toBeVisible();
  });

  test("navigates to login from the mobile Sign in CTA", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.getByTestId("navbar__mobile-menu-toggle").click();
    await page.getByTestId("navbar__link-login--mobile").click();
    await expect(page).toHaveURL("/login");
    await expect(page.getByTestId("login-form")).toBeVisible();
  });

  test("keeps the Backoffice entry for an anonymous visitor", async ({ page }) => {
    await expect(page.getByTestId("navbar__go-to-backoffice-button")).toBeVisible();
  });
});
