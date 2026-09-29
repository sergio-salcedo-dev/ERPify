import { test, expect, type Page } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";

/**
 * E2E coverage for a `/me` probe the server cannot decide (502/503/504).
 *
 * The session store being down is not "signed out": the guard must send the visitor to
 * `/maintenance` rather than to a sign-in form the same outage would refuse, carry the
 * interrupted route in `?next=`, and the retry there must return to it. Literal paths on
 * purpose — the unit suite pins the constants, this pins what the browser actually shows.
 *
 * Every probe is intercepted, so no session is needed: the context starts anonymous.
 */
test.use({ storageState: { cookies: [], origins: [] }, viewport: VIEWPORT_DESKTOP });

const ME_PATH = "/api/v1/me";

function problem(status: number): string {
  return JSON.stringify({
    type: status === 401 ? "session-expired" : "service-unavailable",
    title: status === 401 ? "Session expired." : "Service unavailable.",
    status,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
  });
}

/** Answers every `/me` probe with whatever status `current()` holds when it arrives. */
async function stubMe(page: Page, current: () => number): Promise<void> {
  await page.route(
    (url) => url.pathname === ME_PATH,
    async (route) => {
      const status = current();
      await route.fulfill({
        status,
        contentType: "application/problem+json",
        headers: { "Cache-Control": "no-store" },
        body: problem(status),
      });
    },
  );
}

test.describe("Session unavailable — /me cannot decide", () => {
  test("a guarded route sends the visitor to /maintenance, carrying the route in ?next=", async ({
    page,
  }) => {
    await stubMe(page, () => 503);

    await page.goto("/backoffice/banks");

    await expect(page).toHaveURL(/\/maintenance\?next=%2Fbackoffice%2Fbanks$/);
    await expect(page.getByTestId("maintenance__panel")).toBeVisible();
    await expect(page.getByTestId("maintenance__title")).toHaveText("Service unavailable");
    await expect(page).not.toHaveURL(/\/login/);
  });

  test("a gateway's 504 is treated like the session store's 503", async ({ page }) => {
    await stubMe(page, () => 504);

    await page.goto("/backoffice");

    await expect(page).toHaveURL(/\/maintenance\?next=%2Fbackoffice$/);
    await expect(page.getByTestId("maintenance__panel")).toBeVisible();
  });

  test("Try again returns to the interrupted route, which probes afresh", async ({ page }) => {
    let status = 503;
    await stubMe(page, () => status);
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("maintenance__retry-button")).toBeVisible();

    // The outage is over and the visitor turns out to be signed out: the retry reaches the
    // guarded route again, whose fresh probe now decides and sends them to sign in there.
    status = 401;
    await page.getByTestId("maintenance__retry-button").click();

    await expect(page).toHaveURL(/\/login\?next=%2Fbackoffice%2Fbanks$/);
  });

  test("the landing navbar offers neither Sign in nor the Backoffice entry", async ({ page }) => {
    await stubMe(page, () => 503);

    await page.goto("/");

    await expect(page.locator("nav[data-session-status]")).toHaveAttribute(
      "data-session-status",
      "unavailable",
    );
    await expect(page.getByTestId("navbar__link-login")).toHaveCount(0);
    await expect(page.getByTestId("navbar__go-to-backoffice-button")).toHaveCount(0);
  });
});
