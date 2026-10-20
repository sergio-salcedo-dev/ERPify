import { test, expect } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";

/**
 * The outage round trip through the real provider, guard and page: a `/me` that answers 503
 * `service-unavailable` parks a signed-in visitor on `/maintenance` with the blocked page as `next`,
 * and the visitor is returned there once `/me` answers again. The unit suites mock `useSession`,
 * `useRouter` and `useDeparture` separately; only a browser composes the three over a soft
 * navigation, which is how the guard actually reaches `/maintenance`.
 *
 * Only `/me` is intercepted, and only until the recovery step: everything else the back office
 * requests is the live stack, under the worker's real session.
 */
test.describe("BackOffice — identity service outage", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test("parks a signed-in visitor on /maintenance and returns them once /me answers", async ({
    page,
  }) => {
    const ME = "**/api/v1/me";
    await page.route(ME, (route) =>
      route.fulfill({
        status: 503,
        contentType: "application/problem+json",
        body: JSON.stringify({
          type: "service-unavailable",
          title: "Service Unavailable",
          status: 503,
          detail: "The session store is unreachable.",
          instance: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a99",
          "correlation-id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a98",
        }),
      }),
    );

    await page.goto("/backoffice/banks");

    await expect(page).toHaveURL("/maintenance?next=%2Fbackoffice%2Fbanks");
    await expect(page.getByTestId("maintenance__panel")).toBeVisible();

    await page.unroute(ME);
    // The provider also re-probes on its own timer; a visibility change asks for one at once.
    await page.evaluate(() => document.dispatchEvent(new Event("visibilitychange")));

    await expect(page).toHaveURL("/backoffice/banks");
  });
});
