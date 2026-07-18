import { request } from "@playwright/test";
import { test, expect, type APIRequestContext } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";
import { apiBaseURL } from "../fixtures/api";

/**
 * Write-side real-API E2E for the identity status transition: an ADMIN opens an ACTIVE member's detail and
 * suspends them, and the detail reflects the new status. This is the one layer that exercises the wired
 * PWA→API round trip end to end — the gated `<UserStatusControl>` rendering for an ADMIN on an ACTIVE row, the
 * real `PATCH /users/{id}/status`, and the badge flipping after the reload. The 409/422/403/401/404 matrix is
 * owned by the Behat suite; this is a single happy-path smoke.
 *
 * Its target is a dedicated ACTIVE non-admin (`e2e-suspendable@erpify.test`) — NOT the admin, whose suspension
 * would hit the last-active-admin guard. The `make pwa.test.e2e` seed re-activates it before every run, so the
 * unidirectional lifecycle (no reinstate) never leaves it un-reseedable.
 */
const SUSPENDABLE_EMAIL = "e2e-suspendable@erpify.test";

// The dev-mode stack compiles each route on its first hit, so the first assertion on a freshly-visited route
// absorbs that cold compile before the default per-assertion budget applies.
const COLD_ROUTE_TIMEOUT_MS = 30_000;

interface UserRow {
  id: string;
  email: string;
  status: string;
}

test.describe("BackOffice - Change user status (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  let api: APIRequestContext;
  let target: UserRow;

  test.beforeAll(async ({ workerStorageState }) => {
    api = await request.newContext({
      baseURL: apiBaseURL(),
      ignoreHTTPSErrors: true,
      storageState: workerStorageState,
    });

    // Resolve the seeded suspendable member off the read endpoint (filter by its unique email — the dev DB
    // accumulates identities, so never an exact count).
    const response = await api.get(
      "/api/v1/backoffice/users" +
        `?filters[0][field]=email&filters[0][operator]=contains&filters[0][value]=${encodeURIComponent(
          SUSPENDABLE_EMAIL,
        )}&sort=email&limit=25`,
    );
    expect(response.ok()).toBe(true);

    const body = (await response.json()) as { data: UserRow[] };
    const found = body.data.find((row) => row.email === SUSPENDABLE_EMAIL);
    if (!found) {
      throw new Error(
        `Suspendable e2e user ${SUSPENDABLE_EMAIL} not found in the register — is the make/pwa.mk seed running?`,
      );
    }
    target = found;
  });

  test.afterAll(async () => {
    await api.dispose();
  });

  test("an admin suspends an active member and the detail reflects the new status", async ({
    page,
  }) => {
    // The seed re-activates the target each run, so it starts ACTIVE and the control renders.
    expect(target.status).toBe("ACTIVE");

    await page.goto(`/backoffice/users/${target.id}`);
    await expect(page.getByTestId("users-detail")).toHaveAttribute("data-state", "ready", {
      timeout: COLD_ROUTE_TIMEOUT_MS,
    });

    // The control is gated on users.changeStatus (only ADMIN reaches it) and only shown for an ACTIVE identity.
    const control = page.getByTestId("user-status");
    await expect(control).toBeVisible();

    // The default target is Suspend; apply it.
    await control.getByTestId("user-status__save").click();

    // reload() re-fetches after the mutation, so the detail badge flips to Suspended…
    await expect(page.getByTestId("users-detail__status-badge")).toContainText("Suspended");
    // …and, the lifecycle being unidirectional (no reinstate), the control is gone.
    await expect(control).toHaveCount(0);
  });
});
