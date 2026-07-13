import { test, expect, type Page } from "@playwright/test";

import { E2E_USER_EMAIL, E2E_USER_PASSWORD, VIEWPORT_DESKTOP, VIEWPORT_MOBILE } from "../constants";
import { logoutViaSidebarDesktop, logoutViaSidebarMobile } from "../helpers/backoffice-nav";

/**
 * Sign-out revokes the current session server-side (the registry row) and drops
 * the cookie, so the fail-closed admission gate 401s that session on any later
 * `/api` use. These tests therefore own a throwaway session: a fresh,
 * unauthenticated context signs in through the UI, then signs out — never the
 * per-worker session the rest of the suite reuses. Sharing that session here
 * would revoke it under a sibling test, which would then hang against the gate.
 */
test.use({ storageState: { cookies: [], origins: [] } });

async function signIn(page: Page): Promise<void> {
  await page.goto("/login");
  await page.getByTestId("login-form__email").fill(E2E_USER_EMAIL);
  await page.getByTestId("login-form__password").fill(E2E_USER_PASSWORD);
  await page.getByTestId("login-form__submit").click();
  await expect(page).toHaveURL(/\/backoffice/);
}

test.describe("BackOffice sign-out (real API)", () => {
  test.describe.configure({ mode: "parallel" });

  test.describe("desktop", () => {
    test.use({ viewport: VIEWPORT_DESKTOP });

    test("signs out and returns to the landing page", async ({ page }) => {
      await signIn(page);
      await logoutViaSidebarDesktop(page);
      await expect(page).toHaveURL("/");
      await expect(
        page.getByRole("heading", { level: 1, name: /ERP for Construction/i }),
      ).toBeVisible();
    });
  });

  test.describe("mobile", () => {
    test.use({ viewport: VIEWPORT_MOBILE });

    test("signs out from the mobile navigation", async ({ page }) => {
      await signIn(page);
      await logoutViaSidebarMobile(page);
      await expect(page).toHaveURL("/");
      await expect(
        page.getByRole("heading", { level: 1, name: /ERP for Construction/i }),
      ).toBeVisible();
    });
  });
});
