import { test, expect } from "../fixtures/authenticatedTest";

test.describe("FrontOffice - Landing Page", () => {
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

  test("offers no Sign in to an authenticated visitor, on desktop or mobile", async ({ page }) => {
    // The link is also absent while the session hydrates, so absence only means something
    // once the probe has answered and the navbar has committed the resolved status.
    const sessionProbe = page.waitForResponse((response) =>
      new URL(response.url()).pathname.endsWith("/me"),
    );
    await page.reload();
    expect((await sessionProbe).status()).toBe(200);
    await expect(page.locator("nav.navbar")).toHaveAttribute(
      "data-session-status",
      "authenticated",
    );

    await expect(page.getByTestId("navbar__go-to-backoffice-button")).toBeVisible();
    await expect(page.getByTestId("navbar__link-login")).toHaveCount(0);

    await page.setViewportSize({ width: 375, height: 812 });
    await page.getByTestId("navbar__mobile-menu-toggle").click();
    await expect(page.locator("nav.navbar")).toHaveAttribute(
      "data-session-status",
      "authenticated",
    );
    await expect(page.getByTestId("navbar__go-to-backoffice-button--mobile")).toBeVisible();
    await expect(page.getByTestId("navbar__link-login--mobile")).toHaveCount(0);
  });

  test("navigates to the public status page from the navbar", async ({ page }) => {
    await page.getByTestId("navbar__link-status").click();
    await expect(page).toHaveURL("/status");
    await expect(page.getByRole("heading", { level: 1, name: /System Status/i })).toBeVisible();
  });
});
