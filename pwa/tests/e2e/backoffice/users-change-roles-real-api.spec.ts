import { request } from "@playwright/test";
import { test, expect, type APIRequestContext } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";
import { apiBaseURL } from "../fixtures/api";

/**
 * Write-side real-API E2E for the identity role re-grant: an ADMIN opens a member's detail, replaces their role
 * set, and the detail badges reflect it. This is the one layer that exercises the wired PWA→API round trip end
 * to end — the gated `<UserRolesControl>` rendering for an ADMIN, the real `PATCH /users/{id}/roles` carrying the
 * complete set, and the badges after the reload. The 409/422/403/401/404 matrix is owned by the Behat suite;
 * this is a single happy-path smoke.
 *
 * Its target is a dedicated non-admin (`e2e-suspendable@erpify.test`, shared with the change-status smoke) — NOT
 * the admin, whose demotion would hit the last-active-admin guard. The `make pwa.test.e2e` seed resets its roles
 * to VIEWER before every run, so the re-grant is repeatable. Roles are editable in any lifecycle status, so this
 * spec is order-independent from the suspend smoke that may already have suspended the same member.
 */
const TARGET_EMAIL = "e2e-suspendable@erpify.test";

// The dev-mode stack compiles each route on its first hit, so the first assertion on a freshly-visited route
// absorbs that cold compile before the default per-assertion budget applies.
const COLD_ROUTE_TIMEOUT_MS = 30_000;

interface UserRow {
  id: string;
  email: string;
  roles: string[];
}

test.describe("BackOffice - Change user roles (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  let api: APIRequestContext;
  let target: UserRow;

  test.beforeAll(async ({ workerStorageState }) => {
    api = await request.newContext({
      baseURL: apiBaseURL(),
      ignoreHTTPSErrors: true,
      storageState: workerStorageState,
    });

    // Resolve the seeded member off the read endpoint (filter by its unique email — the dev DB accumulates
    // identities, so never an exact count).
    const response = await api.get(
      "/api/v1/backoffice/users" +
        `?filters[0][field]=email&filters[0][operator]=contains&filters[0][value]=${encodeURIComponent(
          TARGET_EMAIL,
        )}&sort=email&limit=25`,
    );
    expect(response.ok()).toBe(true);

    const body = (await response.json()) as { data: UserRow[] };
    const found = body.data.find((row) => row.email === TARGET_EMAIL);
    if (!found) {
      throw new Error(
        `Role-editable e2e user ${TARGET_EMAIL} not found in the register — is the make/pwa.mk seed running?`,
      );
    }
    target = found;
  });

  test.afterAll(async () => {
    await api.dispose();
  });

  test("an admin replaces a member's role set and the detail reflects it", async ({ page }) => {
    // Derive the swap from whatever the member currently holds rather than from the seeded value, so a
    // Playwright retry — which re-reads a target this spec has already re-granted — still performs a real
    // change instead of asserting a set that is no longer there.
    const swap = target.roles.includes("VIEWER")
      ? { from: "VIEWER", to: "MANAGER", toLabel: "Manager", fromLabel: "Viewer" }
      : { from: "MANAGER", to: "VIEWER", toLabel: "Viewer", fromLabel: "Manager" };

    await page.goto(`/backoffice/users/${target.id}`);
    await expect(page.getByTestId("users-detail")).toHaveAttribute("data-state", "ready", {
      timeout: COLD_ROUTE_TIMEOUT_MS,
    });

    // The control is gated on users.changeRoles (only ADMIN reaches it) and renders in any lifecycle status.
    const control = page.getByTestId("user-roles");
    await expect(control).toBeVisible();
    await expect(control.getByTestId(`user-roles__role-${swap.from}`)).toBeChecked();

    // The checkboxes carry the complete resulting set, not a delta.
    await control.getByTestId(`user-roles__role-${swap.to}`).check();
    await control.getByTestId(`user-roles__role-${swap.from}`).uncheck();
    await control.getByTestId("user-roles__save").click();

    // reload() re-fetches after the mutation, so the detail badges show the re-granted set.
    await expect(page.getByTestId("users-detail__field-roles")).toContainText(swap.toLabel);
    await expect(page.getByTestId("users-detail__field-roles")).not.toContainText(swap.fromLabel);
    await expect(control.getByTestId(`user-roles__role-${swap.to}`)).toBeChecked();
  });
});
