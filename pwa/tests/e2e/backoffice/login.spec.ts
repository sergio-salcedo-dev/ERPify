import { test, expect } from "@playwright/test";

import { E2E_USER_EMAIL, E2E_USER_PASSWORD } from "../constants";

/**
 * The sign-in form driven against the real API (de-mocked in II-3): a real POST to
 * `/api/v1/backoffice/login`, then the client routes the RFC 9457 outcome. A fresh, unauthenticated
 * context so the form is reachable without the setup session.
 *
 * The suspended / deactivated access walls are NOT driven here: putting a user into SUSPENDED /
 * DEACTIVATED needs the member-management trigger deferred to the follow-up slice, so no live tooling can
 * create one. Those walls are covered by the API Behat admission suite and the PWA unit tests
 * (loginForm / AccessWall / ApiLoginRepository).
 */
test.use({ storageState: { cookies: [], origins: [] } });

test.describe("Backoffice login (real API)", () => {
  test("wrong credentials surface a single neutral error and stay on the login page", async ({
    page,
  }) => {
    await page.goto("/login");

    await page.getByTestId("login-form__email").fill(E2E_USER_EMAIL);
    await page.getByTestId("login-form__password").fill("definitely-not-the-password");
    await page.getByTestId("login-form__submit").click();

    await expect(page.getByTestId("login-form__error")).toHaveText("Invalid email or password.");
    await expect(page).toHaveURL(/\/login/);
  });

  test("valid credentials establish a session and land on the back office", async ({ page }) => {
    await page.goto("/login");

    await page.getByTestId("login-form__email").fill(E2E_USER_EMAIL);
    await page.getByTestId("login-form__password").fill(E2E_USER_PASSWORD);
    await page.getByTestId("login-form__submit").click();

    await expect(page).toHaveURL(/\/backoffice/);
  });
});
