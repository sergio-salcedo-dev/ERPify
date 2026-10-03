import { test, expect } from "../fixtures/authenticatedTest";

// The navbar's access cluster follows the session, so each half of it is exercised from the
// session that shows it: the back-office entry from the signed-in worker session, "Sign in" from
// a context whose storage state is emptied, which carries no session at all.
test.describe("FrontOffice - Landing Page (signed in)", () => {
  test.describe.configure({ mode: "parallel" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/");
  });

  test("displays hero heading", async ({ page }) => {
    await expect(
      page.getByRole("heading", { level: 1, name: /ERP for Construction/i }),
    ).toBeVisible();
  });

  test("navigates to backoffice from primary CTA", async ({ page }) => {
    await page.getByTestId("navbar__go-to-backoffice-button").click();
    await expect(page).toHaveURL("/backoffice");
  });

  test("does not offer 'Sign in' to a signed-in visitor", async ({ page }) => {
    await expect(page.getByTestId("navbar__go-to-backoffice-button")).toBeVisible();
    await expect(page.getByTestId("navbar__link-login")).toHaveCount(0);
  });

  test("navigates to the public status page from the navbar", async ({ page }) => {
    await page.getByTestId("navbar__link-status").click();
    await expect(page).toHaveURL("/status");
    await expect(page.getByRole("heading", { level: 1, name: /System Status/i })).toBeVisible();
  });
});

test.describe("FrontOffice - Landing Page (anonymous)", () => {
  test.use({ storageState: { cookies: [], origins: [] } });
  test.describe.configure({ mode: "parallel" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/");
  });

  test("navigates to login from the Sign in CTA", async ({ page }) => {
    await page.getByTestId("navbar__link-login").click();
    await expect(page).toHaveURL("/login");
    await expect(page.getByTestId("login-form")).toBeVisible();
  });

  test("does not offer the back-office entry to an anonymous visitor", async ({ page }) => {
    await expect(page.getByTestId("navbar__link-login")).toBeVisible();
    await expect(page.getByTestId("navbar__go-to-backoffice-button")).toHaveCount(0);
  });

  test("navigates to login from the mobile Sign in CTA", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.getByTestId("navbar__mobile-menu-toggle").click();
    await page.getByTestId("navbar__link-login--mobile").click();
    await expect(page).toHaveURL("/login");
    await expect(page.getByTestId("login-form")).toBeVisible();
  });
});
